<?php

namespace App\Models;

use App\Enums\SongRole;
use App\Services\ImageStore;
use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $title
 * @property string $artist
 * @property int $year
 * @property SongRole $role
 * @property int $sort_order
 * @property string|null $image
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'artist', 'year', 'role', 'sort_order', 'image'])]
class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The revealed face of the card, as shown to players. Never call this for
     * the song of an open or closed round outside the moderator view.
     *
     * @return array{id: int, title: string, artist: string, year: int, image: array{sm: string, lg: string}|null}
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'year' => $this->year,
            'image' => $this->imageUrls(),
        ];
    }

    /**
     * @return array{sm: string, lg: string}|null
     */
    public function imageUrls(): ?array
    {
        if ($this->image === null) {
            return null;
        }

        $images = app(ImageStore::class);
        $directory = $this->imageDirectory();

        return [
            'sm' => $images->url($directory, $this->image, 400),
            'lg' => $images->url($directory, $this->image, 1200),
        ];
    }

    public function imageDirectory(): string
    {
        return 'games/'.$this->game_id.'/songs';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => SongRole::class,
            'year' => 'integer',
        ];
    }
}
