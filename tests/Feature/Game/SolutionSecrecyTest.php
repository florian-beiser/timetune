<?php

use App\Enums\GameState;
use App\Events\LeaderboardUpdated;
use App\Events\StateChanged;
use App\Events\SubmissionsCount;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

/**
 * Asserts that nothing reveals the song of round 1 (Wonderwall, Oasis, 1995).
 */
function assertNoSolution(string $payload): void
{
    expect($payload)
        ->not->toContain('Wonderwall')
        ->not->toContain('Oasis')
        ->not->toContain('1995');
}

it('never sends the solution of an open or closed round to players or the screen', function (GameState $state) {
    Event::fake([StateChanged::class, SubmissionsCount::class, LeaderboardUpdated::class]);

    $game = exampleGame(['bonus_question' => false]);
    $anna = joinGame($game, 'Anna', 'Africa');

    advance($game);
    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1])->assertOk();
    if ($state === GameState::RoundClosed) {
        advance($game);
    }
    expect($game->refresh()->state)->toBe($state);

    assertNoSolution(asPlayer($anna)->getJson(route('api.players.me'))->getContent());
    assertNoSolution($this->getJson(route('api.games.leaderboard', $game))->getContent());
    assertNoSolution(json_encode(
        $this->get(route('screen.show', $game))->assertOk()->inertiaProps(),
    ));
    assertNoSolution(json_encode(
        asPlayer($anna)->get(route('play.show', $game->code))->assertOk()->inertiaProps(),
    ));

    Event::assertDispatched(StateChanged::class, function (StateChanged $event) {
        assertNoSolution(json_encode($event->broadcastWith()));

        return true;
    });
})->with([GameState::RoundOpen, GameState::RoundClosed]);

it('shows the solution of the current round to the moderator only', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');
    advance($game);

    $this->get(route('host.show', ['game' => $game, 'key' => $game->admin_key]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('host/Show')
            ->where('current_song.title', 'Wonderwall')
            ->where('current_song.year', 1995));
});
