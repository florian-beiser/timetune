<?php

namespace App\Services;

use App\Enums\GameState;
use App\Enums\SongRole;
use App\Models\Game;
use App\Models\Player;
use App\Models\Song;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;

/**
 * Page props for the screen (public) and the moderator view (with solution).
 */
class GameView
{
    public function __construct(private GameFlow $flow) {}

    /**
     * Public: no solution of an open or closed round.
     *
     * @return array<string, mixed>
     */
    public function screen(Game $game): array
    {
        $standings = new Standings($game);
        $round = $game->currentRound();
        $isFinished = $game->state === GameState::Finished;
        $joinUrl = route('play.show', $game->code);
        $top = $standings->top(10, final: $isFinished);

        return [
            'game' => ['id' => $game->id, 'code' => $game->code, 'name' => $game->name],
            'branding' => $game->branding(),
            'state' => $this->flow->publicState($game),
            'join_url' => $joinUrl,
            'join_url_short' => (string) preg_replace('#^https?://#', '', $joinUrl),
            'qr_svg' => $game->state === GameState::Lobby ? $this->qrSvg($joinUrl) : null,
            'total_players' => $standings->playerCount(),
            'submitted' => $round && $round->skipped_at === null ? $round->placements()->count() : 0,
            'reveal' => $game->state === GameState::Revealed ? $this->flow->revealSummary($round) : null,
            'top10' => $top,
            'podium' => $isFinished ? array_slice($top, 0, 3) : [],
        ];
    }

    /**
     * Moderator: includes the current song with its solution.
     *
     * @return array<string, mixed>
     */
    public function host(Game $game): array
    {
        $standings = new Standings($game);
        $round = $game->currentRound();
        $songs = $game->songs()->get();
        $rounds = $game->rounds()->get()->keyBy('number');
        $roundSongs = $songs->where('role', SongRole::Round)->values();

        return [
            ...$this->screen($game),
            'host_url' => route('host.show', ['game' => $game->id, 'key' => $game->admin_key]),
            'screen_url' => route('screen.show', $game->id),
            'options' => [
                'bonus_question' => $game->bonusQuestionEnabled(),
                'countdown_seconds' => $game->countdownSeconds(),
            ],
            'next_state' => $game->state === GameState::Finished ? null : $this->flow->naturalNextState($game)->value,
            'has_next_round' => $this->flow->hasNextRound($game),
            'has_tiebreaker_song' => $game->tiebreakerSong() !== null,
            'tie_in_top_three' => $standings->hasTieInTopThree(),
            'current_song' => $round ? $this->songRow($round->song) : null,
            'round_opened_at' => $round?->opened_at?->toIso8601String(),
            'songs' => [
                'round' => $roundSongs->map(function (Song $song, int $index) use ($rounds) {
                    $round = $rounds->get($index + 1);

                    return [
                        ...$this->songRow($song),
                        'status' => match (true) {
                            $round === null => null,
                            $round->skipped_at !== null => 'skipped',
                            $round->revealed_at !== null => 'revealed',
                            default => 'running',
                        },
                        'percent_correct' => $round?->revealed_at && ! $round->skipped_at ? $this->flow->percentCorrect($round) : null,
                    ];
                })->all(),
                'start_pool' => $songs->where('role', SongRole::StartPool)->map($this->songRow(...))->values()->all(),
                'tiebreaker' => $songs->where('role', SongRole::Tiebreaker)->map($this->songRow(...))->values()->all(),
            ],
            'players' => $this->players($game, $standings),
            'rounds_played' => $game->rounds()->whereNull('skipped_at')->count(),
            'inactive_after_seconds' => config()->integer('timetune.inactive_after_seconds'),
        ];
    }

    /**
     * All players with their activity, newest first.
     *
     * @return list<array{id: int, name: string, is_hidden: bool, score: int, submissions: int, joined_at: string, last_seen_at: string|null}>
     */
    private function players(Game $game, Standings $standings): array
    {
        $submissions = DB::table('placements')
            ->join('rounds', 'rounds.id', '=', 'placements.round_id')
            ->where('rounds.game_id', $game->id)
            ->whereNull('rounds.skipped_at')
            ->groupBy('placements.player_id')
            ->pluck(DB::raw('count(*)'), 'placements.player_id');

        return array_values($game->players()->orderByDesc('joined_at')->orderByDesc('id')->get()
            ->map(fn (Player $player) => [
                'id' => $player->id,
                'name' => $player->name,
                'is_hidden' => $player->is_hidden,
                'score' => $standings->score($player->id),
                'submissions' => (int) ($submissions[$player->id] ?? 0),
                'joined_at' => $player->joined_at->toIso8601String(),
                'last_seen_at' => $player->last_seen_at?->toIso8601String(),
            ])->all());
    }

    /**
     * @return array{id: int, title: string, artist: string, year: int, image: array{sm: string, lg: string}|null, role: string}
     */
    private function songRow(Song $song): array
    {
        return [...$song->toCard(), 'role' => $song->role->value];
    }

    private function qrSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(400, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
