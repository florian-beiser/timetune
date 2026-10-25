<?php

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Models\Game;

it('lets a player join with a name and hands out a start card', function () {
    $game = exampleGame();

    $response = $this->postJson(route('api.join', $game->code), ['name' => '  Anna  ']);

    $response->assertCreated()
        ->assertJsonPath('me.player.name', 'Anna')
        ->assertJsonPath('me.score', 1)
        ->assertCookie('timetune_player');
    expect($response->json('start_card.title'))->toBeIn(['Africa', 'Viva la Vida']);
});

it('numbers equal names', function () {
    $game = exampleGame();

    joinGame($game, 'Anna');
    joinGame($game, 'anna');
    joinGame($game, 'Anna');

    expect($game->players()->orderBy('id')->pluck('name')->all())->toBe(['Anna', 'anna 2', 'Anna 3']);
});

it('rejects names that are too long or on the word filter', function (string $name) {
    $this->postJson(route('api.join', exampleGame()->code), ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
})->with(['too long' => str_repeat('a', 21), 'filtered' => 'Wichser99', 'empty' => '']);

it('rotates start cards evenly over a small pool', function () {
    $game = exampleGame();

    foreach (range(1, 6) as $i) {
        joinGame($game, 'Spieler '.$i);
    }

    $counts = $game->players()->get()->countBy('start_song_id')->values()->sort()->values()->all();
    expect($counts)->toBe([3, 3]);
});

it('refuses to join a finished game', function () {
    $game = exampleGame();
    $game->update(['state' => GameState::Finished, 'finished_at' => now()]);

    $this->postJson(route('api.join', $game->code), ['name' => 'Anna'])
        ->assertConflict()
        ->assertJsonPath('message', 'Spiel bereits beendet.');
});

it('refuses to join while the start pool is empty', function () {
    $game = Game::factory()->create();

    $this->postJson(route('api.join', $game->code), ['name' => 'Anna'])->assertConflict();
});

it('lets a player who joins during an open round still submit', function () {
    $game = exampleGame();
    advance($game);

    $late = joinGame($game, 'Spät', 'Africa');

    asPlayer($late)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1])
        ->assertOk();
});

it('does not count missed rounds for players who join after closing', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    advance($game);
    advance($game);

    $this->travel(1)->seconds();
    $late = joinGame($game, 'Spät', 'Africa');
    advance($game);

    expect(asPlayer($late)->getJson(route('api.players.me'))->json())
        ->score->toBe(1)
        ->joined_round->toBeNull()
        ->result->participated->toBeFalse();
});

it('returns the same player and timeline after a reload', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');

    asPlayer($anna)->get(route('play.show', $game->code))
        ->assertInertia(fn ($page) => $page
            ->component('play/Show')
            ->where('me.player.id', $anna->id)
            ->where('me.timeline.0.title', 'Africa'));
});

it('starts a game only with round songs and a start pool', function () {
    $game = Game::factory()->create();
    $game->songs()->create(['title' => 'A', 'artist' => 'B', 'year' => 2000, 'role' => SongRole::Round]);

    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'], hostHeaders($game))
        ->assertConflict();
});
