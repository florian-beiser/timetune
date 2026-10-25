<?php

use App\Enums\GameState;

it('brings a player back to the same timeline after reloading the phone', function () {
    $game = exampleGame();

    $page = visit(route('play.show', $game->code))
        ->on()->mobile()
        ->assertSee('Konzert-Hitster')
        ->type('#name', 'Anna')
        ->press('Mitspielen')
        ->assertSee('Deine Startkarte');

    $player = $game->players()->sole();
    $startCard = $player->startSong;

    advance($game);

    $page->refresh()
        ->assertSee('Stück 1 von 4')
        ->assertSee($startCard->title)
        ->click('hier einfügen')
        ->assertSee('Deine Wahl')
        ->refresh()
        ->assertSee('Deine Wahl')
        ->assertSee('Du kannst bis zum Schließen ändern.')
        ->assertNoJavaScriptErrors();

    expect($game->refresh()->state)->toBe(GameState::RoundOpen)
        ->and($game->players()->count())->toBe(1)
        ->and($game->currentRound()->placements()->sole()->player_id)->toBe($player->id);
});
