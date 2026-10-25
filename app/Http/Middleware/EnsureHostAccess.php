<?php

namespace App\Http\Middleware;

use App\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moderator access: the admin_key (query `key` or header `X-Admin-Key`)
 * unlocks the game for the current session.
 */
class EnsureHostAccess
{
    public const SESSION_KEY = 'timetune.host_games';

    public function handle(Request $request, Closure $next): Response
    {
        $game = $request->route('game');
        abort_unless($game instanceof Game, 404);

        $key = $request->query('key') ?? $request->header('X-Admin-Key');

        if (is_string($key) && $game->hasValidAdminKey($key)) {
            $unlocked = $request->session()->get(self::SESSION_KEY, []);
            if (! in_array($game->id, $unlocked, true)) {
                $request->session()->put(self::SESSION_KEY, [...$unlocked, $game->id]);
            }
        } elseif (! self::hasAccess($request, $game)) {
            abort(403, 'Ungültiger Moderator-Schlüssel.');
        }

        return $next($request);
    }

    public static function hasAccess(Request $request, Game $game): bool
    {
        return $request->hasSession()
            && in_array($game->id, $request->session()->get(self::SESSION_KEY, []), true);
    }
}
