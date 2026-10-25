<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $round_id
 * @property int $player_id
 * @property int $choice
 * @property bool|null $is_correct
 */
#[Fillable(['round_id', 'player_id', 'choice', 'is_correct'])]
class BonusAnswer extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'choice' => 'integer',
            'is_correct' => 'boolean',
        ];
    }
}
