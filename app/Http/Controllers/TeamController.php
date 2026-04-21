<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\RugbyMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Team::query();

        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderBy('name')->paginate(25));
    }

    public function show(Team $team): JsonResponse
    {
        $team->load(['standings' => fn ($q) => $q->latest()]);

        return response()->json($team);
    }

    public function players(Team $team): JsonResponse
    {
        $players = $team->currentPlayers()->with('player')->get()
            ->pluck('player');

        return response()->json($players);
    }

    public function matches(Team $team): JsonResponse
    {
        $matches = RugbyMatch::query()
            ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $team->id))
            ->with(['season.competition', 'matchTeams.team', 'venue'])
            ->orderByDesc('kickoff')
            ->paginate(20);

        return response()->json($matches);
    }
}
