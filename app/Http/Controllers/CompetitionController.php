<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use Illuminate\Http\JsonResponse;

class CompetitionController extends Controller
{
    public function index(): JsonResponse
    {
        $competitions = Competition::with('seasons')
            ->orderBy('name')
            ->get();

        return response()->json($competitions);
    }

    public function show(Competition $competition): JsonResponse
    {
        $competition->load('seasons');

        return response()->json($competition);
    }

    public function seasons(Competition $competition): JsonResponse
    {
        return response()->json(
            $competition->seasons()->orderByDesc('start_date')->get()
        );
    }
}
