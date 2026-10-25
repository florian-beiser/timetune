<?php

namespace App\Models;

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Services\ImageStore;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $admin_key
 * @property GameState $state
 * @property int $current_round
 * @property array{bonus_question?: bool, countdown_seconds?: int|null, theme?: array{accent: string, background: string}|null, join_url?: string|null}|null $options
 * @property string|null $logo
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'admin_key', 'state', 'current_round', 'options', 'logo', 'finished_at'])]
#[Hidden(['admin_key'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * Characters for game codes, without easily confused ones (0/O, 1/I/L).
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    public static function generateAdminKey(): string
    {
        return Str::random(32);
    }

    /**
     * @return HasMany<Song, $this>
     */
    public function songs(): HasMany
    {
        return $this->hasMany(Song::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Song, $this>
     */
    public function roundSongs(): HasMany
    {
        return $this->songs()->where('role', SongRole::Round);
    }

    /**
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class)->orderBy('number');
    }

    /**
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * @return HasMany<Tiebreak, $this>
     */
    public function tiebreaks(): HasMany
    {
        return $this->hasMany(Tiebreak::class);
    }

    public function currentRound(): ?Round
    {
        if ($this->current_round < 1) {
            return null;
        }

        return $this->rounds()->where('number', $this->current_round)->first();
    }

    public function totalRounds(): int
    {
        return $this->roundSongs()->count();
    }

    public function tiebreakerSong(): ?Song
    {
        return $this->songs()->where('role', SongRole::Tiebreaker)->first();
    }

    public function bonusQuestionEnabled(): bool
    {
        return (bool) ($this->options['bonus_question'] ?? false);
    }

    public function countdownSeconds(): ?int
    {
        $seconds = $this->options['countdown_seconds'] ?? null;

        return $seconds ? (int) $seconds : null;
    }

    /**
     * Custom colours of the player and screen views; null means the default design.
     *
     * @return array{accent: string, background: string}|null
     */
    public function theme(): ?array
    {
        return $this->options['theme'] ?? null;
    }

    public function imageDirectory(): string
    {
        return 'games/'.$this->id;
    }

    /**
     * Logo and colours as sent to players, the screen and the moderator.
     *
     * @return array{logo_url: string|null, theme: array{accent: string, background: string}|null}
     */
    public function branding(): array
    {
        return [
            'logo_url' => $this->logo ? app(ImageStore::class)->url($this->imageDirectory(), $this->logo, 480) : null,
            'theme' => $this->theme(),
        ];
    }

    public function hasValidAdminKey(?string $key): bool
    {
        return is_string($key) && hash_equals($this->admin_key, $key);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => GameState::class,
            'options' => 'array',
            'finished_at' => 'datetime',
        ];
    }
}
