<?php

use App\Enums\GameState;
use App\Jobs\CloseRoundAfterCountdown;
use App\Models\Game;
use App\Services\GameFlow;
use Illuminate\Support\Facades\Queue;

function reopen(Game $game)
{
    return test()->postJson(route('api.host.reopen', $game), [], hostHeaders($game));
}

it('reopens a closed round and keeps the placements changeable', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    $round = $game->refresh()->currentRound();
    asPlayer($anna)->putJson(route('api.rounds.placement', $round), ['position' => 0])->assertOk();
    advance($game);

    reopen($game)->assertOk()->assertJsonPath('state', 'ROUND_OPEN')->assertJsonPath('round_id', $round->id);

    expect($round->refresh()->closed_at)->toBeNull()
        ->and(asPlayer($anna)->getJson(route('api.players.me'))->json('placement'))->toBe(0);

    asPlayer($anna)->putJson(route('api.rounds.placement', $round), ['position' => 1])->assertOk();
    advance($game);
    advance($game);

    expect(asPlayer($anna)->getJson(route('api.players.me'))->json('result.is_correct'))->toBeTrue();
});

it('lets players who joined after closing take part once reopened', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    advance($game);
    advance($game);
    $this->travel(1)->seconds();
    $late = joinGame($game, 'Spät', 'Africa');

    reopen($game)->assertOk();

    asPlayer($late)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1])->assertOk();
    expect(asPlayer($late)->getJson(route('api.players.me'))->json('joined_round'))->toBe(1);
});

it('cannot reopen a round that is open, revealed or not started', function (int $steps) {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    for ($step = 0; $step < $steps; $step++) {
        advance($game);
    }

    reopen($game)->assertConflict();
})->with(['lobby' => 0, 'open' => 1, 'revealed' => 3]);

it('gives a reopened round a fresh countdown that the old job cannot cut short', function () {
    $game = exampleGame(['countdown_seconds' => 30]);
    joinGame($game, 'Anna', 'Africa');
    Queue::fake([CloseRoundAfterCountdown::class]);
    advance($game);
    $staleJob = Queue::pushed(CloseRoundAfterCountdown::class)->sole();
    advance($game);

    $this->travel(10)->seconds();
    reopen($game)->assertOk();
    $freshJob = Queue::pushed(CloseRoundAfterCountdown::class)->last();

    $staleJob->handle(app(GameFlow::class));
    expect($game->refresh()->state)->toBe(GameState::RoundOpen);

    $freshJob->handle(app(GameFlow::class));
    expect($game->refresh()->state)->toBe(GameState::RoundClosed);
});
