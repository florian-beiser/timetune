<?php

namespace App\Services;

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Events\GameFinished;
use App\Events\LeaderboardUpdated;
use App\Events\RoundRevealed;
use App\Events\StateChanged;
use App\Events\SubmissionsCount;
use App\Exceptions\GameFlowException;
use App\Jobs\BroadcastSubmissionsCount;
use App\Jobs\CloseRoundAfterCountdown;
use App\Jobs\SendPlayerResults;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Song;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Server-side state machine of a game. Only the moderator triggers
 * transitions; every transition is broadcast after the transaction commits.
 */
class GameFlow
{
    /**
     * Players per queued PlayerResult batch.
     */
    public const RESULT_BATCH_SIZE = 100;

    public function __construct(private TimelineEvaluator $evaluator) {}

    /**
     * Trigger the next transition. $from guards against double clicks: if the
     * game is no longer in that state, nothing happens.
     */
    public function advance(Game $game, GameState $from, ?GameState $target = null): Game
    {
        $game = $this->transaction($game, function (Game $game) use ($from, $target): void {
            if ($game->state !== $from) {
                throw new GameFlowException('Der Spielzustand hat sich inzwischen geändert.');
            }

            $target ??= $this->naturalNextState($game);

            if (! $game->state->canTransitionTo($target)) {
                throw new GameFlowException('Dieser Übergang ist nicht erlaubt.');
            }

            match ($target) {
                GameState::RoundOpen => $this->openNextRound($game),
                GameState::RoundClosed => $this->closeRound($game),
                GameState::Revealed => $this->revealRound($game),
                GameState::Tiebreaker => $this->startTiebreaker($game),
                GameState::Finished => $this->finish($game),
                GameState::Lobby => throw new GameFlowException('Dieser Übergang ist nicht erlaubt.'),
            };
        });

        $this->broadcastTransition($game);

        return $game;
    }

    /**
     * The round is not scored for anyone; the game continues with the next
     * round or, after the last one, goes to REVEALED without a song.
     */
    public function skipRound(Game $game): Game
    {
        $game = $this->transaction($game, function (Game $game): void {
            if (! in_array($game->state, [GameState::RoundOpen, GameState::RoundClosed], true)) {
                throw new GameFlowException('Nur eine laufende Runde kann übersprungen werden.');
            }

            $round = $this->requireCurrentRound($game);
            $round->update(['skipped_at' => now(), 'closed_at' => $round->closed_at ?? now()]);

            if ($this->hasNextRound($game)) {
                $this->openNextRound($game);
            } else {
                $game->update(['state' => GameState::Revealed]);
            }
        });

        $this->broadcastTransition($game);

        return $game;
    }

    /**
     * Opens the closed round again, e.g. when it was closed too early.
     * Only before the reveal: afterwards everyone knows the solution.
     * Placements stay and can be changed until the round is closed again.
     */
    public function reopenRound(Game $game): Game
    {
        $game = $this->transaction($game, function (Game $game): void {
            if ($game->state !== GameState::RoundClosed) {
                throw new GameFlowException('Nur eine geschlossene, noch nicht aufgelöste Runde kann wieder geöffnet werden.');
            }

            $round = $this->requireCurrentRound($game);
            $countdown = $game->countdownSeconds();

            $round->update([
                'closed_at' => null,
                'closes_at' => $countdown ? now()->addSeconds($countdown) : null,
            ]);
            $game->update(['state' => GameState::RoundOpen]);

            if ($round->closes_at !== null) {
                CloseRoundAfterCountdown::dispatch($game->id, $round->id, $round->closes_at->toIso8601String())
                    ->delay($round->closes_at)
                    ->afterCommit();
            }
        });

        $this->broadcastTransition($game);

        return $game;
    }

    /**
     * Back to the lobby: all rounds, placements and tiebreak answers are
     * removed. Players and their start cards stay.
     */
    public function reset(Game $game): Game
    {
        $game = $this->transaction($game, function (Game $game): void {
            $game->rounds()->delete();
            $game->tiebreaks()->delete();
            $game->update(['state' => GameState::Lobby, 'current_round' => 0, 'finished_at' => null]);
        });

        $this->broadcastTransition($game);

        return $game;
    }

    /**
     * Closes the round when its countdown has expired (called from a queued job).
     */
    public function closeAfterCountdown(Game $game, int $roundId, ?string $closesAt = null): void
    {
        $round = $game->currentRound();

        if ($game->state !== GameState::RoundOpen || $round?->id !== $roundId) {
            return;
        }

        // A reopened round has a new countdown; the old job must not close it.
        if ($closesAt !== null && $round->closes_at?->toIso8601String() !== $closesAt) {
            return;
        }

        try {
            $this->advance($game, GameState::RoundOpen, GameState::RoundClosed);
        } catch (GameFlowException) {
            // The moderator was faster.
        }
    }

