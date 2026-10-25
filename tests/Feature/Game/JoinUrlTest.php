<?php

use Inertia\Testing\AssertableInertia;

function setJoinUrl(mixed $value)
{
    $game = test()->game;

    return test()->patchJson(route('api.host.update', $game), ['options' => ['join_url' => $value]], hostHeaders($game));
}

function screenJoinUrl(): array
{
    $props = test()->get(route('screen.show', test()->game))->inertiaProps();

    return [$props['join_url'], $props['join_url_short']];
}

beforeEach(fn () => $this->game = exampleGame());

it('uses the app url when nothing is configured', function () {
    expect(screenJoinUrl()[0])->toBe(route('play.show', $this->game->code));
});

it('appends the play path to a bare host', function () {
    setJoinUrl('localhost:8010')->assertOk();

    expect(screenJoinUrl())->toBe(['http://localhost:8010/play/'.$this->game->code, 'localhost:8010/play/'.$this->game->code]);
});

it('uses a short link with a path as is', function () {
    setJoinUrl('bit.ly/mv-hitster')->assertOk();

    expect(screenJoinUrl())->toBe(['https://bit.ly/mv-hitster', 'bit.ly/mv-hitster']);
});

it('fills in the code placeholder', function () {
    setJoinUrl('https://tt.example.org/?spiel={code}')->assertOk();

    expect(screenJoinUrl()[0])->toBe('https://tt.example.org/?spiel='.$this->game->code);
});

it('rejects unusable addresses', function () {
    setJoinUrl('javascript:alert(1)')->assertUnprocessable()->assertJsonValidationErrors('options.join_url');

    expect($this->game->refresh()->options['join_url'] ?? null)->toBeNull();
});

it('goes back to the app url when cleared', function () {
    setJoinUrl('bit.ly/mv-hitster');
    setJoinUrl(null)->assertOk();

    expect(screenJoinUrl()[0])->toBe(route('play.show', $this->game->code));
});

it('shows the moderator the stored value and the default', function () {
    setJoinUrl('localhost:8010');

    $this->get(route('host.show', ['game' => $this->game, 'key' => $this->game->admin_key]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.join_url', 'http://localhost:8010')
            ->where('default_join_url', route('play.show', $this->game->code)));
});
