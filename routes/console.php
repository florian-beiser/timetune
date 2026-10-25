<?php

use App\Models\Player;
use Illuminate\Support\Facades\Schedule;

// DSGVO: player data is deleted 30 days after the game has finished.
Schedule::command('model:prune', ['--model' => [Player::class]])->daily();
