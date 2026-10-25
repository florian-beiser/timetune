<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('logo', 64)->nullable()->after('options');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->string('image', 64)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('logo');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
