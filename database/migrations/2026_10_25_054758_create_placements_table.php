<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamp('submitted_at');
            $table->boolean('is_correct')->nullable();

            $table->unique(['round_id', 'player_id']);
            $table->index(['player_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placements');
    }
};
