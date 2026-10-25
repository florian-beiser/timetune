<?php

namespace App\Http\Controllers;

use App\Enums\GameState;
use App\Models\Game;
use App\Models\Player;
use App\Services\PlayerView;
use App\Services\Standings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayController extends Controller
{
    public function find(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);

        return to_route('play.show', strtoupper($validated['code']));
    }

    public function show(Request $request, string $code, PlayerView $view): Response
    {
        $game = Game::query()->where('code', strtoupper($code))->firstOrFail();

        /** @var Player|null $player */
        $player = $request->user('player');
        $me = $player?->game_id === $game->id ? $view->me($player) : null;

        return Inertia::render('play/Show', [
            'game' => ['id' => $game->id, 'code' => $game->code, 'name' => $game->name],
            'branding' => $game->branding(),
            'me' => $me,
            'finishedTop10' => $me === null && $game->state === GameState::Finished
                ? (new Standings($game))->top(10, final: true)
                : null,
        ]);
    }
}
