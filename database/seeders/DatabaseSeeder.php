<?php

namespace Database\Seeders;

use App\Enums\SongRole;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the example run from the specification: 4 round songs,
     * 2 start cards and 1 tiebreaker song, plus an organiser login.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Moderation',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $game = Game::query()->create([
            'code' => 'K7M2QX',
            'name' => 'Herbstkonzert 2026',
            'admin_key' => 'demo-admin-key-bitte-aendern',
            'options' => ['bonus_question' => false, 'countdown_seconds' => null],
        ]);

        $songs = [
            ['Wonderwall', 'Oasis', 1995, SongRole::Round],
            ['Billie Jean', 'Michael Jackson', 1983, SongRole::Round],
            ['Rolling in the Deep', 'Adele', 2010, SongRole::Round],
            ['Bohemian Rhapsody', 'Queen', 1975, SongRole::Round],
            ['Africa', 'Toto', 1982, SongRole::StartPool],
            ['Viva la Vida', 'Coldplay', 2008, SongRole::StartPool],
            ['The Final Countdown', 'Europe', 1986, SongRole::Tiebreaker],
        ];

        foreach ($songs as $index => [$title, $artist, $year, $role]) {
            $game->songs()->create([
                'title' => $title,
                'artist' => $artist,
                'year' => $year,
                'role' => $role,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
