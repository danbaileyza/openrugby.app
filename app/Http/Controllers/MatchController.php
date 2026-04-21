<?php

namespace App\Http\Controllers;

use App\Models\RugbyMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RugbyMatch::with(['season.competition', 'matchTeams.team', 'venue']);

        if ($request->has('season_id')) {
            $query->where('season_id', $request->season_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date')) {
            $query->whereDate('kickoff', $request->date);
        }

        return response()->json(
            $query->orderByDesc('kickoff')->paginate(20)
        );
    }

    public function show(string $id): JsonResponse
    {
        $match = RugbyMatch::with([
            'season.competition',
            'venue',
            'matchTeams.team',
            'events.player',
            'lineups.player',
            'officials.referee',
            'matchStats',
        ])->findOrFail($id);

        return response()->json($match);
    }

    public function events(string $id): JsonResponse
    {
        $match = RugbyMatch::findOrFail($id);

        return response()->json(
            $match->events()->with(['player', 'team'])->orderBy('minute')->get()
        );
    }

    public function lineups(string $id): JsonResponse
    {
        $match = RugbyMatch::findOrFail($id);

        return response()->json(
            $match->lineups()->with(['player', 'team'])->orderBy('jersey_number')->get()
        );
    }

    public function stats(string $id): JsonResponse
    {
        $match = RugbyMatch::findOrFail($id);

        $teamStats = $match->matchStats()->with('team')->get()->groupBy('team_id');
        $playerStats = $match->playerMatchStats()->with('player')->get()->groupBy('player_id');

        return response()->json([
            'team_stats' => $teamStats,
            'player_stats' => $playerStats,
        ]);
    }
}
