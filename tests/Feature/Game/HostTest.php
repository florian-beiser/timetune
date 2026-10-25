<?php

use App\Enums\GameState;
use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\UploadedFile;

it('requires the admin key for the moderator view and actions', function () {
    $game = exampleGame();

    $this->get(route('host.show', $game))->assertForbidden();
    $this->get(route('host.show', ['game' => $game, 'key' => 'falsch']))->assertForbidden();
    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'])->assertForbidden();
    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'], ['X-Admin-Key' => 'falsch'])->assertForbidden();
});

it('unlocks the game for the session once the key was given', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');

    $this->get(route('host.show', ['game' => $game, 'key' => $game->admin_key]))->assertOk();
    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'])->assertOk();

    expect($game->refresh()->state)->toBe(GameState::RoundOpen);
});

it('ignores a double click on the transition button', function () {
    $game = exampleGame();
    joinGame($game, 'Anna', 'Africa');

    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'], hostHeaders($game))->assertOk();
    $this->postJson(route('api.host.advance', $game), ['from' => 'LOBBY'], hostHeaders($game))->assertConflict();

    expect($game->refresh()->current_round)->toBe(1);
});

it('resets the game to the lobby and keeps the players', function () {
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    advance($game);
    asPlayer($anna)->putJson(route('api.rounds.placement', $game->refresh()->currentRound()), ['position' => 1]);

    $this->postJson(route('api.host.reset', $game), [], hostHeaders($game))->assertOk();

    expect($game->refresh())
        ->state->toBe(GameState::Lobby)
        ->current_round->toBe(0)
        ->and($game->rounds()->count())->toBe(0)
        ->and($game->players()->count())->toBe(1);
});

it('imports songs from csv', function () {
    $game = Game::factory()->create();
    $csv = "titel;interpret;jahr;rolle\nWonderwall;Oasis;1995;runde\nAfrica;Toto;1982;start\n\"Final Countdown; The\";Europe;1986;tiebreaker\n";

    $this->post(route('api.host.songs.import', $game), ['file' => UploadedFile::fake()->createWithContent('songs.csv', $csv)], hostHeaders($game) + ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('imported', 3);

    expect($game->songs()->get()->map(fn ($song) => [$song->title, $song->year, $song->role->value])->all())->toBe([
        ['Wonderwall', 1995, 'ROUND'],
        ['Africa', 1982, 'START_POOL'],
        ['Final Countdown; The', 1986, 'TIEBREAKER'],
    ]);
});

it('rejects invalid csv rows without importing anything', function () {
    $game = Game::factory()->create();

    $this->post(route('api.host.songs.import', $game), ['file' => UploadedFile::fake()->createWithContent('songs.csv', "Wonderwall;Oasis;neunzehn;runde\n")], hostHeaders($game) + ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect($game->songs()->count())->toBe(0);
});

it('reorders round songs and only allows edits in the lobby', function () {
    $game = exampleGame();
    $ids = $game->roundSongs()->pluck('id')->reverse()->values()->all();

    $this->putJson(route('api.host.songs.reorder', $game), ['ids' => $ids], hostHeaders($game))->assertOk();
    expect($game->roundSongs()->pluck('id')->all())->toBe($ids);

    joinGame($game, 'Anna', 'Africa');
    advance($game);
    $this->putJson(route('api.host.songs.reorder', $game), ['ids' => $ids], hostHeaders($game))->assertConflict();
});

it('hides offensive names on the screen', function () {
    $game = exampleGame();
    $player = joinGame($game, 'Blödsinn', 'Africa');

    $this->postJson(route('api.host.players.toggle', [$game, $player->id]), [], hostHeaders($game))->assertOk();

    expect($this->getJson(route('api.games.leaderboard', $game))->json('top10.0.name'))->toBe('Spieler '.$player->id);
});

it('lets logged-in organisers create games', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('games.store'), ['name' => 'Frühjahrskonzert'])
        ->assertRedirect();

    expect(Game::query()->where('name', 'Frühjahrskonzert')->exists())->toBeTrue();
});

it('deletes player data 30 days after the game has ended', function () {
    $finished = exampleGame();
    joinGame($finished, 'Anna', 'Africa');
    $finished->update(['state' => GameState::Finished, 'finished_at' => now()->subDays(31)]);
    $recent = exampleGame();
    joinGame($recent, 'Ben', 'Africa');
    $recent->update(['state' => GameState::Finished, 'finished_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [Player::class]])->assertSuccessful();

    expect(Player::query()->pluck('name')->all())->toBe(['Ben']);
});
