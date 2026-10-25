<?php

namespace App\Http\Controllers\Host;

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Exceptions\GameFlowException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Song list of a game. Songs can only be changed while the game is in the lobby.
 */
class SongController extends Controller
{
    public function store(Request $request, Game $game): JsonResponse
    {
        $this->ensureEditable($game);
        $validated = $request->validate($this->rules());

        $song = $game->songs()->create([
            ...$validated,
            'sort_order' => (int) $game->songs()->max('sort_order') + 1,
        ]);

        return response()->json($song->toCard(), 201);
    }

    public function update(Request $request, Game $game, int $song): JsonResponse
    {
        $this->ensureEditable($game);
        $model = $game->songs()->findOrFail($song);
        $model->update($request->validate($this->rules()));

        return response()->json($model->toCard());
    }

    public function destroy(Game $game, int $song, ImageStore $images): JsonResponse
    {
        $this->ensureEditable($game);
        $model = $game->songs()->findOrFail($song);

        if ($model->role === SongRole::StartPool && $game->players()->where('start_song_id', $model->id)->exists()) {
            throw new GameFlowException('Diese Startkarte wurde bereits vergeben.');
        }

        $images->delete($model->imageDirectory(), $model->image, 'song');
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * New order of the songs, e.g. after drag & drop.
     */
    public function reorder(Request $request, Game $game): JsonResponse
    {
        $this->ensureEditable($game);
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('songs', 'id')->where('game_id', $game->id)],
        ]);

        DB::transaction(function () use ($game, $validated): void {
            foreach (array_values($validated['ids']) as $index => $id) {
                $game->songs()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return response()->json(['ids' => $validated['ids']]);
    }

    /**
     * CSV import with the columns `titel;interpret;jahr;rolle`. A header row
     * is skipped; comma-separated files are accepted as well.
     */
    public function import(Request $request, Game $game, ImageStore $images): JsonResponse
    {
        $this->ensureEditable($game);
        $request->validate([
            'file' => ['required', 'file', 'max:1024'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        $rows = $this->parseCsv((string) file_get_contents($request->file('file')->getRealPath()));

        DB::transaction(function () use ($request, $game, $rows, $images): void {
            if ($request->boolean('replace')) {
                $replaced = $game->songs()->whereNotIn('id', $game->players()->select('start_song_id'))->get();
                foreach ($replaced as $song) {
                    $images->delete($song->imageDirectory(), $song->image, 'song');
                }
                $game->songs()->whereKey($replaced->modelKeys())->delete();
            }

            $order = (int) $game->songs()->max('sort_order');
            foreach ($rows as $row) {
                $game->songs()->create([...$row, 'sort_order' => ++$order]);
            }
        });

        return response()->json(['imported' => count($rows)], 201);
    }

    /**
     * @return list<array{title: string, artist: string, year: int, role: SongRole}>
     */
    private function parseCsv(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $content) ?: [], fn (string $line) => trim($line) !== ''));
        $delimiter = isset($lines[0]) && substr_count($lines[0], ';') === 0 && substr_count($lines[0], ',') >= 3 ? ',' : ';';

        $rows = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $columns = array_map(fn (?string $column) => trim($column ?? ''), str_getcsv($line, $delimiter, '"', ''));

            if ($index === 0 && mb_strtolower($columns[0]) === 'titel') {
                continue;
            }

            if (count($columns) < 4) {
                $errors[] = 'Zeile '.($index + 1).': '.$line;

                continue;
            }

            [$title, $artist, $yearColumn, $roleColumn] = $columns;
            $role = SongRole::fromImport($roleColumn);
            $year = filter_var($yearColumn, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 2100]]);

            if ($title === '' || $artist === '' || $year === false || $role === null) {
                $errors[] = 'Zeile '.($index + 1).': '.$line;

                continue;
            }

            $rows[] = ['title' => mb_substr($title, 0, 255), 'artist' => mb_substr($artist, 0, 255), 'year' => $year, 'role' => $role];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => 'Ungültige Zeilen (erwartet: titel;interpret;jahr;rolle) – '.implode(' | ', array_slice($errors, 0, 5)),
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Die Datei enthält keine Songs.']);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1000', 'max:2100'],
            'role' => ['required', Rule::enum(SongRole::class)],
        ];
    }

    private function ensureEditable(Game $game): void
    {
        if ($game->state !== GameState::Lobby) {
            throw new GameFlowException('Songs können nur vor dem Spielstart geändert werden.');
        }
    }
}
