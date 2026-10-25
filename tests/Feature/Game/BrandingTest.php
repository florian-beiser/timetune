<?php

use App\Events\RoundRevealed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

function upload(string $route, array $parameters, UploadedFile $file, array $headers)
{
    return test()->post(route($route, $parameters), ['file' => $file], $headers + ['Accept' => 'application/json']);
}

it('uses the default design until the moderator customises it', function () {
    $game = exampleGame();

    expect($this->get(route('screen.show', $game))->inertiaProps('branding'))
        ->toBe(['title' => 'Konzert-Hitster', 'logo_url' => null, 'theme' => null]);
});

it('stores a downscaled logo and shows it to players and the screen', function () {
    $game = exampleGame();

    upload('api.host.logo.store', [$game], UploadedFile::fake()->image('logo.png', 2000, 600), hostHeaders($game))
        ->assertCreated();

    $logo = $game->refresh()->logo;
    Storage::disk('public')->assertExists("games/{$game->id}/{$logo}-480.webp");
    expect(getimagesizefromstring(Storage::disk('public')->get("games/{$game->id}/{$logo}-480.webp"))[0])->toBe(480)
        ->and($this->get(route('play.show', $game->code))->inertiaProps('branding.logo_url'))->toEndWith("{$logo}-480.webp");

    $this->deleteJson(route('api.host.logo.destroy', $game), [], hostHeaders($game))->assertOk();
    Storage::disk('public')->assertMissing("games/{$game->id}/{$logo}-480.webp");
});

it('rejects non-image and svg uploads', function (UploadedFile $file) {
    $game = exampleGame();

    upload('api.host.logo.store', [$game], $file, hostHeaders($game))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'text' => fn () => UploadedFile::fake()->create('logo.png', 10, 'text/plain'),
]);

it('saves theme colours and resets them to the default', function () {
    $game = exampleGame();

    $this->patchJson(route('api.host.update', $game), ['options' => ['theme' => ['accent' => '#c8102e', 'background' => '#1a1a1a']]], hostHeaders($game))
        ->assertOk();
    expect($game->refresh()->theme())->toBe(['accent' => '#c8102e', 'background' => '#1a1a1a']);

    $this->patchJson(route('api.host.update', $game), ['options' => ['theme' => ['accent' => 'red', 'background' => '#1a1a1a']]], hostHeaders($game))
        ->assertUnprocessable();

    $this->patchJson(route('api.host.update', $game), ['options' => ['theme' => null]], hostHeaders($game))->assertOk();
    expect($game->refresh()->theme())->toBeNull();
});

it('keeps the photo of the open song secret and shows it after the reveal', function () {
    Event::fake([RoundRevealed::class]);
    $game = exampleGame();
    $anna = joinGame($game, 'Anna', 'Africa');
    $wonderwall = $game->songs()->where('title', 'Wonderwall')->sole();

    upload('api.host.songs.image.store', [$game, $wonderwall->id], UploadedFile::fake()->image('oasis.jpg', 1600, 1600), hostHeaders($game))
        ->assertCreated();
    $image = $wonderwall->refresh()->image;
    Storage::disk('public')->assertExists(["games/{$game->id}/songs/{$image}-400.webp", "games/{$game->id}/songs/{$image}-1200.webp"]);

    advance($game);
    expect(asPlayer($anna)->getJson(route('api.players.me'))->getContent())->not->toContain($image)
        ->and(json_encode($this->get(route('screen.show', $game))->inertiaProps()))->not->toContain($image);

    advance($game);
    advance($game);

    Event::assertDispatched(RoundRevealed::class, fn (RoundRevealed $event) => str_ends_with((string) $event->song['image']['lg'], "{$image}-1200.webp"));
    expect(asPlayer($anna)->getJson(route('api.players.me'))->json('result.song.image.sm'))->toEndWith("{$image}-400.webp");
});

it('lets the moderator change the title shown to players and on the screen', function () {
    $game = exampleGame();

    $this->patchJson(route('api.host.update', $game), ['options' => ['title' => '  Cäcilia-Hitster  ']], hostHeaders($game))
        ->assertOk();

    expect($this->get(route('screen.show', $game))->inertiaProps('branding.title'))->toBe('Cäcilia-Hitster')
        ->and($this->get(route('play.show', $game->code))->inertiaProps('branding.title'))->toBe('Cäcilia-Hitster');

    $this->patchJson(route('api.host.update', $game), ['options' => ['title' => '']], hostHeaders($game))->assertOk();
    expect($game->refresh()->title())->toBe('Konzert-Hitster');
});

it('limits the title length', function () {
    $game = exampleGame();

    $this->patchJson(route('api.host.update', $game), ['options' => ['title' => str_repeat('x', 41)]], hostHeaders($game))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('options.title');
});
