<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GameView;
use Inertia\Inertia;
use Inertia\Response;

class ScreenController extends Controller
{
    public function show(Game $game, GameView $view): Response
    {
        return Inertia::render('screen/Show', $view->screen($game));
    }
}
