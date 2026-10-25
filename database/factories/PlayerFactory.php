<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Player;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'name' => fake()->unique()->firstName(),
            'session_token' => Str::random(48),
            'start_song_id' => fn (array $attributes) => Song::factory()->startPool()->create(['game_id' => $attributes['game_id']])->id,
            'is_hidden' => false,
            'joined_at' => now(),
        ];
    }
}
