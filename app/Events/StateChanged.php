<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Public state change. Never contains the year, title or artist of an open song.
 */
class StateChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(public int $gameId, public array $state) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('game.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return 'StateChanged';
    }

    /**
     * `sent_at` (ms) lets load tests measure the delivery latency.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [...$this->state, 'sent_at' => (int) floor(microtime(true) * 1000)];
    }
}
