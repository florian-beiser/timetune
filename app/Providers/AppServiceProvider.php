<?php

namespace App\Providers;

use App\Http\Middleware\EnsureHostAccess;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider;
use Symfony\Component\HttpFoundation\IpUtils;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureGuards();
        $this->configureRateLimiting();
    }

    /**
     * Trusted reverse proxies from TRUSTED_PROXIES (see config/timetune.php).
     * Set here instead of bootstrap/app.php, because the middleware callback
     * there runs before .env and the (cached) configuration are loaded.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = self::parseTrustedProxies(config('timetune.trusted_proxies'));

        if ($proxies === null) {
            TrustProxies::flushState();

            return;
        }

        TrustProxies::at($proxies);
    }

    /**
     * @return list<string>|string|null
     */
    public static function parseTrustedProxies(mixed $value): array|string|null
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return $value;
        }

        $proxies = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $entry) {
            array_push($proxies, ...(strtolower($entry) === 'private' ? IpUtils::PRIVATE_SUBNETS : [$entry]));
        }

        return array_values(array_unique($proxies));
    }

    /**
     * Players and moderators have no user account: players are identified by
     * their session_token cookie, moderators by the games unlocked in their session.
     */
    protected function configureGuards(): void
    {
        Auth::viaRequest('player-token', function (Request $request): ?Player {
            $token = $request->cookie(Player::COOKIE);

            return is_string($token) && $token !== ''
                ? Player::query()->where('session_token', $token)->first()
                : null;
        });

        Auth::viaRequest('host-session', function (Request $request): ?GenericUser {
            $gameIds = $request->hasSession() ? $request->session()->get(EnsureHostAccess::SESSION_KEY, []) : [];

            return $gameIds === [] ? null : new GenericUser(['id' => 'host', 'game_ids' => $gameIds]);
        });
    }

    /**
     * Many players share the venue's public IP, so the limits are keyed by
     * session or player and only loosely by IP.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('join', fn (Request $request): array => [
            Limit::perMinute(10)->by('join:'.($request->hasSession() ? $request->session()->getId() : $request->ip())),
            Limit::perMinute(1000)->by('join-ip:'.$request->ip()),
        ]);

        RateLimiter::for('submit', function (Request $request): Limit {
            $token = $request->cookie(Player::COOKIE);

            return Limit::perMinute(60)->by('submit:'.(is_string($token) && $token !== '' ? $token : $request->ip()));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
