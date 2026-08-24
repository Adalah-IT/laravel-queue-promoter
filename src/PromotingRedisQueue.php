<?php

namespace AbdulmajeedJamaan\QueuePromoter;

use Illuminate\Queue\RedisQueue;

/**
 * A Redis queue whose pop() promotes but never reserves. pop() already runs
 * migrate() (promoting due delayed and expired reserved jobs) before reserving;
 * reserving nothing makes pop() return null, so a stock Worker just keeps looping.
 */
class PromotingRedisQueue extends RedisQueue
{
    /** Real connection name to report, so pause flags and events resolve correctly. */
    private ?string $reportConnectionName = null;

    public function promotingFor(?string $connectionName): static
    {
        $this->reportConnectionName = $connectionName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getConnectionName()
    {
        return $this->reportConnectionName ?? parent::getConnectionName();
    }

    /**
     * Reserve nothing — pop()'s migrate() call already did the promotion.
     *
     * @param  string  $queue
     * @param  bool  $block
     * @return array{0: null, 1: null}
     */
    protected function retrieveNextJob($queue, $block = true)
    {
        return [null, null];
    }
}
