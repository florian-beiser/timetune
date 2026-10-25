<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GameView;
use Inertia\Inertia;
use Inertia\Response;

class HostController extends Controller
{
    public function show(Game $game, GameView $view): Response
    {
        return Inertia::render('host/Show', $view->host($game));
    }
}
