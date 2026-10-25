<?php

use App\Events\PlayerRemoved;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

it('shows the moderator every player with activity and submissions', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    joinGame($game, 'Ben', 'Africa');
    advance($game);
    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1]);

    $this->get(route('host.show', ['game' => $game, 'key' => $game->admin_key]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('players', 2)
            ->where('players.1.name', 'Anna')
            ->where('players.1.submissions', 1)
            ->where('players.0.submissions', 0)
            ->whereNot('players.1.last_seen_at', null)
            ->where('rounds_played', 1)
            ->where('inactive_after_seconds', 120));
});

it('marks a player as active with each heartbeat', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    $this->travel(5)->minutes();

    asPlayer($anna)->postJson(route('api.players.heartbeat'))->assertNoContent();

    expect($anna->refresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(2);
});

it('removes a player and tells their phone', function () {
    Event::fake([PlayerRemoved::class]);
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1]);

    $this->deleteJson(route('api.host.players.remove', [$game, $anna->id]), [], hostHeaders($game))->assertNoContent();

    Event::assertDispatched(PlayerRemoved::class, fn (PlayerRemoved $event) => $event->playerId === $anna->id);
    expect($game->players()->count())->toBe(0)
        ->and($game->currentRound()->placements()->count())->toBe(0);
    asPlayer($anna)->getJson(route('api.players.me'))->assertUnauthorized();
});

it('removes only inactive players', function () {
    Event::fake([PlayerRemoved::class]);
    $game = exampleGame();
    joinGame($game, 'Schläfer', 'Africa');
    $this->travel(3)->minutes();
    $active = joinGame($game, 'Wach', 'Africa');

    $this->deleteJson(route('api.host.players.remove-inactive', $game), [], hostHeaders($game))
        ->assertOk()
        ->assertJsonPath('removed', 1);

    expect($game->players()->pluck('id')->all())->toBe([$active->id]);
    Event::assertDispatchedTimes(PlayerRemoved::class, 1);
});

it('only lets the moderator remove players of their own game', function () {
    $game = exampleGame();
    $other = exampleGame();
    $stranger = joinGame($other, 'Fremd', 'Africa');

    $this->deleteJson(route('api.host.players.remove', [$game, $stranger->id]), [], hostHeaders($game))->assertNotFound();
    $this->deleteJson(route('api.host.players.remove', [$other, $stranger->id]))->assertForbidden();

    expect($stranger->fresh())->not->toBeNull();
});
