<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $song_id
 * @property int $number
 * @property list<string>|null $bonus_options
 * @property int|null $bonus_correct_index
 * @property Carbon|null $opened_at
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $revealed_at
 * @property Carbon|null $skipped_at
 */
#[Fillable(['song_id', 'number', 'bonus_options', 'bonus_correct_index', 'opened_at', 'closes_at', 'closed_at', 'revealed_at', 'skipped_at'])]
#[Hidden(['song_id', 'bonus_correct_index'])]
class Round extends Model
{
    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<Song, $this>
     */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /**
     * @return HasMany<BonusAnswer, $this>
     */
    public function bonusAnswers(): HasMany
    {
        return $this->hasMany(BonusAnswer::class);
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null && $this->closed_at === null && $this->skipped_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bonus_options' => 'array',
            'opened_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
            'revealed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }
}
