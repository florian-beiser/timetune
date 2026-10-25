<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class RoundRevealed implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array{title: string, artist: string, year: int, image: array{sm: string, lg: string}|null}  $song
     */
    public function __construct(public int $gameId, public int $roundNumber, public array $song, public int $percentCorrect) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('game.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return 'RoundRevealed';
    }

    /**
     * @return array{round_number: int, song: array{title: string, artist: string, year: int, image: array{sm: string, lg: string}|null}, percent_correct: int}
     */
    public function broadcastWith(): array
    {
        return ['round_number' => $this->roundNumber, 'song' => $this->song, 'percent_correct' => $this->percentCorrect];
    }
}
