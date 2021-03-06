<?php declare(strict_types = 1);

namespace Room11\Jeeves\Plugins;

use Amp\Success;
use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\StackChat\Client\Client as ChatClient;
use Room11\StackChat\Client\MessageFetchFailureException;
use Room11\StackChat\Entities\PostedMessage;
use Room11\StackChat\Event\StarMessage as StarMessageEvent;
use Room11\StackChat\Room\AclDataAccessor;
use Room11\StackChat\Room\Room as ChatRoom;

class RePinner extends BasePlugin
{
    private $chatClient;
    private $aclDataAccessor;
    private $keyValueStore;

    public function __construct(ChatClient $chatClient, AclDataAccessor $aclDataAccessor, KeyValueStore $keyValueStore)
    {
        $this->chatClient = $chatClient;
        $this->aclDataAccessor = $aclDataAccessor;
        $this->keyValueStore = $keyValueStore;
    }

    public function starMessageEventHandler(StarMessageEvent $event)
    {
        $key = (string)$event->getMessageId();

        if (!yield $this->keyValueStore->exists($key, $event->getRoom())) {
            return;
        }

        if ($event->isPinned()) {
            return;
        }

        $message = yield $this->keyValueStore->get($key, $event->getRoom());
        yield $this->keyValueStore->unset($key, $event->getRoom());

        /** @var PostedMessage $posted */
        $posted = yield $this->chatClient->postMessage($event->getRoom(), $message);

        yield $this->keyValueStore->set((string)$posted->getId(), $message, $event->getRoom());

        yield $this->chatClient->pinOrUnpinMessage($posted, $event->getRoom());
    }

    public function repin(Command $command)
    {
        $owners = yield $this->aclDataAccessor->getRoomOwners($command->getRoom());

        if (!isset($owners[$command->getUserId()])) {
            return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
        }

        if (!$command->hasParameters(1)) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to repin");
        }

        if (!preg_match('#(?:messages?/)?([0-9]+)#', $command->getParameter(0), $match)) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to repin");
        }
        $id = (int)$match[1];

        try {
            $message = yield $this->chatClient->getMessageText($command->getRoom(), $id);
        } catch (MessageFetchFailureException $e) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to repin");
        }

        if (!in_array($id, yield $this->chatClient->getPinnedMessages($command->getRoom()))) {
            /** @var PostedMessage $posted */
            $posted = yield $this->chatClient->postMessage($command, $message);
            $id = $posted->getId();
            yield $this->chatClient->pinOrUnpinMessage($id, $command->getRoom());
        }

        yield $this->keyValueStore->set((string)$id, $message, $command->getRoom());

        return $this->chatClient->postMessage(
            $command, "I will keep repinning message #{$id} until someone tells me to stop"
        );
    }

    public function unpin(Command $command)
    {
        $owners = yield $this->aclDataAccessor->getRoomOwners($command->getRoom());

        if (!isset($owners[$command->getUserId()])) {
            return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
        }

        if (!$command->hasParameters(1)) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to unpin");
        }

        if (!preg_match('#(?:messages?/)?([0-9]+)#', $command->getParameter(0), $match)) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to unpin");
        }

        $key = $match[1];
        $id = (int)$key;

        if (!yield $this->keyValueStore->exists($key, $command->getRoom())) {
            return $this->chatClient->postReply($command, "You must supply a valid message ID to unpin");
        }

        yield $this->keyValueStore->unset($key, $command->getRoom());

        if (in_array($id, yield $this->chatClient->getPinnedMessages($command->getRoom()))) {
            yield $this->chatClient->pinOrUnpinMessage($id, $command->getRoom());
        }

        return $this->chatClient->postMessage($command, "I will no longer repin message #{$id}");
    }

    public function getDescription(): string
    {
        return 'Re-pins chat messages';
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [
            new PluginCommandEndpoint('RePin', [$this, 'repin'], 'repin', 'Repin a chat message indefinitely'),
            new PluginCommandEndpoint('UnPin', [$this, 'unpin'], 'unpin', 'Cancel a repinned chat message'),
        ];
    }

    /**
     * @return callable[] An array of callbacks with filter strings as keys
     */
    public function getEventHandlers(): array
    {
        return [
            'type=' . StarMessageEvent::TYPE_ID => [$this, 'starMessageEventHandler'],
        ];
    }

    public function enableForRoom(ChatRoom $room, bool $persist) /* : void */
    {
        $pins = yield $this->chatClient->getPinnedMessages($room);
        $repins = yield $this->keyValueStore->getAll($room);

        foreach ($repins as $key => $message) {
            if (in_array($key, $pins)) {
                continue;
            }

            $key = (string)$key;

            yield $this->keyValueStore->unset($key, $room);

            /** @var PostedMessage $posted */
            $posted = yield $this->chatClient->postMessage($room, $message);
            $id = $posted->getId();

            yield $this->chatClient->pinOrUnpinMessage($id, $room);

            yield $this->keyValueStore->set((string)$id, $message, $room);
        }
    }

    public function disableForRoom(ChatRoom $room, bool $persist) /* : void */
    {
        return $persist
            ? $this->keyValueStore->clear($room)
            : new Success();
    }
}
