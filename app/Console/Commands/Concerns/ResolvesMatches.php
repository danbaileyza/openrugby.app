<?php

namespace App\Console\Commands\Concerns;

use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Cross-source match deduplication.
 *
 * Provides a shared method for all import commands to find existing matches
 * regardless of which source originally created them. This prevents the same
 * real-world match appearing multiple times when imported from ESPN, URC,
 * all.rugby, etc.
 */
trait ResolvesMatches
{
    /**
     * Find an existing match by date and team names, across ALL sources.
     *
     * Looks for matches within ±1 day of the given date where both teams
     * match by name. Returns the first match found, regardless of which
     * external_source it was imported from.
     */
    protected function findExistingMatchByTeamsAndDate(
        Carbon $kickoff,
        string $homeTeamName,
        string $awayTeamName
    ): ?RugbyMatch {
        if (! $homeTeamName || ! $awayTeamName) {
            return null;
        }

        // Find matches on the same date (± 1 day for timezone differences)
        $candidates = RugbyMatch::whereBetween('kickoff', [
                $kickoff->copy()->subDay()->startOfDay(),
                $kickoff->copy()->addDay()->endOfDay(),
            ])
            ->with('matchTeams.team')
            ->get();

        foreach ($candidates as $candidate) {
            $homeTeam = $candidate->matchTeams->firstWhere('side', 'home')?->team;
            $awayTeam = $candidate->matchTeams->firstWhere('side', 'away')?->team;

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            if ($this->teamNameFuzzyMatch($homeTeam, $homeTeamName)
                && $this->teamNameFuzzyMatch($awayTeam, $awayTeamName)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check if a Team model matches a given name string.
     *
     * Handles sponsor prefixes (Vodacom, DHL, Hollywoodbets, etc.),
     * partial matches, and short name comparisons.
     */
    protected function teamNameFuzzyMatch(Team $team, string $searchName): bool
    {
        $teamName = Str::lower($team->name);
        $teamShort = Str::lower($team->short_name ?? '');
        $search = Str::lower(trim($searchName));

        // Direct contains (either direction)
        if (Str::contains($teamName, $search) || Str::contains($search, $teamName)) {
            return true;
        }

        // Short name match
        if ($teamShort && (Str::contains($search, $teamShort) || Str::contains($teamShort, $search))) {
            return true;
        }

        // Strip sponsor prefixes and compare core names
        $coreSearch = $this->stripSponsorPrefix($search);
        $coreTeam = $this->stripSponsorPrefix($teamName);

        if ($coreSearch !== $search || $coreTeam !== $teamName) {
            if (Str::contains($coreTeam, $coreSearch) || Str::contains($coreSearch, $coreTeam)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip common sponsor prefixes from team names.
     */
    protected function stripSponsorPrefix(string $name): string
    {
        return preg_replace(
            '/^(vodacom|dhl|hollywoodbets|fidelity\s+securedrive|toyota|cell\s+c)\s+/i',
            '',
            trim($name)
        );
    }
}
