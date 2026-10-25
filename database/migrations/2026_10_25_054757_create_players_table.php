<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('session_token', 64)->unique();
            $table->foreignId('start_song_id')->constrained('songs')->cascadeOnDelete();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('joined_at');
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['game_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
