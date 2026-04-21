<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\RugbyMatch;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillTourEventPlayersCommand extends Command
{
    protected $signature = 'rugby:backfill-tour-event-players
                            {--path= : wiki_tour_*.json file}
                            {--loose : Also try global unique-surname lookup when lineup has no match}
                            {--dry-run}';

    protected $description = 'Re-attribute null-player events on tour matches using scraped lineups';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error("File required: --path=/path/to/wiki_tour_*.json");
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $data = json_decode(file_get_contents($path), true);

        $competition = Competition::where('code', $data['competition'])->first();
        $season = $competition?->seasons()->where('label', (string) $data['season'])->first();
        if (! $season) {
            $this->error("Season not found: {$data['competition']} {$data['season']}");
            return self::FAILURE;
        }

        $updated = 0;
        $stillMissing = 0;
        $ambiguous = 0;

        foreach ($data['matches'] as $entry) {
            $date = Carbon::parse($entry['date']);
            $match = RugbyMatch::where('season_id', $season->id)
                ->whereBetween('kickoff', [$date->copy()->subDay(), $date->copy()->addDay()])
                ->with(['matchTeams.team', 'lineups.player'])
                ->first();
            if (! $match) continue;

            // Map team slug (home/away from scraper) to our team via matchTeams
            $home = $match->matchTeams->firstWhere('side', 'home')?->team;
            $away = $match->matchTeams->firstWhere('side', 'away')?->team;
            if (! $home || ! $away) continue;

            foreach ([['home', $home, $entry['home_events'] ?? []], ['away', $away, $entry['away_events'] ?? []]] as [$side, $team, $events]) {
                // Build surname index for this team's lineup in this match
                $lineup = $match->lineups->where('team_id', $team->id);
                $bySurname = $lineup->groupBy(fn ($l) => strtolower($l->player?->last_name ?? ''));

                foreach ($events as $ev) {
                    $type = match ($ev['type'] ?? null) {
                        'try' => 'try',
                        'conversion' => 'conversion',
                        'penalty' => 'penalty_goal',
                        'drop_goal' => 'drop_goal',
                        default => null,
                    };
                    if (! $type) continue;

                    $raw = trim((string) ($ev['player'] ?? ''));
                    if ($raw === '') continue;

                    // Penalty tries have no player attribution — leave null, it's correct.
                    if (strcasecmp($raw, 'penalty try') === 0) continue;

                    // Find matching event row with null player_id
                    $row = MatchEvent::where('match_id', $match->id)
                        ->where('team_id', $team->id)
                        ->where('type', $type)
                        ->where('minute', $ev['minute'] ?? 0)
                        ->whereNull('player_id')
                        ->first();
                    if (! $row) continue;

                    // Parse "F. Smith" style — initial + surname
                    $initial = null;
                    if (preg_match('/^([A-Z])\.\s+(.+)$/u', $raw, $m)) {
                        $initial = strtolower($m[1]);
                        $surname = strtolower($m[2]);
                    } else {
                        // "van der Merwe" / "van der Flier" — treat full lowercased string as surname
                        $surname = strtolower($raw);
                    }

                    $candidates = $bySurname->get($surname) ?? collect();

                    // Fallback: startsWith/endsWith match (hyphenated surnames)
                    if ($candidates->isEmpty()) {
                        $candidates = $lineup->filter(function ($l) use ($surname) {
                            $ln = strtolower($l->player?->last_name ?? '');
                            return $ln !== '' && (str_starts_with($ln, $surname) || str_ends_with($ln, $surname));
                        });
                    }

                    // Disambiguate by initial when provided
                    if ($initial && $candidates->count() > 1) {
                        $filtered = $candidates->filter(function ($l) use ($initial) {
                            $fn = strtolower($l->player?->first_name ?? '');
                            return $fn !== '' && str_starts_with($fn, $initial);
                        });
                        if ($filtered->count() >= 1) $candidates = $filtered;
                    }

                    if ($candidates->count() >= 1) {
                        $player = $candidates->first()->player;
                        if ($player) {
                            if (! $dryRun) $row->update(['player_id' => $player->id]);
                            $updated++;
                            if ($candidates->count() > 1) $ambiguous++;
                            continue;
                        }
                    }

                    // Loose fallback: scope to players with any contract to this team.
                    $loose = (bool) $this->option('loose');
                    if ($loose) {
                        $q = Player::whereRaw('LOWER(last_name) = ?', [$surname])
                            ->whereHas('contracts', fn ($c) => $c->where('team_id', $team->id));
                        if ($initial) {
                            $q->whereRaw('LOWER(first_name) LIKE ?', [$initial.'%']);
                        }
                        $players = $q->limit(2)->get();
                        if ($players->count() === 1) {
                            if (! $dryRun) $row->update(['player_id' => $players->first()->id]);
                            $updated++;
                            continue;
                        }

                        // Widen: contract to team that shares nationality/country with this team (e.g., SA A → SA players).
                        if ($players->isEmpty() && $team->country) {
                            $nationalTeams = \App\Models\Team::where('country', $team->country)
                                ->where('type', 'national')->pluck('id');
                            $q = Player::whereRaw('LOWER(last_name) = ?', [$surname])
                                ->whereHas('contracts', fn ($c) => $c->whereIn('team_id', $nationalTeams));
                            if ($initial) $q->whereRaw('LOWER(first_name) LIKE ?', [$initial.'%']);
                            $players = $q->limit(2)->get();
                            if ($players->count() === 1) {
                                if (! $dryRun) $row->update(['player_id' => $players->first()->id]);
                                $updated++;
                                continue;
                            }
                        }

                        // Last resort: globally unique surname (with initial disambiguation).
                        $q = Player::whereRaw('LOWER(last_name) = ?', [$surname]);
                        if ($initial) $q->whereRaw('LOWER(first_name) LIKE ?', [$initial.'%']);
                        $players = $q->limit(2)->get();
                        if ($players->count() === 1) {
                            if (! $dryRun) $row->update(['player_id' => $players->first()->id]);
                            $updated++;
                            continue;
                        }
                    }

                    $stillMissing++;
                    $this->line("  ? {$match->kickoff->format('Y-m-d')} {$team->name} {$type}@{$ev['minute']} — '{$raw}'");
                }
            }
        }

        $this->table(['Metric', 'Count'], [
            ['events resolved', $updated],
            ['ambiguous (>1 surname match)', $ambiguous],
            ['still unresolved', $stillMissing],
        ]);
        return self::SUCCESS;
    }
}
