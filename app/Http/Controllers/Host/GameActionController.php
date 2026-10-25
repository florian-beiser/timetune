<?php

namespace App\Http\Controllers\Host;

use App\Enums\GameState;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameFlow;
use App\Services\PlayerRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class GameActionController extends Controller
{
    public function __construct(private GameFlow $flow) {}

    /**
     * Trigger the next transition. `from` is the state the moderator saw,
     * `to` optionally picks among several possible targets.
     */
    public function advance(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', Rule::enum(GameState::class)],
            'to' => ['nullable', Rule::enum(GameState::class)],
        ]);

        $game = $this->flow->advance(
            $game,
            GameState::from($validated['from']),
            isset($validated['to']) ? GameState::from($validated['to']) : null,
        );

        return response()->json($this->flow->publicState($game));
    }

    public function skip(Game $game): JsonResponse
    {
        return response()->json($this->flow->publicState($this->flow->skipRound($game)));
    }

    public function reset(Game $game): JsonResponse
    {
        return response()->json($this->flow->publicState($this->flow->reset($game)));
    }

    public function update(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'options.bonus_question' => ['sometimes', 'boolean'],
            'options.countdown_seconds' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:600'],
            'options.theme' => ['sometimes', 'nullable', 'array:accent,background'],
            'options.theme.accent' => ['required_with:options.theme', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'options.theme.background' => ['required_with:options.theme', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'options.theme.*.regex' => 'Bitte eine Farbe im Format #RRGGBB angeben.',
        ]);

        $game->fill(array_filter(['name' => $validated['name'] ?? null]));
        $game->options = [...($game->options ?? []), ...($validated['options'] ?? [])];
        $game->save();

        return response()->json(['name' => $game->name, 'options' => $game->options]);
    }

    public function removePlayer(Game $game, int $player, PlayerRegistrar $registrar): Response
    {
        $registrar->remove($game->players()->findOrFail($player));

        return response()->noContent();
    }

    public function removeInactivePlayers(Game $game, PlayerRegistrar $registrar): JsonResponse
    {
        return response()->json(['removed' => $registrar->removeInactive($game)]);
    }

    public function togglePlayer(Game $game, int $player): JsonResponse
    {
        $model = $game->players()->findOrFail($player);
        $model->update(['is_hidden' => ! $model->is_hidden]);

        return response()->json(['id' => $model->id, 'is_hidden' => $model->is_hidden]);
    }
}
