<?php

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The game of the example run (spec section 7): 4 round songs,
 * start cards Africa (1982) and Viva la Vida (2008), tiebreaker 1986.
 */
function exampleGame(array $options = []): Game
{
    $game = Game::factory()->create(['options' => [...['bonus_question' => false, 'countdown_seconds' => null], ...$options]]);

    $songs = [
        ['Wonderwall', 'Oasis', 1995, SongRole::Round],
        ['Billie Jean', 'Michael Jackson', 1983, SongRole::Round],
        ['Rolling in the Deep', 'Adele', 2010, SongRole::Round],
        ['Bohemian Rhapsody', 'Queen', 1975, SongRole::Round],
        ['Africa', 'Toto', 1982, SongRole::StartPool],
        ['Viva la Vida', 'Coldplay', 2008, SongRole::StartPool],
        ['The Final Countdown', 'Europe', 1986, SongRole::Tiebreaker],
    ];

    foreach ($songs as $index => [$title, $artist, $year, $role]) {
        $game->songs()->create(['title' => $title, 'artist' => $artist, 'year' => $year, 'role' => $role, 'sort_order' => $index + 1]);
    }

    return $game;
}

/**
 * Joins through the API and returns the player; optionally forces the start card.
 */
function joinGame(Game $game, string $name, ?string $startTitle = null): Player
{
    test()->postJson(route('api.join', $game->code), ['name' => $name])->assertCreated();

    $player = $game->players()->latest('id')->firstOrFail();

    if ($startTitle !== null) {
        $player->update(['start_song_id' => $game->songs()->where('title', $startTitle)->value('id')]);
    }

    return $player;
}

/**
 * @return array<string, string>
 */
function hostHeaders(Game $game): array
{
    return ['X-Admin-Key' => $game->admin_key];
}

function advance(Game $game, ?GameState $to = null): void
{
    $game->refresh();

    test()->postJson(route('api.host.advance', $game), ['from' => $game->state->value, 'to' => $to?->value], hostHeaders($game))
        ->assertOk();
}

function asPlayer(Player $player): TestCase
{
    // Guards cache their user for the lifetime of the test application.
    app('auth')->forgetGuards();

    return test()->withCredentials()->withCookie(Player::COOKIE, $player->session_token);
}
