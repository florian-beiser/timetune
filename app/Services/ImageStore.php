<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores uploaded images as downscaled WebP files on the public disk.
 *
 * Hundreds of phones load a song photo at the same moment, so originals are
 * never served: every image is stored in a few fixed widths under a random
 * name (which also reveals nothing about the song).
 */
class ImageStore
{
    /**
     * Widths per kind: small for phones, large for the beamer.
     *
     * @var array<string, list<positive-int>>
     */
    public const SIZES = [
        'logo' => [480],
        'song' => [400, 1200],
    ];

    /**
     * @param  'logo'|'song'  $kind
     * @return string Basename of the stored image
     */
    public function store(UploadedFile $file, string $directory, string $kind): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['file' => 'Das Bild konnte nicht gelesen werden.']);
        }

        $source = $this->applyExifOrientation($source, $file);
        $name = Str::random(24);

        foreach (self::SIZES[$kind] as $width) {
            Storage::disk('public')->put($this->path($directory, $name, $width), $this->encode($source, $width));
        }

        return $name;
    }

    /**
     * @param  'logo'|'song'  $kind
     */
    public function delete(string $directory, ?string $name, string $kind): void
    {
        if ($name === null) {
            return;
        }

        foreach (self::SIZES[$kind] as $width) {
            Storage::disk('public')->delete($this->path($directory, $name, $width));
        }
    }

    /**
     * Root-relative, because PlayerResult payloads are built in queue workers
     * that do not know the domain the phones use.
     */
    public function url(string $directory, string $name, int $width): string
    {
        return '/storage/'.$this->path($directory, $name, $width);
    }

    private function path(string $directory, string $name, int $width): string
    {
        return "{$directory}/{$name}-{$width}.webp";
    }

    /**
     * @param  positive-int  $maxWidth
     */
    private function encode(GdImage $source, int $maxWidth): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $image = $source;

        if ($width > $maxWidth) {
            $targetHeight = max(1, (int) round($height * $maxWidth / $width));
            $image = imagecreatetruecolor($maxWidth, $targetHeight);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagecopyresampled($image, $source, 0, 0, 0, 0, $maxWidth, $targetHeight, $width, $height);
        } else {
            imagesavealpha($image, true);
        }

        ob_start();
        imagewebp($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * Phone photos are often stored sideways with an EXIF rotation flag.
     */
    private function applyExifOrientation(GdImage $image, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
