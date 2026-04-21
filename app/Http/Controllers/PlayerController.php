<?php

namespace App\Http\Controllers;

use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Player::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%");
            });
        }

        if ($request->has('position')) {
            $query->where('position', $request->position);
        }

        if ($request->has('nationality')) {
            $query->where('nationality', $request->nationality);
        }

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('last_name')->paginate(25));
    }

    public function show(Player $player): JsonResponse
    {
        $player->load(['contracts.team', 'seasonStats.season.competition']);

        return response()->json($player);
    }

    public function stats(Player $player): JsonResponse
    {
        $stats = $player->seasonStats()
            ->with('season.competition')
            ->get()
            ->groupBy(fn ($s) => $s->season->label);

        return response()->json($stats);
    }
}
