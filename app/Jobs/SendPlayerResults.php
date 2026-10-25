<?php

namespace App\Jobs;

use App\Events\PlayerResult;
use App\Models\Game;
use App\Models\Round;
use App\Services\PlayerView;
use App\Services\Standings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the personal result of a revealed round to a batch of players.
 */
class SendPlayerResults implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $playerIds
     */
    public function __construct(public int $gameId, public int $roundId, public array $playerIds) {}

    public function handle(PlayerView $view): void
    {
        $game = Game::query()->find($this->gameId);
        $round = Round::query()->with('song')->find($this->roundId);

        if ($game === null || $round === null || $game->current_round !== $round->number) {
            return;
        }

        $standings = new Standings($game);
        $placements = $round->placements()->whereIn('player_id', $this->playerIds)->get()->keyBy('player_id');
        $bonusAnswers = $round->bonusAnswers()->whereIn('player_id', $this->playerIds)->get()->keyBy('player_id');

        foreach ($game->players()->whereIn('id', $this->playerIds)->get() as $player) {
            event(new PlayerResult($player->id, $view->result(
                $player,
                $round,
                $standings,
                $placements->get($player->id),
                $bonusAnswers->get($player->id),
            )));
        }
    }
}
