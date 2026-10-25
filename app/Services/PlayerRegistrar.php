<?php

namespace App\Services;

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Events\PlayerRemoved;
use App\Exceptions\GameFlowException;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlayerRegistrar
{
    public const MAX_NAME_LENGTH = 20;

    public function __construct(private GameFlow $flow) {}

    public function join(Game $game, string $name): Player
    {
        $name = $this->normalizeName($name);

        if ($this->isOffensive($name)) {
            throw ValidationException::withMessages(['name' => 'Bitte wähle einen anderen Namen.']);
        }

        $player = DB::transaction(function () use ($game, $name): Player {
            $game = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($game->state === GameState::Finished) {
                throw new GameFlowException('Spiel bereits beendet.');
            }

            return $game->players()->create([
                'name' => $this->uniqueName($game, $name),
                'session_token' => Str::random(48),
                'start_song_id' => $this->nextStartSongId($game),
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);
        });

        $this->flow->queueSubmissionsCount($game);

        return $player;
    }

    /**
     * Removes a player with all placements and answers. The phone is told
     * first, while its private channel is still authorised.
     */
    public function remove(Player $player): void
    {
        event(new PlayerRemoved($player->id));
        $player->delete();
        $this->flow->queueSubmissionsCount($player->game);
    }

    /**
     * Removes all players without a heartbeat for the configured time.
     */
    public function removeInactive(Game $game): int
    {
        $players = $game->players()
            ->where(fn ($query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subSeconds(config()->integer('timetune.inactive_after_seconds'))))
            ->get();

        foreach ($players as $player) {
            event(new PlayerRemoved($player->id));
        }

        $game->players()->whereKey($players->modelKeys())->delete();
        $this->flow->queueSubmissionsCount($game);

        return $players->count();
    }

    public function normalizeName(string $name): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $name)), self::MAX_NAME_LENGTH, '');
    }

    public function isOffensive(string $name): bool
    {
        $normalized = mb_strtolower($name);

        foreach (config()->array('timetune.name_filter') as $word) {
            if (str_contains($normalized, mb_strtolower((string) $word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Equal names are numbered: "Anna", "Anna 2", "Anna 3" ...
     */
    private function uniqueName(Game $game, string $name): string
    {
        $lower = mb_strtolower($name);
        $taken = $game->players()
            ->where(fn ($query) => $query
                ->whereRaw('lower(name) = ?', [$lower])
                ->orWhereRaw('substr(lower(name), 1, ?) = ?', [mb_strlen($lower) + 1, $lower.' ']))
            ->pluck('name')
            ->map(fn (string $existing) => mb_strtolower($existing))
            ->all();

        $candidate = $name;
        for ($suffix = 2; in_array(mb_strtolower($candidate), $taken, true); $suffix++) {
            $candidate = $name.' '.$suffix;
        }

        return $candidate;
    }

    /**
     * Rotates through the start pool: the least used card is handed out next,
     * so timelines differ and a small pool is reused evenly.
     */
    private function nextStartSongId(Game $game): int
    {
        $pool = $game->songs()->where('role', SongRole::StartPool)->pluck('id');

        if ($pool->isEmpty()) {
            throw new GameFlowException('Das Spiel ist noch nicht bereit.');
        }

        $usage = $game->players()
            ->selectRaw('start_song_id, count(*) as uses')
            ->groupBy('start_song_id')
            ->pluck('uses', 'start_song_id');

        $leastUsed = $pool->min(fn (int $id) => (int) ($usage[$id] ?? 0));

        return $pool->filter(fn (int $id) => (int) ($usage[$id] ?? 0) === $leastUsed)->random();
    }
}
