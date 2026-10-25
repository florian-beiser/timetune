<?php

namespace App\Enums;

enum GameState: string
{
    case Lobby = 'LOBBY';
    case RoundOpen = 'ROUND_OPEN';
    case RoundClosed = 'ROUND_CLOSED';
    case Revealed = 'REVEALED';
    case Tiebreaker = 'TIEBREAKER';
    case Finished = 'FINISHED';

    /**
     * Whether the state graph allows moving from this state to the given one.
     *
     * Skipping a round leaves ROUND_OPEN/ROUND_CLOSED either for the next round
     * or, after the last round, for REVEALED. Resetting the game to LOBBY is
     * handled separately and is always possible.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::Lobby => [self::RoundOpen],
            self::RoundOpen => [self::RoundClosed, self::RoundOpen, self::Revealed],
            self::RoundClosed => [self::Revealed, self::RoundOpen],
            self::Revealed => [self::RoundOpen, self::Tiebreaker, self::Finished],
            self::Tiebreaker => [self::Finished],
            self::Finished => [],
        }, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Lobby => 'Lobby',
            self::RoundOpen => 'Runde offen',
            self::RoundClosed => 'Geschlossen',
            self::Revealed => 'Aufgelöst',
            self::Tiebreaker => 'Tiebreaker',
            self::Finished => 'Beendet',
        };
    }
}
