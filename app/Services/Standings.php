<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Song;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * In-memory snapshot of all timelines, scores and ranks of a game.
 *
 * Loads everything with a handful of queries so that results for hundreds of
 * players can be computed without per-player queries. Timelines are never
 * stored; they are derived from the start card plus all correct placements.
 */
class Standings
{
    /**
     * @var Collection<int, Player>
     */
    private Collection $players;

    /**
     * Cards per player: start card plus correct placements, in insertion order.
     *
     * @var array<int, list<array{id: int, title: string, artist: string, year: int, image: array{sm: string, lg: string}|null, round: int}>>
     */
    private array $cards = [];

    /**
     * @var array<int, int>
     */
    private array $bonusPoints = [];

    /**
     * @var array<int, array{guessed_year: int, submitted_at: string}>
     */
    private array $tiebreaks = [];

    private ?int $tiebreakYear;

    /**
     * Sort keys used for ranking, cached per mode.
     *
     * @var array<string, array<int, array{0: int, 1: int, 2: string}>>
     */
    private array $rankKeys = [];

    public function __construct(private Game $game)
    {
        $this->players = $game->players()->with('startSong')->get()->keyBy('id');

        foreach ($this->players as $player) {
            $this->cards[$player->id] = [[...$player->startSong->toCard(), 'round' => 0]];
        }

        $correct = DB::table('placements')
            ->join('rounds', 'rounds.id', '=', 'placements.round_id')
            ->join('songs', 'songs.id', '=', 'rounds.song_id')
            ->where('rounds.game_id', $game->id)
            ->where('placements.is_correct', true)
            ->orderBy('rounds.number')
            ->get(['placements.player_id', 'rounds.number', 'songs.id']);

        $songs = Song::query()->whereIn('id', $correct->pluck('id')->unique())->get()->keyBy('id');

        foreach ($correct as $row) {
            $song = $songs->get($row->id);

            if ($song === null) {
                continue;
            }

            $this->cards[$row->player_id][] = [...$song->toCard(), 'round' => (int) $row->number];
        }

        $this->bonusPoints = DB::table('bonus_answers')
            ->join('rounds', 'rounds.id', '=', 'bonus_answers.round_id')
            ->where('rounds.game_id', $game->id)
            ->where('bonus_answers.is_correct', true)
            ->groupBy('bonus_answers.player_id')
            ->pluck(DB::raw('count(*)'), 'bonus_answers.player_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        foreach ($game->tiebreaks()->get() as $tiebreak) {
            $this->tiebreaks[$tiebreak->player_id] = [
                'guessed_year' => $tiebreak->guessed_year,
                'submitted_at' => $tiebreak->submitted_at->format('Y-m-d H:i:s.u'),
            ];
        }

        $this->tiebreakYear = $game->tiebreakerSong()?->year;
    }

    public function playerCount(): int
    {
        return $this->players->count();
    }

    /**
     * Timeline sorted by year (old first). With $beforeRound, only cards won
     * in earlier rounds are included, i.e. the timeline a placement refers to.
     *
     * @return list<array{id: int, title: string, artist: string, year: int, image: array{sm: string, lg: string}|null}>
     */
    public function timeline(int $playerId, ?int $beforeRound = null): array
    {
        $cards = array_filter(
            $this->cards[$playerId] ?? [],
            fn (array $card) => $beforeRound === null || $card['round'] < $beforeRound,
        );

        usort($cards, fn (array $a, array $b) => [$a['year'], $a['round']] <=> [$b['year'], $b['round']]);

        return array_map(function (array $card) {
            unset($card['round']);

            return $card;
        }, $cards);
    }

    /**
     * @return list<int>
     */
    public function years(int $playerId, ?int $beforeRound = null): array
    {
        return array_column($this->timeline($playerId, $beforeRound), 'year');
    }

    /**
     * Points = number of cards in the timeline, including the start card.
     */
    public function score(int $playerId): int
    {
        return count($this->cards[$playerId] ?? []);
    }

    public function bonusPoints(int $playerId): int
    {
        return $this->bonusPoints[$playerId] ?? 0;
    }

    /**
     * Competition rank (1, 2, 2, 4 ...). During the game only the score
     * counts; with $final the tiebreak deviation and then the earlier
     * submission decide between equal scores.
     */
    public function rank(int $playerId, bool $final = false): int
    {
        $keys = $this->rankKeys($final);
        $own = $keys[$playerId] ?? null;

        if ($own === null) {
            return $this->players->count() + 1;
        }

        $better = 0;
        foreach ($keys as $key) {
            if ($key < $own) {
                $better++;
            }
        }

        return $better + 1;
    }

    /**
     * @return list<array{player_id: int, name: string, score: int, bonus: int, rank: int, tiebreak_deviation: int|null}>
     */
    public function top(int $limit = 10, bool $final = false): array
    {
        $keys = $this->rankKeys($final);
        asort($keys);

        $rows = [];
        foreach (array_slice($keys, 0, $limit, true) as $playerId => $key) {
            $rows[] = $this->row($playerId, $final);
        }

        return $rows;
    }

    /**
     * @return array{player_id: int, name: string, score: int, bonus: int, rank: int, tiebreak_deviation: int|null}
     */
    public function row(int $playerId, bool $final = false): array
    {
        /** @var Player $player */
        $player = $this->players->get($playerId);

        return [
            'player_id' => $playerId,
            'name' => $player->displayName(),
            'score' => $this->score($playerId),
            'bonus' => $this->bonusPoints($playerId),
            'rank' => $this->rank($playerId, $final),
            'tiebreak_deviation' => $final ? $this->tiebreakDeviation($playerId) : null,
        ];
    }

    public function tiebreakDeviation(int $playerId): ?int
    {
        if ($this->tiebreakYear === null || ! isset($this->tiebreaks[$playerId])) {
            return null;
        }

        return abs($this->tiebreaks[$playerId]['guessed_year'] - $this->tiebreakYear);
    }

    /**
     * Whether players with equal scores share a place among the top three.
     */
    public function hasTieInTopThree(): bool
    {
        $scores = array_column($this->top(PHP_INT_MAX), 'score', 'player_id');
        $podiumScores = array_slice(array_values($scores), 0, 3);

        foreach (array_unique($podiumScores) as $score) {
            if (count(array_keys($scores, $score, true)) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort keys: lower is better. Equal keys share a rank.
     *
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private function rankKeys(bool $final): array
    {
        $mode = $final ? 'final' : 'live';

        if (! isset($this->rankKeys[$mode])) {
            $keys = [];
            foreach ($this->players as $player) {
                $keys[$player->id] = [
                    -$this->score($player->id),
                    $final ? ($this->tiebreakDeviation($player->id) ?? PHP_INT_MAX) : 0,
                    $final ? ($this->tiebreaks[$player->id]['submitted_at'] ?? '9999') : '',
                ];
            }
            $this->rankKeys[$mode] = $keys;
        }

        return $this->rankKeys[$mode];
    }
}
