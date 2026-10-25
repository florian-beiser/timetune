<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Game management for logged-in organisers (starter kit login).
 */
class GameController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'games' => Game::query()->latest()->get()->map(fn (Game $game) => [
                'id' => $game->id,
                'code' => $game->code,
                'name' => $game->name,
                'state' => $game->state->value,
                'players_count' => $game->players()->count(),
                'created_at' => $game->created_at?->toIso8601String(),
                'host_url' => route('host.show', ['game' => $game->id, 'key' => $game->admin_key]),
                'screen_url' => route('screen.show', $game->id),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $game = Game::query()->create([
            'code' => Game::generateCode(),
            'name' => $validated['name'],
            'admin_key' => Game::generateAdminKey(),
            'options' => ['bonus_question' => false, 'countdown_seconds' => null],
        ]);

        return redirect()->route('host.show', ['game' => $game->id, 'key' => $game->admin_key]);
    }
}
