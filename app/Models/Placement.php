<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $round_id
 * @property int $player_id
 * @property int $position
 * @property Carbon $submitted_at
 * @property bool|null $is_correct
 */
#[Fillable(['round_id', 'player_id', 'position', 'submitted_at', 'is_correct'])]
class Placement extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

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
            'position' => 'integer',
            'submitted_at' => 'datetime',
            'is_correct' => 'boolean',
        ];
    }
}
