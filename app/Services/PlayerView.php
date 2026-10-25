<?php

namespace App\Services;

use App\Enums\GameState;
use App\Models\BonusAnswer;
use App\Models\Placement;
use App\Models\Player;
use App\Models\Round;

/**
 * Everything one player may see. The song of an open or closed round is
 * never part of it.
 */
class PlayerView
{
    public function __construct(private GameFlow $flow, private TimelineEvaluator $evaluator) {}

    /**
     * @return array<string, mixed>
     */
    public function me(Player $player): array
    {
        $game = $player->game;
        $standings = new Standings($game);
        $round = $game->currentRound();
        $isFinished = $game->state === GameState::Finished;

        $placement = $round ? $round->placements()->where('player_id', $player->id)->first() : null;
        $bonusAnswer = $round ? $round->bonusAnswers()->where('player_id', $player->id)->first() : null;
        $showResult = $game->state === GameState::Revealed && $round !== null && $round->skipped_at === null;

        return [
            'player' => ['id' => $player->id, 'name' => $player->name],
            'game' => ['id' => $game->id, 'code' => $game->code, 'name' => $game->name],
            'state' => $this->flow->publicState($game),
            'start_card' => $player->startSong->toCard(),
            'timeline' => $standings->timeline($player->id),
            'placement' => $placement?->position,
            'bonus_choice' => $bonusAnswer?->choice,
            'score' => $standings->score($player->id),
            'bonus_points' => $standings->bonusPoints($player->id),
            'rank' => $standings->rank($player->id, final: $isFinished),
            'total_players' => $standings->playerCount(),
            'joined_round' => $this->joinedRound($player, $round),
            'result' => $showResult ? $this->result($player, $round, $standings, $placement, $bonusAnswer) : null,
            'tiebreak_year' => $player->tiebreak?->guessed_year,
            'tiebreak_deviation' => $isFinished ? $standings->tiebreakDeviation($player->id) : null,
        ];
    }

    /**
     * Personal result of a revealed round (payload of the PlayerResult event).
     *
     * @return array<string, mixed>
     */
    public function result(Player $player, Round $round, Standings $standings, ?Placement $placement, ?BonusAnswer $bonusAnswer): array
    {
        $song = $round->song;
        $before = $standings->years($player->id, $round->number);

        return [
            'round_number' => $round->number,
            'song' => $song->toCard(),
            'submitted' => $placement !== null,
            'participated' => $this->joinedRound($player, $round) !== null,
            'position' => $placement?->position,
            'is_correct' => (bool) $placement?->is_correct,
            'correct_position' => $this->evaluator->correctPosition($before, $song->year),
            'timeline' => $standings->timeline($player->id),
            'score' => $standings->score($player->id),
            'rank' => $standings->rank($player->id),
            'total_players' => $standings->playerCount(),
            'bonus_correct' => $bonusAnswer?->is_correct,
            'bonus_points' => $standings->bonusPoints($player->id),
        ];
    }

    /**
     * Null if the player joined after the round was closed: such rounds are
     * neither scored nor shown as a miss.
     */
    private function joinedRound(Player $player, ?Round $round): ?int
    {
        if ($round === null) {
            return null;
        }

        if ($round->closed_at !== null && $player->joined_at->greaterThan($round->closed_at)) {
            return null;
        }

        return $round->number;
    }
}
