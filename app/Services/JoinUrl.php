<?php

namespace App\Services;

use App\Models\Game;

/**
 * The address behind the QR code on the screen. Moderators can replace the
 * app's own URL, e.g. with a short domain or a short link.
 */
class JoinUrl
{
    /**
     * Turns the moderator's input into an absolute http(s) URL, or null if it
     * is not usable. Without a scheme, local addresses get http, all others https.
     */
    public function normalize(string $input): ?string
    {
        $url = trim($input);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $host = strtolower((string) preg_replace('#[:/?].*$#', '', $url));
            $isLocal = $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_ends_with($host, '.local');
            $url = ($isLocal ? 'http://' : 'https://').$url;
        }

        $parts = parse_url(str_replace('{code}', 'CODE', $url));

        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || filter_var(str_replace('{code}', 'CODE', $url), FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    /**
     * Join link of a game: the configured address with the code filled in, or
     * the app's own play URL when nothing is configured.
     */
    public function for(Game $game): string
    {
        $configured = $game->options['join_url'] ?? null;

        if (! is_string($configured) || $configured === '') {
            return $this->default($game);
        }

        if (str_contains($configured, '{code}')) {
            return str_replace('{code}', $game->code, $configured);
        }

        $path = parse_url($configured, PHP_URL_PATH);

        // Only a host: append the play path. With a path: a ready-made (short) link.
        if ($path === null || $path === false || $path === '' || $path === '/') {
            return rtrim($configured, '/').'/play/'.$game->code;
        }

        return $configured;
    }

    public function default(Game $game): string
    {
        return route('play.show', $game->code);
    }

    /**
     * The address as shown in large type on the screen (without scheme).
     */
    public function display(string $url): string
    {
        return (string) preg_replace('#^https?://#', '', $url);
    }
}
