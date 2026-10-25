<?php

use App\Services\TimelineEvaluator;

dataset('beispiel-durchlauf', [
    // Spec section 7: [timeline before, chosen gap, song year, expected result, timeline after]
    'R1 Anna: Wonderwall' => [[1982], 1, 1995, true, [1982, 1995]],
    'R1 Markus: Wonderwall' => [[2008], 1, 1995, false, [2008]],
    'R2 Anna: Billie Jean' => [[1982, 1995], 1, 1983, true, [1982, 1983, 1995]],
    'R2 Markus: Billie Jean' => [[2008], 0, 1983, true, [1983, 2008]],
    'R3 Anna: Rolling in the Deep' => [[1982, 1983, 1995], 3, 2010, true, [1982, 1983, 1995, 2010]],
    'R3 Markus: Rolling in the Deep' => [[1983, 2008], 1, 2010, false, [1983, 2008]],
    'R4 Anna: Bohemian Rhapsody' => [[1982, 1983, 1995, 2010], 1, 1975, false, [1982, 1983, 1995, 2010]],
    'R4 Markus: Bohemian Rhapsody' => [[1983, 2008], 0, 1975, true, [1975, 1983, 2008]],
]);

it('evaluates the example run from the specification', function (array $before, int $position, int $year, bool $expected, array $after) {
    $evaluator = new TimelineEvaluator;

    $isCorrect = $evaluator->isCorrect($before, $position, $year);

    expect($isCorrect)->toBe($expected)
        ->and($isCorrect ? $evaluator->insert($before, $year) : $before)->toBe($after);
})->with('beispiel-durchlauf');

it('accepts a year equal to a neighbour on either side', function (int $position) {
    expect((new TimelineEvaluator)->isCorrect([1980, 1990, 2000], $position, 1990))->toBeTrue();
})->with([1, 2]);

it('treats missing neighbours as unbounded', function () {
    $evaluator = new TimelineEvaluator;

    expect($evaluator->isCorrect([], 0, 1950))->toBeTrue()
        ->and($evaluator->isCorrect([1990], 0, 1800))->toBeTrue()
        ->and($evaluator->isCorrect([1990], 1, 2100))->toBeTrue();
});

it('rejects positions outside the n+1 gaps', function (int $position) {
    expect((new TimelineEvaluator)->isCorrect([1980, 1990], $position, 1985))->toBeFalse();
})->with([-1, 3]);

it('finds the first gap where a song belongs', function () {
    $evaluator = new TimelineEvaluator;

    expect($evaluator->correctPosition([1982, 1983, 1995, 2010], 1975))->toBe(0)
        ->and($evaluator->correctPosition([1983, 2008], 2010))->toBe(2)
        ->and($evaluator->correctPosition([1980, 1990, 1990, 2000], 1990))->toBe(1);
});
