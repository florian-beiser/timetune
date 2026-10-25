<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the phone that the moderator changed the player's name.
 */
class PlayerRenamed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $playerId, public string $name) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('player.'.$this->playerId)];
    }

    public function broadcastAs(): string
    {
        return 'PlayerRenamed';
    }

    /**
     * @return array{name: string}
     */
    public function broadcastWith(): array
    {
        return ['name' => $this->name];
    }
}
