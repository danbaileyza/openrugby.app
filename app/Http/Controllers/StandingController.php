<?php

namespace App\Http\Controllers;

use App\Models\Season;
use Illuminate\Http\JsonResponse;

class StandingController extends Controller
{
    public function index(string $seasonId): JsonResponse
    {
        $season = Season::with('competition')->findOrFail($seasonId);

        $standings = $season->standings()
            ->with('team')
            ->orderBy('pool')
            ->orderBy('position')
            ->get();

        return response()->json([
            'competition' => $season->competition->name,
            'season' => $season->label,
            'standings' => $standings,
        ]);
    }
}
