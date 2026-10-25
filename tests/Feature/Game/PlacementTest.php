<?php

it('lets players change their gap until the round closes', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    $round = $game->refresh()->currentRound();

    asPlayer($anna)->putJson(route('api.rounds.placement', $round), ['position' => 0])->assertOk();
    asPlayer($anna)->putJson(route('api.rounds.placement', $round), ['position' => 1])->assertOk();

    expect($round->placements()->count())->toBe(1)
        ->and($round->placements()->value('position'))->toBe(1);
});

it('rejects submissions after closing', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    advance($game);

    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1])
        ->assertConflict()
        ->assertJsonPath('message', 'Zu spät – die Abgabe ist geschlossen.');
});

it('only accepts the n+1 gaps of the own timeline', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);

    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 2])
        ->assertUnprocessable();
});

it('requires a joined player', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    advance($game);

    $this->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 0])
        ->assertUnauthorized();
});

it('rejects placements for another game', function () {
    $game = exampleGame();
    $other = exampleGame();
    $stranger = joinGame($other, 'Fremd', 'Africa');
    advance($game);

    asPlayer($stranger)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 0])
        ->assertForbidden();
});

it('scores the bonus question separately from the cards', function () {
    $game = exampleGame(['bonus_question' => true]);
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    $round = $game->refresh()->currentRound();
    $correct = array_search('Oasis', $round->bonus_options, true);

    expect($round->bonus_options)->toHaveCount(4)->toContain('Oasis');

    asPlayer($anna)->putJson(route('api.rounds.bonus', $round), ['choice' => $correct])->assertOk();
    advance($game);
    advance($game);

    expect(asPlayer($anna)->getJson(route('api.players.me'))->json())
        ->score->toBe(1)
        ->bonus_points->toBe(1)
        ->result->bonus_correct->toBeTrue();
});

it('closes the round automatically after the countdown', function () {
    $game = exampleGame(['countdown_seconds' => 30]);
    advance($game);

    expect($game->refresh()->state->value)->toBe('ROUND_CLOSED');
});
