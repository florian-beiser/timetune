<?php

namespace Database\Factories;

use App\Enums\GameState;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Game::generateCode(),
            'name' => 'Konzert '.fake()->year(),
            'admin_key' => Game::generateAdminKey(),
            'state' => GameState::Lobby,
            'current_round' => 0,
            'options' => ['bonus_question' => false, 'countdown_seconds' => null],
        ];
    }
}
