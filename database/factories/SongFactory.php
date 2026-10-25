<?php

namespace Database\Factories;

use App\Enums\SongRole;
use App\Models\Game;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'title' => fake()->unique()->words(3, true),
            'artist' => fake()->unique()->name(),
            'year' => fake()->numberBetween(1960, 2025),
            'role' => SongRole::Round,
            'sort_order' => fake()->numberBetween(1, 1000),
        ];
    }

    public function startPool(): static
    {
        return $this->state(['role' => SongRole::StartPool]);
    }

    public function tiebreaker(): static
    {
        return $this->state(['role' => SongRole::Tiebreaker]);
    }
}
