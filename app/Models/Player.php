<?php

namespace App\Models;

use Database\Factories\PlayerFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $name
 * @property string $session_token
 * @property int $start_song_id
 * @property bool $is_hidden
 * @property Carbon $joined_at
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'session_token', 'start_song_id', 'is_hidden', 'joined_at', 'last_seen_at'])]
#[Hidden(['session_token'])]
class Player extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    use Prunable;

    public const COOKIE = 'timetune_player';

    public $timestamps = false;

    /**
     * Player data is deleted 30 days after the game has ended (DSGVO).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereHas(
            'game',
            fn (Builder $query) => $query->where('finished_at', '<=', now()->subDays(config()->integer('timetune.retention_days'))),
        );
    }

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
    public function startSong(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'start_song_id');
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /**
     * @return HasOne<Tiebreak, $this>
     */
    public function tiebreak(): HasOne
    {
        return $this->hasOne(Tiebreak::class);
    }

    /**
     * Name as shown on the screen and in rankings; hidden names are replaced.
     */
    public function displayName(): string
    {
        return $this->is_hidden ? 'Spieler '.$this->id : $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
