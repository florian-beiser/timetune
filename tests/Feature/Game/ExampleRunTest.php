<?php

use App\Enums\GameState;
use App\Events\GameFinished;
use App\Events\LeaderboardUpdated;
use App\Events\PlayerResult;
use App\Events\RoundRevealed;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Event;

/**
 * Plays one round: open, both players place, close, reveal.
 *
 * @param  array<string, int>  $positions  player name => chosen gap
 */
function playRound(Game $game, array $positions): void
{
    advance($game);

    $round = $game->refresh()->currentRound();
    foreach ($positions as $name => $position) {
        asPlayer($game->players()->where('name', $name)->firstOrFail())
            ->putJson(route('api.rounds.placement', $round), ['position' => $position])
            ->assertOk();
    }

    advance($game);
    advance($game);
}

/**
 * @return list<int>
 */
function timelineYears(Player $player): array
{
    return array_column(asPlayer($player)->getJson(route('api.players.me'))->json('timeline'), 'year');
}

it('reproduces the example run of the specification', function () {
    Event::fake([RoundRevealed::class, PlayerResult::class, LeaderboardUpdated::class, GameFinished::class]);

    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    $markus = joinGame($game, 'Markus', 'Viva la Vida');

    playRound($game, ['Anna' => 1, 'Markus' => 1]);
    expect(timelineYears($anna))->toBe([1982, 1995])
        ->and(timelineYears($markus))->toBe([2008]);

    playRound($game, ['Anna' => 1, 'Markus' => 0]);
    expect(timelineYears($anna))->toBe([1982, 1983, 1995])
        ->and(timelineYears($markus))->toBe([1983, 2008]);

    playRound($game, ['Anna' => 3, 'Markus' => 1]);
    expect(timelineYears($anna))->toBe([1982, 1983, 1995, 2010])
        ->and(timelineYears($markus))->toBe([1983, 2008]);

    playRound($game, ['Anna' => 1, 'Markus' => 0]);
    expect(timelineYears($anna))->toBe([1982, 1983, 1995, 2010])
        ->and(timelineYears($markus))->toBe([1975, 1983, 2008]);

    Event::assertDispatched(RoundRevealed::class, fn (RoundRevealed $event) => $event->roundNumber === 4
        && $event->song === ['title' => 'Bohemian Rhapsody', 'artist' => 'Queen', 'year' => 1975, 'image' => null]
        && $event->percentCorrect === 50);

    Event::assertDispatched(LeaderboardUpdated::class, fn (LeaderboardUpdated $event) => array_map(
        fn (array $row) => [$row['name'], $row['score']],
        $event->top10,
    ) === [['Anna', 4], ['Markus', 3]]);

    Event::assertDispatched(PlayerResult::class, fn (PlayerResult $event) => $event->playerId === $anna->id
        && $event->result['round_number'] === 4
        && $event->result['is_correct'] === false
        && $event->result['correct_position'] === 0
        && $event->result['score'] === 4
        && $event->result['rank'] === 1);

    expect(asPlayer($markus)->getJson(route('api.players.me'))->json())
        ->score->toBe(3)
        ->rank->toBe(2);

    advance($game, GameState::Finished);

    Event::assertDispatched(GameFinished::class, fn (GameFinished $event) => $event->podium[0]['name'] === 'Anna');
    expect($game->refresh()->state)->toBe(GameState::Finished)
        ->and($game->finished_at)->not->toBeNull();
});

it('counts a missing submission as wrong', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    $markus = joinGame($game, 'Markus', 'Viva la Vida');

    playRound($game, ['Anna' => 1]);

    expect(asPlayer($markus)->getJson(route('api.players.me'))->json('result'))
        ->submitted->toBeFalse()
        ->is_correct->toBeFalse()
        ->correct_position->toBe(0);
});

it('does not score a skipped round for anyone', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');

    advance($game);
    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1])->assertOk();

    $this->postJson(route('api.host.skip', $game), [], hostHeaders($game))->assertOk();

    expect($game->refresh())
        ->state->toBe(GameState::RoundOpen)
        ->current_round->toBe(2)
        ->and(timelineYears($anna))->toBe([1982]);
});
