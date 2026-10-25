<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class GameFinished implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $podium
     * @param  list<array<string, mixed>>  $top10
     */
    public function __construct(public int $gameId, public array $podium, public array $top10) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('game.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return 'GameFinished';
    }

    /**
     * @return array{podium: list<array<string, mixed>>, top10: list<array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return ['podium' => $this->podium, 'top10' => $this->top10];
    }
}
