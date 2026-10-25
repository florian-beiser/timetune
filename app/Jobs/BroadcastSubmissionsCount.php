<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BroadcastSubmissionsCount implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $gameId) {}

    public function handle(GameFlow $flow): void
    {
        $game = Game::query()->find($this->gameId);

        if ($game !== null) {
            $flow->broadcastSubmissionsCount($game);
        }
    }
}
