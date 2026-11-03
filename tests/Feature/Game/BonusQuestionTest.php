<?php

use App\Enums\GameState;
use App\Models\Game;
use App\Models\Player;

/**
 * Plays all four rounds without placements; $bonusFor answers the bonus
 * question of the first round correctly.
 *
 * @param  list<Player>  $bonusFor
 */
function playAllRounds(Game $game, array $bonusFor = []): void
{
    foreach (range(1, 4) as $number) {
        advance($game);

        if ($number === 1) {
            $round = $game->refresh()->currentRound();
            $correct = $round->bonus_correct_index;

            foreach ($bonusFor as $player) {
                asPlayer($player)->putJson(route('api.rounds.bonus', $round), ['choice' => $correct])->assertOk();
            }
        }

        advance($game);
        advance($game);
    }
}

it('offers only conductors of the round songs and the extra list, the correct one once', function () {
    $game = exampleGame(['bonus_question' => true, 'bonus_artists' => [' oasis ', 'Udo Jürgens']]);

    foreach (range(1, 4) as $number) {
        advance($game);
        $round = $game->refresh()->currentRound();
        $options = collect($round->bonus_options);

        expect($options)->toHaveCount(4)
            ->and($options->diff(['Oasis', 'Michael Jackson', 'Adele', 'Queen', 'Udo Jürgens']))->toBeEmpty()
            ->and($options->filter(fn (string $name) => mb_strtolower($name) === mb_strtolower($round->song->artist)))->toHaveCount(1)
            ->and($options[$round->bonus_correct_index])->toBe($round->song->artist);

        advance($game);
        advance($game);
    }
});

it('offers fewer options when few conductors exist and no question without any other', function () {
    $game = exampleGame(['bonus_question' => true]);
    $game->roundSongs()->update(['artist' => 'Oasis']);

    advance($game);
    expect($game->refresh()->currentRound()->bonus_options)->toBeNull();

    advance($game);
    advance($game);
    $game->update(['options' => [...$game->options, 'bonus_artists' => ['Udo Jürgens']]]);
    advance($game);

    expect($game->refresh()->currentRound()->bonus_options)->toEqualCanonicalizing(['Oasis', 'Udo Jürgens']);
});

it('ranks equal cards by bonus points', function () {
    $game = exampleGame(['bonus_question' => true]);
    $anna = joinGame($game, 'Anna', 'Africa');
    $ben = joinGame($game, 'Ben', 'Africa');

    playAllRounds($game, [$ben]);

    expect($this->getJson(route('api.games.leaderboard', $game))->json('top10'))
        ->sequence(
            fn ($row) => $row->name->toBe('Ben')->rank->toBe(1)->bonus->toBe(1),
            fn ($row) => $row->name->toBe('Anna')->rank->toBe(2)->bonus->toBe(0),
        );
});

it('lets only the players tied on the podium take part in the tiebreaker', function () {
    $game = exampleGame(['bonus_question' => true]);
    $anna = joinGame($game, 'Anna', 'Africa');
    $ben = joinGame($game, 'Ben', 'Africa');
    $cleo = joinGame($game, 'Cleo', 'Africa');

    playAllRounds($game, [$anna]);

    $this->get(route('host.show', ['game' => $game, 'key' => $game->admin_key]))
        ->assertInertia(fn ($page) => $page->where('tie_in_top_three', true)->where('tiebreak_players', ['Ben', 'Cleo']));

    advance($game, GameState::Tiebreaker);

    expect(asPlayer($anna)->getJson(route('api.players.me'))->json('tiebreak_eligible'))->toBeFalse()
        ->and(asPlayer($ben)->getJson(route('api.players.me'))->json('tiebreak_eligible'))->toBeTrue();

    asPlayer($anna)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1986])->assertConflict();
    asPlayer($ben)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1990])->assertCreated();
    asPlayer($cleo)->postJson(route('api.games.tiebreak', $game), ['guessed_year' => 1987])->assertCreated();
    advance($game);

    expect(collect($this->getJson(route('api.games.leaderboard', $game))->json('top10'))->map(fn ($row) => [$row['name'], $row['rank']])->all())
        ->toBe([['Anna', 1], ['Cleo', 2], ['Ben', 3]]);
});

it('resets all bonus points with the game', function () {
    $game = exampleGame(['bonus_question' => true]);
    $anna = joinGame($game, 'Anna', 'Africa');
    playAllRounds($game, [$anna]);

    expect(asPlayer($anna)->getJson(route('api.players.me'))->json('bonus_points'))->toBe(1);

    $this->postJson(route('api.host.reset', $game), [], hostHeaders($game))->assertOk();

    expect(asPlayer($anna)->getJson(route('api.players.me'))->json('bonus_points'))->toBe(0);
});

it('saves the extra conductors without blanks and duplicates', function () {
    $game = exampleGame();

    $this->patchJson(route('api.host.update', $game), ['options' => ['bonus_artists' => [' Udo Jürgens ', 'udo jürgens', '', 'Peter Alexander']]], hostHeaders($game))
        ->assertOk();

    expect($game->refresh()->bonusArtists())->toBe(['Udo Jürgens', 'Peter Alexander']);
});
