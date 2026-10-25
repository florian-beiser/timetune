<?php

namespace App\Services;

/**
 * Pure placement rules for a single timeline.
 *
 * A timeline is a list of years sorted ascending (old first). With n cards
 * there are n+1 gaps, numbered 0..n. Only the order matters: a placement is
 * correct when left neighbour <= year <= right neighbour, where a missing
 * neighbour is unbounded and equal years count as correct.
 */
class TimelineEvaluator
{
    /**
     * @param  list<int>  $years
     */
    public function isCorrect(array $years, int $position, int $year): bool
    {
        if ($position < 0 || $position > count($years)) {
            return false;
        }

        $left = $years[$position - 1] ?? null;
        $right = $years[$position] ?? null;

        return ($left === null || $left <= $year) && ($right === null || $year <= $right);
    }

    /**
     * The first gap in which the year would be correct.
     *
     * @param  list<int>  $years
     */
    public function correctPosition(array $years, int $year): int
    {
        foreach ($years as $index => $existing) {
            if ($year <= $existing) {
                return $index;
            }
        }

        return count($years);
    }

    /**
     * @param  list<int>  $years
     * @return list<int>
     */
    public function insert(array $years, int $year): array
    {
        array_splice($years, $this->correctPosition($years, $year), 0, [$year]);

        return $years;
    }
}
