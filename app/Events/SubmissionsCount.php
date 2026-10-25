<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SubmissionsCount implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $gameId, public int $submitted, public int $totalPlayers) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('game.'.$this->gameId),
            new PrivateChannel('game.'.$this->gameId.'.host'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SubmissionsCount';
    }

    /**
     * @return array{submitted: int, total_players: int}
     */
    public function broadcastWith(): array
    {
        return ['submitted' => $this->submitted, 'total_players' => $this->totalPlayers];
    }
}
