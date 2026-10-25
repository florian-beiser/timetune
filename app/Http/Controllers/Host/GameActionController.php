<?php

namespace App\Http\Controllers\Host;

use App\Enums\GameState;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameFlow;
use App\Services\JoinUrl;
use App\Services\PlayerRegistrar;
use Closure;
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

    public function reopen(Game $game): JsonResponse
    {
        return response()->json($this->flow->publicState($this->flow->reopenRound($game)));
    }

    public function skip(Game $game): JsonResponse
    {
        return response()->json($this->flow->publicState($this->flow->skipRound($game)));
    }

    public function reset(Game $game): JsonResponse
    {
        return response()->json($this->flow->publicState($this->flow->reset($game)));
    }

    public function update(Request $request, Game $game, JoinUrl $joinUrl): JsonResponse
    {
        $validated = $request->validate([
            'options.join_url' => ['sometimes', 'nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($joinUrl): void {
                if (is_string($value) && $joinUrl->normalize($value) === null) {
                    $fail('Bitte eine gültige Adresse angeben, z. B. hitster.musikverein.at oder https://bit.ly/abc.');
                }
            }],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'options.bonus_question' => ['sometimes', 'boolean'],
            'options.countdown_seconds' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:600'],
            'options.title' => ['sometimes', 'nullable', 'string', 'max:'.Game::MAX_TITLE_LENGTH],
            'options.theme' => ['sometimes', 'nullable', 'array:accent,background'],
            'options.theme.accent' => ['required_with:options.theme', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'options.theme.background' => ['required_with:options.theme', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'options.theme.*.regex' => 'Bitte eine Farbe im Format #RRGGBB angeben.',
            'options.title.max' => 'Der Titel darf höchstens :max Zeichen lang sein.',
        ]);

        if (array_key_exists('title', $validated['options'] ?? [])) {
            $title = trim((string) $validated['options']['title']);
            $validated['options']['title'] = $title === '' ? null : $title;
        }

        if (array_key_exists('join_url', $validated['options'] ?? [])) {
            $validated['options']['join_url'] = is_string($validated['options']['join_url'])
                ? $joinUrl->normalize($validated['options']['join_url'])
                : null;
        }

        $game->fill(array_filter(['name' => $validated['name'] ?? null]));
        $game->options = [...($game->options ?? []), ...($validated['options'] ?? [])];
        $game->save();

        return response()->json(['name' => $game->name, 'options' => $game->options]);
    }

    public function renamePlayer(Request $request, Game $game, int $player, PlayerRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.PlayerRegistrar::MAX_NAME_LENGTH],
        ], [
            'name.required' => 'Bitte einen Namen angeben.',
            'name.max' => 'Höchstens :max Zeichen.',
        ]);

        $model = $registrar->rename($game->players()->findOrFail($player), $validated['name'], byModerator: true);

        return response()->json(['id' => $model->id, 'name' => $model->name]);
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
