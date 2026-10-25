<?php

use App\Enums\GameState;

it('breaks ties by the smallest deviation and then by the earlier submission', function () {
    $game = exampleGame();
    $players = collect(['Anna', 'Ben', 'Cleo', 'Dora'])->map(fn ($name) => joinGame($game, $name, 'Africa'));

    foreach (range(1, 4) as $round) {
        advance($game);
        advance($game);
        advance($game);
    }
    advance($game, GameState::Tiebreaker);

    // The tiebreaker song is from 1986.
    $guesses = ['Anna' => 1990, 'Ben' => 1985, 'Cleo' => 1987, 'Dora' => 1970];
    foreach ($players as $player) {
        $this->travel(1)->seconds();
        asPlayer($player)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => $guesses[$player->name]])->assertCreated();
    }
    advance($game);

    expect(collect($this->getJson(route('api.games.leaderboard', $game))->json('top10'))->map(fn ($row) => [$row['name'], $row['rank']])->all())
        ->toBe([['Ben', 1], ['Cleo', 2], ['Anna', 3], ['Dora', 4]]);
});

it('accepts only one four-digit tiebreak answer during the tiebreaker', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');

    asPlayer($anna)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1986])->assertConflict();

    foreach (range(1, 4) as $round) {
        advance($game);
        advance($game);
        advance($game);
    }
    advance($game, GameState::Tiebreaker);

    asPlayer($anna)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 86])->assertUnprocessable();
    asPlayer($anna)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1986])->assertCreated();
    asPlayer($anna)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1987])->assertConflict();
});

it('can finish directly without a tiebreaker', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');

    foreach (range(1, 4) as $round) {
        advance($game);
        advance($game);
        advance($game);
    }
    advance($game, GameState::Finished);

    expect($game->refresh()->state)->toBe(GameState::Finished);
});
