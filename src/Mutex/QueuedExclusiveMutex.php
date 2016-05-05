<?php declare(strict_types = 1);

namespace Room11\Jeeves\Mutex;

use Amp\Deferred;

class QueuedExclusiveMutex extends Mutex
{
    /**
     * @var Deferred
     */
    private $last;

    public function getLock(): \Generator
    {
        $deferred = new Deferred();
        $last = $this->last;

        $this->last = $deferred;

        if ($last !== null) {
            yield $last->promise();
        }

        return new class($deferred) implements Lock
        {
            private $deferred;

            private $released;

            public function __construct(Deferred $deferred)
            {
                $this->deferred = $deferred;
            }

            public function __destruct()
            {
                if (!$this->released) {
                    $this->release();
                }
            }

            public function release()
            {
                $this->deferred->succeed();
                $this->deferred = null; // remove our ref in case someone keeps their lock ref'd after they release
                $this->released = true;
            }
        };
    }
}