    public function naturalNextState(Game $game): GameState
    {
        return match ($game->state) {
            GameState::Lobby => GameState::RoundOpen,
            GameState::RoundOpen => GameState::RoundClosed,
            GameState::RoundClosed => GameState::Revealed,
            GameState::Revealed => $this->hasNextRound($game)
                ? GameState::RoundOpen
                : ($game->tiebreakerSong() ? GameState::Tiebreaker : GameState::Finished),
            GameState::Tiebreaker, GameState::Finished => GameState::Finished,
        };
    }

    public function hasNextRound(Game $game): bool
    {
        return $game->current_round < $game->totalRounds();
    }

    /**
     * State as sent to every client. Never contains the solution of an open
     * or closed round; the tiebreaker song is shown without its year.
     *
     * @return array{state: string, round_number: int, total_rounds: int, round_id: int|null, round_skipped: bool, closes_at: string|null, bonus_options: list<string>|null, tiebreaker: array{title: string, artist: string}|null}
     */
    public function publicState(Game $game): array
    {
        $round = $game->currentRound();
        $isRunning = in_array($game->state, [GameState::RoundOpen, GameState::RoundClosed], true);
        $tiebreaker = $game->state === GameState::Tiebreaker ? $game->tiebreakerSong() : null;

        return [
            'state' => $game->state->value,
            'round_number' => $game->current_round,
            'total_rounds' => $game->totalRounds(),
            'round_id' => $round?->id,
            'round_skipped' => $round?->skipped_at !== null,
            'closes_at' => $game->state === GameState::RoundOpen ? $round?->closes_at?->toIso8601String() : null,
            'bonus_options' => $isRunning ? $round?->bonus_options : null,
            'tiebreaker' => $tiebreaker ? ['title' => $tiebreaker->title, 'artist' => $tiebreaker->artist] : null,
        ];
    }

    /**
     * The revealed song and the share of correct placements. Only available
     * once the round has been revealed.
     *
     * @return array{song: array{title: string, artist: string, year: int, image: array{sm: string, lg: string}|null}, percent_correct: int}|null
     */
    public function revealSummary(?Round $round): ?array
    {
        if ($round?->revealed_at === null || $round->skipped_at !== null) {
            return null;
        }

        $song = $round->song;

        return [
            'song' => ['title' => $song->title, 'artist' => $song->artist, 'year' => $song->year, 'image' => $song->imageUrls()],
            'percent_correct' => $this->percentCorrect($round),
        ];
    }

    /**
     * Correct placements relative to all players who could take part, i.e.
     * who joined before the round was closed. Missing submissions count as wrong.
     */
    public function percentCorrect(Round $round): int
    {
        $eligible = $round->game->players()->where('joined_at', '<=', $round->closed_at ?? now())->count();

        if ($eligible === 0) {
            return 0;
        }

        $correct = $round->placements()->where('is_correct', true)->count();

        return (int) round($correct / $eligible * 100);
    }

    /**
     * Throttles the submission counter to at most one broadcast per second;
     * the delayed job sends the count that is current when it runs.
     */
    public function queueSubmissionsCount(Game $game): void
    {
        if (Cache::add('timetune:submissions-count:'.$game->id, true, 1)) {
            BroadcastSubmissionsCount::dispatch($game->id)->delay(now()->addSecond());
        }
    }

    public function broadcastSubmissionsCount(Game $game): void
    {
        $round = $game->state === GameState::RoundOpen || $game->state === GameState::RoundClosed
            ? $game->currentRound()
            : null;

        event(new SubmissionsCount(
            $game->id,
            $round ? $round->placements()->count() : 0,
            $game->players()->count(),
        ));
    }

    private function openNextRound(Game $game): void
    {
        $number = $game->current_round + 1;
        $song = $game->roundSongs()->skip($number - 1)->first();

        if ($song === null) {
            throw new GameFlowException('Es gibt kein weiteres Stück.');
        }

        if ($game->state === GameState::Lobby && ! $game->songs()->where('role', SongRole::StartPool)->exists()) {
            throw new GameFlowException('Der Startkarten-Pool ist leer.');
        }

        $countdown = $game->countdownSeconds();
        [$bonusOptions, $bonusIndex] = $game->bonusQuestionEnabled() ? $this->bonusOptions($game, $song) : [null, null];

        $round = $game->rounds()->create([
            'song_id' => $song->id,
            'number' => $number,
            'bonus_options' => $bonusOptions,
            'bonus_correct_index' => $bonusIndex,
            'opened_at' => now(),
            'closes_at' => $countdown ? now()->addSeconds($countdown) : null,
        ]);

        $game->update(['state' => GameState::RoundOpen, 'current_round' => $number]);

        if ($countdown) {
            CloseRoundAfterCountdown::dispatch($game->id, $round->id, $round->closes_at?->toIso8601String())->delay($round->closes_at)->afterCommit();
        }
    }

