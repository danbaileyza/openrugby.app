<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchOfficial;
use App\Models\MatchTeam;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportWikiOfficialsCommand extends Command
{
    protected $signature = 'rugby:import-wiki-officials
                            {--path= : Custom path to wiki_match_officials.json}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import match officials scraped from Wikipedia tournament pages';

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/wiki_match_officials.json');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            $this->line('Run: python3 scripts/wiki_referee_scraper.py');
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! $data || empty($data['matches'])) {
            $this->error('Empty or invalid JSON.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->info('DRY RUN mode.');
        }

        $refereesByName = $data['referees'] ?? [];
        $matchesFound = 0;
        $matchesUnresolved = 0;
        $officialsCreated = 0;
        $refereesCreated = 0;
        $refereesUpdated = 0;

        foreach ($data['matches'] as $entry) {
            $competitionCode = $entry['competition_code'];
            $competition = Competition::where('code', $competitionCode)->first();
            if (! $competition) {
                continue;
            }

            $match = $this->findMatch($competition, $entry);
            if (! $match) {
                $matchesUnresolved++;
                $this->line("  <comment>Unresolved:</comment> {$entry['date']} {$entry['team1']} vs {$entry['team2']}");
                continue;
            }
            $matchesFound++;

            // Resolve or create referee
            $parts = preg_split('/\s+/', trim($entry['referee']), 2);
            if (count($parts) < 2) {
                continue;
            }
            [$firstName, $lastName] = $parts;

            $referee = Referee::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();

            if (! $referee) {
                $referee = Referee::whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])->first();
            }

            if (! $referee && ! $dryRun) {
                $referee = Referee::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nationality' => $entry['referee_nationality'] ?? null,
                    'external_source' => 'wikipedia',
                ]);
                $refereesCreated++;
            }

            if ($referee && ! empty($entry['referee_nationality']) && empty($referee->nationality)) {
                if (! $dryRun) {
                    $referee->update(['nationality' => $entry['referee_nationality']]);
                }
                $refereesUpdated++;
            }

            if (! $referee) {
                continue;
            }

            // Check if match already has a referee assigned
            $exists = MatchOfficial::where('match_id', $match->id)
                ->where('role', 'referee')
                ->exists();

            if ($exists) {
                continue;
            }

            if (! $dryRun) {
                MatchOfficial::create([
                    'match_id' => $match->id,
                    'referee_id' => $referee->id,
                    'role' => 'referee',
                ]);
            }
            $officialsCreated++;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Match entries in JSON', count($data['matches'])],
            ['Matches resolved to DB', $matchesFound],
            ['Matches unresolved', $matchesUnresolved],
            ['Officials created', $officialsCreated],
            ['Referees created', $refereesCreated],
            ['Referees updated (nationality)', $refereesUpdated],
        ]);

        return self::SUCCESS;
    }

    private function findMatch(Competition $competition, array $entry): ?RugbyMatch
    {
        $date = $entry['date'];
        if (! $date) {
            return null;
        }

        $dateCarbon = Carbon::parse($date);
        $seasonIds = $competition->seasons()->pluck('id');

        // Find matches on that date (+/- 1 day) in this competition
        $candidates = RugbyMatch::whereIn('season_id', $seasonIds)
            ->whereBetween('kickoff', [
                $dateCarbon->copy()->subDay()->startOfDay(),
                $dateCarbon->copy()->addDay()->endOfDay(),
            ])
            ->with('matchTeams.team')
            ->get();

        $team1 = $this->resolveTeam($entry['team1']);
        $team2 = $this->resolveTeam($entry['team2']);

        if (! $team1 || ! $team2) {
            return null;
        }

        foreach ($candidates as $match) {
            $teamIds = $match->matchTeams->pluck('team_id')->filter()->toArray();
            if (in_array($team1->id, $teamIds) && in_array($team2->id, $teamIds)) {
                return $match;
            }
        }

        return null;
    }

    private function resolveTeam(string $name): ?Team
    {
        // Exact match first
        $team = Team::where('name', $name)->first();
        if ($team) return $team;

        // Try with trailing whitespace / special chars stripped
        $clean = trim(preg_replace('/\s+/', ' ', $name));
        $team = Team::whereRaw('LOWER(name) = ?', [strtolower($clean)])->first();
        if ($team) return $team;

        // Normalise common suffixes: "ASM Clermont Auvergne" vs "Clermont Auvergne"
        // and aliases like "Toulon" → "RC Toulonnais"
        $aliases = [
            'toulon' => 'RC Toulonnais',
            'racing' => 'Racing 92',
            'bordeaux bègles' => 'Bordeaux Begles',
            'bordeaux begles' => 'Bordeaux Begles',
            'la rochelle' => 'La Rochelle 7s',  // ESPN stored it as "7s" quirk
            'stade toulousain' => 'Stade Toulousain',
            'toulouse' => 'Stade Toulousain',
            'clermont' => 'Clermont Auvergne',
            'montpellier' => 'Montpellier Herault',
            'castres' => 'Castres Olympique',
            'brive' => 'CA Brive',
            'oyonnax' => 'Oyonnax',
            'stade français' => 'Stade Francais',
            'stade francais' => 'Stade Francais',
            'paris' => 'Stade Francais',
        ];

        $lower = strtolower($clean);
        if (isset($aliases[$lower])) {
            $team = Team::where('name', $aliases[$lower])->first();
            if ($team) return $team;
        }

        // Partial match (stored name contains input OR input contains stored name)
        $team = Team::where('name', 'like', "%{$clean}%")->first();
        if ($team) return $team;

        // Try input containing stored name (e.g. "Bordeaux Bègles" → "Begles")
        foreach (Team::pluck('name', 'id') as $id => $teamName) {
            if (stripos($clean, $teamName) !== false || stripos($teamName, $clean) !== false) {
                return Team::find($id);
            }
        }

        return null;
    }
}
