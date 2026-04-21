<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompetitionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\StandingController;
use App\Http\Controllers\ChatController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically.
|
*/

Route::name('api.')->group(function () {
    // Competitions & seasons
    Route::apiResource('competitions', CompetitionController::class)->only(['index', 'show']);
    Route::get('competitions/{competition}/seasons', [CompetitionController::class, 'seasons']);

    // Teams
    Route::apiResource('teams', TeamController::class)->only(['index', 'show']);
    Route::get('teams/{team}/players', [TeamController::class, 'players']);
    Route::get('teams/{team}/matches', [TeamController::class, 'matches']);

    // Players
    Route::apiResource('players', PlayerController::class)->only(['index', 'show']);
    Route::get('players/{player}/stats', [PlayerController::class, 'stats']);

    // Matches
    Route::apiResource('matches', MatchController::class)->only(['index', 'show']);
    Route::get('matches/{match}/events', [MatchController::class, 'events']);
    Route::get('matches/{match}/lineups', [MatchController::class, 'lineups']);
    Route::get('matches/{match}/stats', [MatchController::class, 'stats']);

    // Standings
    Route::get('seasons/{season}/standings', [StandingController::class, 'index']);

    // RAG-powered chat endpoint
    Route::post('chat', [ChatController::class, 'ask'])->middleware('throttle:chat-api');
});
