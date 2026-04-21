<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchOfficial;
use App\Models\Referee;
use App\Models\RugbyMatch;
use Illuminate\Console\Command;

class ImportSuperRugbyOfficialsCommand extends Command
{
    protected $signature = 'rugby:import-super-rugby-officials
                            {--path= : Custom path to super_rugby_match_officials.json}
                            {--season=2025-26 : Target season label}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import Super Rugby officials scraped from super.rugby match pages';

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/super_rugby_match_officials.json');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! $data) {
            $this->error('Empty or invalid JSON.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $seasonLabel = $this->option('season');

        $competition = Competition::where('code', 'super_rugby')->first();
        if (! $competition) {
            $this->error('Super Rugby competition not found.');
            return self::FAILURE;
        }

        $season = $competition->seasons()->where('label', $seasonLabel)->first();
        if (! $season) {
            $this->error("Season not found: {$seasonLabel}");
            return self::FAILURE;
        }

        // Load all Super Rugby matches for matching
        $matches = RugbyMatch::where('season_id', $season->id)
            ->with('matchTeams.team')
            ->get();

        $this->info("Matching " . count($data) . " scraped entries against {$matches->count()} DB matches...");

        $matched = 0;
        $unmatched = 0;
        $officialsCreated = 0;
        $refereesCreated = 0;

        foreach ($data as $entry) {
            $referee = trim((string) ($entry['referee'] ?? ''));
            if ($referee === '') {
                continue;
            }

            $match = $this->findMatch($matches, $entry);
            if (! $match) {
                $unmatched++;
                $round = $entry['actual_round'] ?? $entry['round'] ?? '?';
                $this->line("  <comment>Unmatched:</comment> R{$round} — {$referee}");
                continue;
            }
            $matched++;

            // Resolve or create referee
            $parts = preg_split('/\s+/', $referee, 2);
            if (count($parts) < 2) {
                continue;
            }
            [$firstName, $lastName] = $parts;

            $refereeModel = Referee::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();

            if (! $refereeModel) {
                $refereeModel = Referee::whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])->first();
            }

            if (! $refereeModel && ! $dryRun) {
                $refereeModel = Referee::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'external_source' => 'super_rugby',
                ]);
                $refereesCreated++;
            }

            if (! $refereeModel) {
                continue;
            }

            $exists = MatchOfficial::where('match_id', $match->id)
                ->where('role', 'referee')
                ->exists();

            if ($exists) {
                continue;
            }

            if (! $dryRun) {
                MatchOfficial::create([
                    'match_id' => $match->id,
                    'referee_id' => $refereeModel->id,
                    'role' => 'referee',
                ]);
            }
            $officialsCreated++;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Scraped entries', count($data)],
            ['Matched to DB', $matched],
            ['Unmatched', $unmatched],
            ['Officials created', $officialsCreated],
            ['New referees', $refereesCreated],
        ]);

        return self::SUCCESS;
    }

    /**
     * Match a scraped entry to a DB match by round number.
     * Super Rugby has 1 match per referee per round, so round alone isn't unique —
     * but we also have the teams in the URL title; for now we match by round + the
     * scraped match_id stored against external_id.
     */
    private function findMatch($matches, array $entry): ?RugbyMatch
    {
        $round = $entry['actual_round'] ?? $entry['round'] ?? null;
        $date = $entry['date'] ?? null;
        $fullScore = $entry['full_score'] ?? null;
        $htScore = $entry['ht_score'] ?? null;
        $scrapedTeams = $entry['teams'] ?? null;

        $candidates = $matches;

        // Filter by date (within ±2 calendar days to handle weekend rounds + timezones)
        if ($date) {
            $target = \Carbon\Carbon::parse($date)->startOfDay();
            $candidates = $matches->filter(function ($m) use ($target) {
                if (! $m->kickoff) return false;
                $diff = $m->kickoff->copy()->startOfDay()->diffInDays($target, false);
                return abs($diff) <= 2;
            });
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // PRIMARY: match by scraped team names
        if ($scrapedTeams && isset($scrapedTeams['home'], $scrapedTeams['away'])) {
            foreach ($candidates as $match) {
                $home = $match->matchTeams->firstWhere('side', 'home');
                $away = $match->matchTeams->firstWhere('side', 'away');
                if (! $home?->team || ! $away?->team) {
                    continue;
                }

                if ($this->teamNameMatches($home->team->name, $scrapedTeams['home'])
                    && $this->teamNameMatches($away->team->name, $scrapedTeams['away'])) {
                    return $match;
                }
                // Also try flipped
                if ($this->teamNameMatches($home->team->name, $scrapedTeams['away'])
                    && $this->teamNameMatches($away->team->name, $scrapedTeams['home'])) {
                    return $match;
                }
            }
        }

        // SECONDARY: match by full score
        if ($fullScore && isset($fullScore['home'], $fullScore['away'])) {
            foreach ($candidates as $match) {
                $home = $match->matchTeams->firstWhere('side', 'home');
                $away = $match->matchTeams->firstWhere('side', 'away');
                if ($home?->score === $fullScore['home'] && $away?->score === $fullScore['away']) {
                    return $match;
                }
                if ($home?->score === $fullScore['away'] && $away?->score === $fullScore['home']) {
                    return $match;
                }
            }
        }

        // HT score fallback
        if ($htScore && isset($htScore['home'], $htScore['away'])) {
            foreach ($candidates as $match) {
                $home = $match->matchTeams->firstWhere('side', 'home');
                $away = $match->matchTeams->firstWhere('side', 'away');
                if ($home?->ht_score === $htScore['home'] && $away?->ht_score === $htScore['away']) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Fuzzy team-name match. Handles "Waratahs" vs "NSW Waratahs",
     * "ACT Brumbies" vs "Brumbies", "Drua" vs "Fijian Drua", etc.
     */
    private function teamNameMatches(string $stored, string $incoming): bool
    {
        $norm = fn (string $v) => strtolower(trim(preg_replace('/\s+/', ' ',
            preg_replace('/\b(rugby|rugby club|club|rc|fc|nsw|act|queensland|qld|western|pasifika)\b/i', '', $v)
        )));

        $s = $norm($stored);
        $i = $norm($incoming);

        if ($s === '' || $i === '') return false;

        return $s === $i
            || str_contains($s, $i)
            || str_contains($i, $s);
    }
}
