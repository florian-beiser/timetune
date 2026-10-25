<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameState;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Standings;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    public function __invoke(Game $game): JsonResponse
    {
        $standings = new Standings($game);

        return response()->json([
            'top10' => $standings->top(10, final: $game->state === GameState::Finished),
            'total_players' => $standings->playerCount(),
        ]);
    }
}
