<?php

namespace App\Enums;

enum SongRole: string
{
    case Round = 'ROUND';
    case StartPool = 'START_POOL';
    case Tiebreaker = 'TIEBREAKER';

    /**
     * Parse the role column of a CSV import (German or English spelling).
     */
    public static function fromImport(string $value): ?self
    {
        return match (mb_strtolower(trim($value))) {
            'round', 'runde', 'runden', 'rundensong' => self::Round,
            'start_pool', 'start', 'startkarte', 'startpool', 'pool' => self::StartPool,
            'tiebreaker', 'tiebreak', 'stechen' => self::Tiebreaker,
            default => null,
        };
    }
}
