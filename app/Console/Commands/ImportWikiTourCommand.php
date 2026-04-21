<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchOfficial;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportWikiTourCommand extends Command
{
    protected $signature = 'rugby:import-wiki-tour
                            {--path= : JSON file from wiki_tour_scraper.py}
                            {--dry-run}';

    protected $description = 'Import a tour scraped from Wikipedia (matches + referees + venues)';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error("File required: --path=/path/to/wiki_tour_*.json");
            return self::FAILURE;
        }
        $data = json_decode(file_get_contents($path), true);

        $compCode = $data['competition'];
        $seasonLabel = (string) $data['season'];
        $competition = Competition::where('code', $compCode)->first();
        if (! $competition) {
            $this->error("Competition not found: {$compCode}");
            return self::FAILURE;
        }

        $season = $competition->seasons()->firstOrCreate(
            ['label' => $seasonLabel],
            ['external_source' => 'wikipedia', 'start_date' => '2000-01-01', 'end_date' => '2000-12-31']
        );

        $dryRun = (bool) $this->option('dry-run');
        $this->info("Importing {$compCode} {$seasonLabel}: " . count($data['matches']) . ' matches');

        $matchesCreated = 0;
        $officialsCreated = 0;
        $teamsCreated = 0;
        $refereesCreated = 0;

        foreach ($data['matches'] as $entry) {
            $home = $this->resolveOrCreateTeam($entry['home'], $teamsCreated, $dryRun);
            $away = $this->resolveOrCreateTeam($entry['away'], $teamsCreated, $dryRun);
            if (! $home || ! $away) continue;

            $date = Carbon::parse($entry['date']);

            // Find existing match by date + teams
            $match = RugbyMatch::whereHas('matchTeams', fn ($q) => $q->where('team_id', $home->id))
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $away->id))
                ->whereBetween('kickoff', [$date->copy()->subDay(), $date->copy()->addDay()])
                ->first();

            if (! $match) {
                $status = $entry['home_score'] !== null ? 'ft' : 'scheduled';
                if (! $dryRun) {
                    $match = RugbyMatch::create([
                        'season_id' => $season->id,
                        'kickoff' => $date,
                        'status' => $status,
                        'external_source' => 'wikipedia',
                    ]);
                    MatchTeam::create([
                        'match_id' => $match->id,
                        'team_id' => $home->id,
                        'side' => 'home',
                        'score' => $entry['home_score'],
                        'is_winner' => $entry['home_score'] !== null && $entry['home_score'] > $entry['away_score'],
                    ]);
                    MatchTeam::create([
                        'match_id' => $match->id,
                        'team_id' => $away->id,
                        'side' => 'away',
                        'score' => $entry['away_score'],
                        'is_winner' => $entry['away_score'] !== null && $entry['away_score'] > $entry['home_score'],
                    ]);
                }
                $matchesCreated++;
            } else {
                // Make sure match is in the correct season
                if (! $dryRun && $match->season_id !== $season->id) {
                    $match->update(['season_id' => $season->id]);
                }
            }

            // Events (home + away)
            if ($match && (! empty($entry['home_events']) || ! empty($entry['away_events']))) {
                foreach ([['home', $home, $entry['home_events'] ?? []], ['away', $away, $entry['away_events'] ?? []]] as [$side, $team, $events]) {
                    foreach ($events as $ev) {
                        $player = $this->resolvePlayer($ev['player'] ?? null);
                        $type = match ($ev['type']) {
                            'try' => 'try',
                            'conversion' => 'conversion',
                            'penalty' => 'penalty_goal',
                            'drop_goal' => 'drop_goal',
                            default => null,
                        };
                        if (! $type) continue;

                        $exists = MatchEvent::where('match_id', $match->id)
                            ->where('team_id', $team->id)
                            ->where('type', $type)
                            ->where('minute', $ev['minute'])
                            ->where('player_id', $player?->id)
                            ->exists();
                        if ($exists) continue;

                        if (! $dryRun) {
                            MatchEvent::create([
                                'match_id' => $match->id,
                                'team_id' => $team->id,
                                'player_id' => $player?->id,
                                'type' => $type,
                                'minute' => $ev['minute'] ?? 0,
                            ]);
                        }
                    }
                }
            }

            // Referee
            if (! empty($entry['referee']) && $match) {
                $parts = preg_split('/\s+/', trim($entry['referee']), 2);
                if (count($parts) >= 2) {
                    [$first, $last] = $parts;
                    $ref = Referee::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($first), strtolower($last)])->first();
                    if (! $ref) {
                        $ref = Referee::whereRaw('LOWER(last_name) = ?', [strtolower($last)])->first();
                    }
                    if (! $ref && ! $dryRun) {
                        $ref = Referee::create([
                            'first_name' => $first,
                            'last_name' => $last,
                            'external_source' => 'wikipedia',
                        ]);
                        $refereesCreated++;
                    }
                    if ($ref && ! $dryRun) {
                        $exists = MatchOfficial::where('match_id', $match->id)->where('role', 'referee')->exists();
                        if (! $exists) {
                            MatchOfficial::create([
                                'match_id' => $match->id,
                                'referee_id' => $ref->id,
                                'role' => 'referee',
                            ]);
                            $officialsCreated++;
                        }
                    }
                }
            }
        }

        // Update season dates
        if (! $dryRun && ! $season->matches()->count() === 0) {
            $first = $season->matches()->min('kickoff');
            $last = $season->matches()->max('kickoff');
            if ($first) $season->update(['start_date' => substr($first, 0, 10), 'end_date' => substr($last, 0, 10)]);
        }

        $this->table(['Metric', 'Count'], [
            ['Matches created', $matchesCreated],
            ['Officials created', $officialsCreated],
            ['Teams created', $teamsCreated],
            ['Referees created', $refereesCreated],
        ]);
        return self::SUCCESS;
    }

    private function resolvePlayer(?string $name): ?Player
    {
        if (! $name) return null;
        $name = trim($name);
        // Wikipedia often gives just surname (e.g. "Adams"). Try both full and partial.
        if (str_contains($name, ' ')) {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $p = Player::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($first), strtolower($last)])->first();
            if ($p) return $p;
        }
        // Surname-only
        $players = Player::whereRaw('LOWER(last_name) = ?', [strtolower($name)])->limit(2)->get();
        if ($players->count() === 1) return $players->first();
        return null;
    }

    private function resolveOrCreateTeam(string $name, int &$teamsCreated, bool $dryRun): ?Team
    {
        $name = trim($name);
        $name = preg_replace('/^\s*\d+px\s+/', '', $name);  // strip leading "23px " markup
        $name = preg_replace('/\s*\(\d+\s*BP\)\s*/i', '', $name);  // strip "(1 BP)" bonus point markers
        $name = trim($name);
        if ($name === '') return null;

        // Direct match
        $team = Team::where('name', $name)->first();
        if ($team) return $team;

        // Aliases/variants
        $aliases = [
            'British & Irish Lions' => 'British and Irish Lions',
            'NZL' => 'New Zealand',
            'RSA' => 'South Africa',
            'AUS' => 'Australia',
            'ARG' => 'Argentina',
            'New South Wales Waratahs' => 'Waratahs',
            'ACT Brumbies' => 'Brumbies',
            'Queensland Reds' => 'Reds',
        ];
        foreach ($aliases as $from => $to) {
            if (stripos($name, $from) !== false) {
                $team = Team::where('name', $to)->first();
                if ($team) return $team;
            }
        }

        // Partial match
        $team = Team::where('name', 'like', "%{$name}%")->first();
        if ($team) return $team;

        if ($dryRun) return null;

        $team = Team::create([
            'name' => $name,
            'country' => 'International',
            'type' => str_contains($name, 'Lions') ? 'invitational' : 'club',
            'external_source' => 'wikipedia',
        ]);
        $teamsCreated++;
        return $team;
    }
}
