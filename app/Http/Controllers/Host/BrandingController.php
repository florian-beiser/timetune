<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logo of the game and photos of the songs. Images can be changed at any
 * time; the photo of a song is only sent to players once it is revealed.
 */
class BrandingController extends Controller
{
    public function __construct(private ImageStore $images) {}

    public function storeLogo(Request $request, Game $game): JsonResponse
    {
        $request->validate($this->rules(4096), $this->messages());

        $previous = $game->logo;
        $game->update(['logo' => $this->images->store($request->file('file'), $game->imageDirectory(), 'logo')]);
        $this->images->delete($game->imageDirectory(), $previous, 'logo');

        return response()->json($game->branding(), 201);
    }

    public function destroyLogo(Game $game): JsonResponse
    {
        $this->images->delete($game->imageDirectory(), $game->logo, 'logo');
        $game->update(['logo' => null]);

        return response()->json($game->branding());
    }

    public function storeSongImage(Request $request, Game $game, int $song): JsonResponse
    {
        $model = $game->songs()->findOrFail($song);
        $request->validate($this->rules(10240), $this->messages());

        $previous = $model->image;
        $model->update(['image' => $this->images->store($request->file('file'), $model->imageDirectory(), 'song')]);
        $this->images->delete($model->imageDirectory(), $previous, 'song');

        return response()->json($model->toCard(), 201);
    }

    public function destroySongImage(Game $game, int $song): JsonResponse
    {
        $model = $game->songs()->findOrFail($song);
        $this->images->delete($model->imageDirectory(), $model->image, 'song');
        $model->update(['image' => null]);

        return response()->json($model->toCard());
    }

    /**
     * SVG is not accepted: it could carry scripts and is served from our origin.
     *
     * @return array<string, list<string>>
     */
    private function rules(int $maxKilobytes): array
    {
        return ['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKilobytes]];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'file.mimes' => 'Erlaubt sind JPG, PNG und WebP.',
            'file.max' => 'Die Datei ist zu groß (höchstens :max KB).',
        ];
    }
}
