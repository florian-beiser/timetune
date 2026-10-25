<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the phone of a removed player to go back to the join screen.
 */
class PlayerRemoved implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $playerId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('player.'.$this->playerId)];
    }

    public function broadcastAs(): string
    {
        return 'PlayerRemoved';
    }

    /**
     * @return array{message: string}
     */
    public function broadcastWith(): array
    {
        return ['message' => 'Du wurdest vom Moderator aus dem Spiel entfernt.'];
    }
}
