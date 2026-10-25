<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class LeaderboardUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $top10
     */
    public function __construct(public int $gameId, public array $top10, public int $totalPlayers) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('game.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return 'LeaderboardUpdated';
    }

    /**
     * @return array{top10: list<array<string, mixed>>, total_players: int}
     */
    public function broadcastWith(): array
    {
        return ['top10' => $this->top10, 'total_players' => $this->totalPlayers];
    }
}
