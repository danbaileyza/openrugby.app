<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchOfficial;
use App\Models\Referee;
use App\Models\RugbyMatch;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportTop14OfficialsCommand extends Command
{
    protected $signature = 'rugby:import-top14-officials
                            {--path= : Custom JSON path}
                            {--season=2025-26 : Target season label}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import Top 14 officials scraped from top14.lnr.fr';

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/top14_match_officials.json');
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! $data) {
            $this->error('Empty JSON.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $seasonLabel = $this->option('season');

        $competition = Competition::where('code', 'top14')->first();
        if (! $competition) {
            $this->error('Top 14 competition not found.');
            return self::FAILURE;
        }

        // Find all top14 season match candidates — we'll match by date + teams
        $matches = RugbyMatch::whereIn('season_id', $competition->seasons()->pluck('id'))
            ->with('matchTeams.team')
            ->get();

        $this->info('Matching ' . count($data) . ' scraped entries against ' . $matches->count() . ' DB matches...');

        $matched = 0;
        $unmatched = 0;
        $officialsCreated = 0;
        $refereesCreated = 0;

        foreach ($data as $entry) {
            $referee = trim((string) ($entry['referee'] ?? ''));
            if ($referee === '') continue;

            $match = $this->findMatch($matches, $entry);
            if (! $match) {
                $unmatched++;
                continue;
            }
            $matched++;

            $parts = preg_split('/\s+/', $referee, 2);
            if (count($parts) < 2) continue;
            [$firstName, $lastName] = $parts;

            $refereeModel = Referee::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($firstName), strtolower($lastName)])->first();

            if (! $refereeModel) {
                $refereeModel = Referee::whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])->first();
            }

            if (! $refereeModel && ! $dryRun) {
                $refereeModel = Referee::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nationality' => 'France',
                    'external_source' => 'lnr',
                ]);
                $refereesCreated++;
            }

            if (! $refereeModel) continue;

            $exists = MatchOfficial::where('match_id', $match->id)
                ->where('role', 'referee')
                ->exists();
            if ($exists) continue;

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

    private function findMatch($matches, array $entry): ?RugbyMatch
    {
        $date = $entry['date'] ?? null;
        $scrapedTeams = $entry['teams'] ?? null;
        $fullScore = $entry['full_score'] ?? null;
        $round = $entry['round'] ?? null;

        $candidates = $matches;
        if ($date) {
            $dateCarbon = Carbon::parse($date);
            $candidates = $matches->filter(fn ($m) => $m->kickoff && abs($m->kickoff->diffInDays($dateCarbon, false)) <= 1);
        }

        if ($candidates->isEmpty()) return null;
        if ($candidates->count() === 1) return $candidates->first();

        if ($scrapedTeams && isset($scrapedTeams['home'], $scrapedTeams['away'])) {
            foreach ($candidates as $m) {
                $h = $m->matchTeams->firstWhere('side', 'home');
                $a = $m->matchTeams->firstWhere('side', 'away');
                if (! $h?->team || ! $a?->team) continue;

                if ($this->teamNameMatches($h->team->name, $scrapedTeams['home'])
                    && $this->teamNameMatches($a->team->name, $scrapedTeams['away'])) {
                    return $m;
                }
                if ($this->teamNameMatches($h->team->name, $scrapedTeams['away'])
                    && $this->teamNameMatches($a->team->name, $scrapedTeams['home'])) {
                    return $m;
                }
            }
        }

        if ($fullScore && isset($fullScore['home'], $fullScore['away'])) {
            foreach ($candidates as $m) {
                $h = $m->matchTeams->firstWhere('side', 'home');
                $a = $m->matchTeams->firstWhere('side', 'away');
                if ($h?->score === $fullScore['home'] && $a?->score === $fullScore['away']) return $m;
                if ($h?->score === $fullScore['away'] && $a?->score === $fullScore['home']) return $m;
            }
        }

        return null;
    }

    private function teamNameMatches(string $stored, string $incoming): bool
    {
        $norm = fn (string $v) => strtolower(trim(preg_replace('/\s+/', ' ',
            preg_replace('/\b(rugby|union|club|stade|rc|fc|racing|cs|ca|asm|us|asb)\b/i', '', $v)
        )));

        $s = $norm($stored);
        $i = $norm($incoming);

        if ($s === '' || $i === '') return false;

        return $s === $i || str_contains($s, $i) || str_contains($i, $s);
    }
}
