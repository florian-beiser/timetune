<?php

namespace App\Providers;

use App\Http\Middleware\EnsureHostAccess;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider;

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
        $this->configureGuards();
        $this->configureRateLimiting();
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
