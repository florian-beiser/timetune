<?php

use App\Events\PlayerRenamed;
use Illuminate\Support\Facades\Event;

it('lets players change their own name', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');

    asPlayer($anna)->patchJson(route('api.players.rename'), ['name' => '  Anna B.  '])
        ->assertOk()
        ->assertJsonPath('name', 'Anna B.');

    expect($this->getJson(route('api.games.leaderboard', $game))->json('top10.0.name'))->toBe('Anna B.');
});

it('numbers a new name that is already taken, but keeps the own name', function () {
    $game = exampleGame();
    joinGame($game, 'Ben', 'Africa');
    $anna = joinGame($game, 'Anna', 'Africa');

    asPlayer($anna)->patchJson(route('api.players.rename'), ['name' => 'ben'])->assertJsonPath('name', 'ben 2');
    asPlayer($anna)->patchJson(route('api.players.rename'), ['name' => 'Ben 2'])->assertJsonPath('name', 'Ben 2');
});

it('applies the same rules as joining', function (string $name) {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');

    asPlayer($anna)->patchJson(route('api.players.rename'), ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($anna->refresh()->name)->toBe('Anna');
})->with(['too long' => str_repeat('a', 21), 'filtered' => 'Wichser99', 'empty' => '   ']);

it('requires a joined player to rename', function () {
    $this->patchJson(route('api.players.rename'), ['name' => 'Anna'])->assertUnauthorized();
});

it('lets the moderator rename players and tells their phone', function () {
    Event::fake([PlayerRenamed::class]);
    $game = exampleGame();
    $player = joinGame($game, 'Tippfehlr', 'Africa');

    $this->patchJson(route('api.host.players.rename', [$game, $player->id]), ['name' => 'Tippfehler'], hostHeaders($game))
        ->assertOk()
        ->assertJsonPath('name', 'Tippfehler');

    Event::assertDispatched(PlayerRenamed::class, fn (PlayerRenamed $event) => $event->playerId === $player->id && $event->name === 'Tippfehler');
    expect(asPlayer($player)->getJson(route('api.players.me'))->json('player.name'))->toBe('Tippfehler');
});

it('lets the moderator replace offensive names despite the word filter', function () {
    $game = exampleGame();
    $player = joinGame($game, 'Anna', 'Africa');

    $this->patchJson(route('api.host.players.rename', [$game, $player->id]), ['name' => 'Spast Ersatz'], hostHeaders($game))
        ->assertOk();
});

it('only lets the moderator rename players of their own game', function () {
    $game = exampleGame();
    $other = exampleGame();
    $stranger = joinGame($other, 'Fremd', 'Africa');

    $this->patchJson(route('api.host.players.rename', [$game, $stranger->id]), ['name' => 'X'], hostHeaders($game))->assertNotFound();
    $this->patchJson(route('api.host.players.rename', [$other, $stranger->id]), ['name' => 'X'])->assertForbidden();

    expect($stranger->refresh()->name)->toBe('Fremd');
});
