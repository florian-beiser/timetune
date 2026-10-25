<?php

use App\Http\Middleware\EnsureHostAccess;
use App\Models\Player;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// `game.{id}` is a public channel and needs no authorization.

// The host guard only resolves when at least one game is unlocked in the session.
Broadcast::channel('game.{gameId}.host', function (GenericUser $host, int $gameId) {
    return in_array($gameId, session(EnsureHostAccess::SESSION_KEY, []), true);
}, ['guards' => ['host']]);

Broadcast::channel('player.{playerId}', function (Player $player, int $playerId) {
    return $player->id === $playerId;
}, ['guards' => ['player']]);
