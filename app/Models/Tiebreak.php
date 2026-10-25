<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $player_id
 * @property int $guessed_year
 * @property Carbon $submitted_at
 */
#[Fillable(['game_id', 'player_id', 'guessed_year', 'submitted_at'])]
class Tiebreak extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guessed_year' => 'integer',
            'submitted_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }
}
