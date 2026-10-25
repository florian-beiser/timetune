<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameState;
use App\Exceptions\GameFlowException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Services\GameFlow;
use App\Services\PlayerRegistrar;
use App\Services\PlayerView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * Player actions. The player is identified by the session_token cookie.
 */
class PlayerController extends Controller
{
    public function __construct(private PlayerView $view, private GameFlow $flow) {}

    public function join(Request $request, string $code, PlayerRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.PlayerRegistrar::MAX_NAME_LENGTH],
        ], [
            'name.required' => 'Bitte gib deinen Namen ein.',
            'name.max' => 'Höchstens :max Zeichen.',
        ]);

        $game = Game::query()->where('code', strtoupper($code))->firstOrFail();
        $player = $registrar->join($game, $validated['name']);

        $minutes = (config()->integer('timetune.retention_days') + 1) * 24 * 60;

        return response()
            ->json([
                'player_id' => $player->id,
                'session_token' => $player->session_token,
                'start_card' => $player->startSong->toCard(),
                'me' => $this->view->me($player),
            ], 201)
            ->withCookie(Cookie::make(Player::COOKIE, $player->session_token, $minutes));
    }

    public function me(Request $request): JsonResponse
    {
        $player = $this->player($request);

        if ($player->last_seen_at === null || $player->last_seen_at->lt(now()->subSeconds(30))) {
            $player->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->json($this->view->me($player));
    }

    public function rename(Request $request, PlayerRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.PlayerRegistrar::MAX_NAME_LENGTH],
        ], [
            'name.required' => 'Bitte gib deinen Namen ein.',
            'name.max' => 'Höchstens :max Zeichen.',
        ]);

        $player = $registrar->rename($this->player($request), $validated['name']);

        return response()->json(['name' => $player->name]);
    }

    /**
     * Sent every 45 s while the page is visible; marks the player as active.
     */
    public function heartbeat(Request $request): Response
    {
        $player = $this->player($request);

        if ($player->last_seen_at === null || $player->last_seen_at->lt(now()->subSeconds(20))) {
            $player->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->noContent();
    }

    /**
     * Set or change the gap for the current round. Several tabs or devices
     * with the same token overwrite the same placement.
     */
    public function place(Request $request, Round $round): JsonResponse
    {
        $player = $this->player($request);
        $this->ensureRoundOpen($player, $round);

        $timelineSize = 1 + $player->placements()->where('is_correct', true)->count();
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:0', 'max:'.$timelineSize],
        ]);

        DB::table('placements')->upsert(
            [[
                'round_id' => $round->id,
                'player_id' => $player->id,
                'position' => $validated['position'],
                'submitted_at' => now(),
            ]],
            ['round_id', 'player_id'],
            ['position', 'submitted_at'],
        );

        $this->flow->queueSubmissionsCount($round->game);

        return response()->json(['position' => $validated['position']]);
    }

    public function answerBonus(Request $request, Round $round): JsonResponse
    {
        $player = $this->player($request);
        $this->ensureRoundOpen($player, $round);

        if ($round->bonus_options === null) {
            throw new GameFlowException('Für dieses Stück gibt es keine Bonusfrage.');
        }

        $validated = $request->validate([
            'choice' => ['required', 'integer', 'min:0', 'max:'.(count($round->bonus_options) - 1)],
        ]);

        $round->bonusAnswers()->updateOrCreate(['player_id' => $player->id], ['choice' => $validated['choice']]);

        return response()->json(['choice' => $validated['choice']]);
    }

    public function tiebreak(Request $request, Game $game): JsonResponse
    {
        $player = $this->player($request);
        abort_unless($player->game_id === $game->id, 403);

        if ($game->state !== GameState::Tiebreaker) {
            throw new GameFlowException('Zu spät – der Tiebreaker ist vorbei.');
        }

        $validated = $request->validate([
            'guessed_year' => ['required', 'integer', 'digits:4'],
        ], [
            'guessed_year.digits' => 'Bitte gib ein vierstelliges Jahr ein.',
        ]);

        if ($player->tiebreak()->exists()) {
            throw new GameFlowException('Du hast bereits abgegeben.');
        }

        $player->tiebreak()->create([
            'game_id' => $game->id,
            'guessed_year' => $validated['guessed_year'],
            'submitted_at' => now(),
        ]);

        return response()->json(['guessed_year' => $validated['guessed_year']], 201);
    }

    private function player(Request $request): Player
    {
        $player = $request->user('player');
        abort_unless($player instanceof Player, 401, 'Bitte tritt dem Spiel zuerst bei.');

        return $player;
    }

    private function ensureRoundOpen(Player $player, Round $round): void
    {
        abort_unless($player->game_id === $round->game_id, 403);

        $game = $round->game;

        if ($game->state !== GameState::RoundOpen || $game->current_round !== $round->number || ! $round->isOpen()) {
            throw new GameFlowException('Zu spät – die Abgabe ist geschlossen.');
        }
    }
}
