<?php

use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Host\BrandingController;
use App\Http\Controllers\Host\GameActionController;
use App\Http\Controllers\Host\SongController;
use App\Http\Controllers\HostController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\ScreenController;
use App\Http\Middleware\EnsureHostAccess;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::post('play', [PlayController::class, 'find'])->name('play.find');

// Views
Route::get('play/{code}', [PlayController::class, 'show'])->name('play.show');
Route::get('screen/{game}', [ScreenController::class, 'show'])->name('screen.show');
Route::get('host/{game}', [HostController::class, 'show'])->middleware(EnsureHostAccess::class)->name('host.show');

// JSON endpoints for players and the screen
Route::prefix('api')->name('api.')->group(function () {
    Route::post('games/{code}/join', [PlayerController::class, 'join'])->middleware('throttle:join')->name('join');
    Route::get('players/me', [PlayerController::class, 'me'])->name('players.me');
    Route::patch('players/me', [PlayerController::class, 'rename'])->middleware('throttle:submit')->name('players.rename');
    Route::post('players/me/heartbeat', [PlayerController::class, 'heartbeat'])->middleware('throttle:submit')->name('players.heartbeat');
    Route::put('rounds/{round}/placement', [PlayerController::class, 'place'])->middleware('throttle:submit')->name('rounds.placement');
    Route::put('rounds/{round}/bonus', [PlayerController::class, 'answerBonus'])->middleware('throttle:submit')->name('rounds.bonus');
    Route::post('games/{game}/tiebreak', [PlayerController::class, 'tiebreak'])->middleware('throttle:submit')->name('games.tiebreak');
    Route::get('games/{game}/leaderboard', LeaderboardController::class)->name('games.leaderboard');

    // Moderator, requires the admin_key (header X-Admin-Key or unlocked session)
    Route::prefix('host/games/{game}')->name('host.')->middleware(EnsureHostAccess::class)->group(function () {
        Route::post('advance', [GameActionController::class, 'advance'])->name('advance');
        Route::post('skip', [GameActionController::class, 'skip'])->name('skip');
        Route::post('reopen', [GameActionController::class, 'reopen'])->name('reopen');
        Route::post('reset', [GameActionController::class, 'reset'])->name('reset');
        Route::patch('/', [GameActionController::class, 'update'])->name('update');
        Route::post('players/{player}/toggle-hidden', [GameActionController::class, 'togglePlayer'])->name('players.toggle');
        Route::delete('players/inactive', [GameActionController::class, 'removeInactivePlayers'])->name('players.remove-inactive');
        Route::patch('players/{player}', [GameActionController::class, 'renamePlayer'])->whereNumber('player')->name('players.rename');
        Route::delete('players/{player}', [GameActionController::class, 'removePlayer'])->whereNumber('player')->name('players.remove');
        Route::post('logo', [BrandingController::class, 'storeLogo'])->name('logo.store');
        Route::delete('logo', [BrandingController::class, 'destroyLogo'])->name('logo.destroy');
        Route::post('songs/{song}/image', [BrandingController::class, 'storeSongImage'])->name('songs.image.store');
        Route::delete('songs/{song}/image', [BrandingController::class, 'destroySongImage'])->name('songs.image.destroy');
        Route::post('songs', [SongController::class, 'store'])->name('songs.store');
        Route::post('songs/import', [SongController::class, 'import'])->name('songs.import');
        Route::put('songs/order', [SongController::class, 'reorder'])->name('songs.reorder');
        Route::put('songs/{song}', [SongController::class, 'update'])->name('songs.update');
        Route::delete('songs/{song}', [SongController::class, 'destroy'])->name('songs.destroy');
    });
});

// Game management for organisers (starter kit login)
Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [GameController::class, 'index'])->name('dashboard');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
});

require __DIR__.'/settings.php';