    private function closeRound(Game $game): void
    {
        $this->requireCurrentRound($game)->update(['closed_at' => now()]);
        $game->update(['state' => GameState::RoundClosed]);
    }

    /**
     * Evaluates all placements of the current round in one go. Each placement
     * refers to the player's timeline before this round.
     */
    private function revealRound(Game $game): void
    {
        $round = $this->requireCurrentRound($game);

        if ($round->skipped_at === null) {
            $year = $round->song->year;
            $standings = new Standings($game);
            $correct = [];
            $wrong = [];

            foreach ($round->placements()->get(['id', 'player_id', 'position']) as $placement) {
                $years = $standings->years($placement->player_id, $round->number);

                if ($this->evaluator->isCorrect($years, $placement->position, $year)) {
                    $correct[] = $placement->id;
                } else {
                    $wrong[] = $placement->id;
                }
            }

            foreach (array_chunk($correct, 500) as $ids) {
                $round->placements()->whereIn('id', $ids)->update(['is_correct' => true]);
            }
            foreach (array_chunk($wrong, 500) as $ids) {
                $round->placements()->whereIn('id', $ids)->update(['is_correct' => false]);
            }

            if ($round->bonus_correct_index !== null) {
                $round->bonusAnswers()->update(['is_correct' => false]);
                $round->bonusAnswers()->where('choice', $round->bonus_correct_index)->update(['is_correct' => true]);
            }
        }

        $round->update(['revealed_at' => now()]);
        $game->update(['state' => GameState::Revealed]);
    }

    private function startTiebreaker(Game $game): void
    {
        if ($this->hasNextRound($game)) {
            throw new GameFlowException('Es sind noch Stücke offen.');
        }

        if ($game->tiebreakerSong() === null) {
            throw new GameFlowException('Es ist kein Tiebreaker-Song festgelegt.');
        }

        $game->update(['state' => GameState::Tiebreaker]);
    }

    private function finish(Game $game): void
    {
        if ($game->state === GameState::Revealed && $this->hasNextRound($game)) {
            throw new GameFlowException('Es sind noch Stücke offen.');
        }

        $game->update(['state' => GameState::Finished, 'finished_at' => now()]);
    }

    /**
     * Four artists for the multiple-choice bonus question: the correct one plus
     * three other artists of this game. Without enough artists there is no question.
     *
     * @return array{0: list<string>|null, 1: int|null}
     */
    private function bonusOptions(Game $game, Song $song): array
    {
        $others = $game->songs()
            ->where('artist', '!=', $song->artist)
            ->get(['artist'])
            ->map(fn (Song $other) => $other->artist)
            ->unique()
            ->shuffle()
            ->take(3);

        if ($others->count() < 3) {
            return [null, null];
        }

        $options = array_values($others->push($song->artist)->shuffle()->all());

        return [$options, (int) array_search($song->artist, $options, true)];
    }

    private function requireCurrentRound(Game $game): Round
    {
        return $game->currentRound() ?? throw new GameFlowException('Es läuft keine Runde.');
    }

    /**
     * Runs the callback with the game row locked, so concurrent moderator
     * requests cannot trigger the same transition twice.
     *
     * @param  callable(Game): void  $callback
     */
    private function transaction(Game $game, callable $callback): Game
    {
        return DB::transaction(function () use ($game, $callback): Game {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $callback($locked);

            return $locked;
        });
    }

    private function broadcastTransition(Game $game): void
    {
        event(new StateChanged($game->id, $this->publicState($game)));

        if ($game->state === GameState::RoundOpen || $game->state === GameState::Lobby) {
            $this->broadcastSubmissionsCount($game);
        }

        if ($game->state === GameState::Revealed) {
            $round = $game->currentRound();
            $standings = new Standings($game);
            $summary = $this->revealSummary($round);

            if ($round !== null && $summary !== null) {
                event(new RoundRevealed($game->id, $round->number, $summary['song'], $summary['percent_correct']));
            }

            event(new LeaderboardUpdated($game->id, $standings->top(10), $standings->playerCount()));

            if ($round !== null && $round->skipped_at === null) {
                $game->players()->get(['id'])
                    ->map(fn (Player $player) => $player->id)
                    ->chunk(self::RESULT_BATCH_SIZE)
                    ->each(fn (Collection $ids) => SendPlayerResults::dispatch($game->id, $round->id, array_values($ids->all())));
            }
        }

        if ($game->state === GameState::Finished) {
            $standings = new Standings($game);
            $top = $standings->top(10, final: true);

            event(new GameFinished($game->id, array_slice($top, 0, 3), $top));
        }
    }
}
