<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Applies TRUSTED_PROXIES like at boot time.
 */
function trustProxies(?string $value): void
{
    config(['timetune.trusted_proxies' => $value]);
    app()->getProvider(AppServiceProvider::class)->boot();
}

/**
 * A request as Caddy forwards it: TLS terminated at the proxy.
 *
 * @return array<string, string>
 */
function forwardedHeaders(string $host = 'timetune.beiser.info'): array
{
    return [
        'X-Forwarded-For' => '93.184.216.34',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => $host,
    ];
}

afterEach(fn () => TrustProxies::flushState());

it('generates https urls and redirects behind a trusted proxy', function () {
    trustProxies('private');
    $game = exampleGame();

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->post(route('play.find'), ['code' => $game->code], forwardedHeaders())
        ->assertRedirect("https://timetune.beiser.info/play/{$game->code}");

    $props = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->get("/screen/{$game->id}", forwardedHeaders())
        ->inertiaProps();

    expect($props['join_url'])->toBe("https://timetune.beiser.info/play/{$game->code}");
});

it('keeps a non-default port from X-Forwarded-Host', function () {
    trustProxies('private');
    $game = exampleGame();

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->post(route('play.find'), ['code' => $game->code], forwardedHeaders('timetune.beiser.info:8443'))
        ->assertRedirect("https://timetune.beiser.info:8443/play/{$game->code}");
});

it('ignores forwarded headers from addresses that are not trusted', function (?string $setting, string $remoteAddress) {
    trustProxies($setting);
    $game = exampleGame();

    $location = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->post(route('play.find'), ['code' => $game->code], forwardedHeaders())
        ->headers->get('Location');

    expect($location)->toStartWith(config('app.url').'/')
        ->not->toContain('timetune.beiser.info');
})->with([
    'no proxies configured (default)' => [null, '172.18.0.1'],
    'public client, private proxies trusted' => ['private', '93.184.216.34'],
    'other ip than the configured proxy' => ['10.0.0.5', '10.0.0.6'],
]);

it('parses the TRUSTED_PROXIES setting', function (?string $value, array|string|null $expected) {
    expect(AppServiceProvider::parseTrustedProxies($value))->toBe($expected);
})->with([
    'empty' => ['', null],
    'unset' => [null, null],
    'all' => ['*', '*'],
    'list' => [' 10.0.0.5 , 192.168.1.0/24 ', ['10.0.0.5', '192.168.1.0/24']],
    'private keyword' => ['private', array_values(IpUtils::PRIVATE_SUBNETS)],
]);
