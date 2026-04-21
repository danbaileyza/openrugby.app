<?php

namespace App\Services\Rugby\Rag;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchOfficial;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\RagDocument;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;

/**
 * Generates natural language documents from structured rugby data.
 * These documents are optimised for RAG retrieval by an AI bot.
 *
 * Each document type produces a self-contained text that answers
 * common questions without needing cross-references.
 */
class DocumentGenerator
{
    /**
     * Generate a match summary document for RAG.
     */
    public function generateMatchSummary(RugbyMatch $match): ?RagDocument
    {
        $match->load([
            'season.competition',
            'venue',
            'matchTeams.team',
            'events.player',
            'lineups.player',
            'officials.referee',
            'matchStats',
        ]);

        $home = $match->matchTeams->firstWhere('side', 'home');
        $away = $match->matchTeams->firstWhere('side', 'away');
        $competition = $match->season->competition;

        // Skip matches missing either team (unresolved ESPN fixtures etc.)
        if (! $home?->team || ! $away?->team) {
            return null;
        }

        $lines = [];
        $lines[] = "# Match: {$home->team->name} vs {$away->team->name}";
        $lines[] = "Competition: {$competition->name} ({$competition->format})";
        $lines[] = "Season: {$match->season->label}, Round {$match->round}";
        $lines[] = "Date: {$match->kickoff->format('l, j F Y')} at {$match->kickoff->format('H:i')}";
        $lines[] = 'Venue: '.($match->venue?->name ?? 'Unknown').', '.($match->venue?->city ?? '');
        $lines[] = 'Attendance: '.($match->attendance ? number_format($match->attendance) : 'Not recorded');
        $lines[] = '';

        // Score
        $lines[] = '## Final Score';
        $lines[] = "{$home->team->name} {$home->score} - {$away->score} {$away->team->name}";
        $lines[] = "Half-time: {$home->ht_score} - {$away->ht_score}";
        if ($home->score !== null && $away->score !== null) {
            if ($home->score > $away->score) {
                $margin = $home->score - $away->score;
                $lines[] = "{$home->team->name} won by {$margin} points.";
            } elseif ($away->score > $home->score) {
                $margin = $away->score - $home->score;
                $lines[] = "{$away->team->name} won by {$margin} points.";
            } else {
                $lines[] = 'The match ended in a draw.';
            }
        }
        $lines[] = '';

        // Scoring breakdown
        $lines[] = '## Scoring';
        foreach (['home', 'away'] as $side) {
            $mt = $match->matchTeams->firstWhere('side', $side);
            $lines[] = "{$mt->team->name}: {$mt->tries}T {$mt->conversions}C {$mt->penalties_kicked}P {$mt->drop_goals}DG";
        }
        $lines[] = '';

        // Try scorers
        $tries = $match->events->where('type', 'try')->sortBy('minute');
        if ($tries->isNotEmpty()) {
            $lines[] = '## Try Scorers';
            foreach ($tries as $event) {
                $playerName = $event->player ? "{$event->player->first_name} {$event->player->last_name}" : 'Unknown';
                $teamName = $event->team->name ?? '';
                $lines[] = "- {$playerName} ({$teamName}) {$event->minute}'";
            }
            $lines[] = '';
        }

        // Cards
        $cards = $match->events->whereIn('type', ['yellow_card', 'red_card'])->sortBy('minute');
        if ($cards->isNotEmpty()) {
            $lines[] = '## Disciplinary';
            foreach ($cards as $event) {
                $playerName = $event->player ? "{$event->player->first_name} {$event->player->last_name}" : 'Unknown';
                $cardType = $event->type === 'yellow_card' ? 'Yellow card' : 'Red card';
                $reason = $event->meta['reason'] ?? '';
                $lines[] = "- {$cardType}: {$playerName} ({$event->minute}') {$reason}";
            }
            $lines[] = '';
        }

        // Officials
        if ($match->officials->isNotEmpty()) {
            $lines[] = '## Match Officials';
            foreach ($match->officials as $official) {
                $ref = $official->referee;
                $lines[] = "- {$official->role}: {$ref->first_name} {$ref->last_name} ({$ref->nationality})";
            }
            $lines[] = '';
        }

        // Key stats
        $stats = $match->matchStats->groupBy('team_id');
        if ($stats->isNotEmpty()) {
            $lines[] = '## Key Statistics';
            $statKeys = ['possession_pct', 'territory_pct', 'tackles_made', 'tackles_missed',
                'carries', 'metres_carried', 'clean_breaks', 'offloads',
                'scrums_won', 'lineouts_won', 'turnovers_won', 'penalties_conceded'];

            foreach ($statKeys as $key) {
                $homeStat = $match->matchStats->where('team_id', $home->team_id)->where('stat_key', $key)->first();
                $awayStat = $match->matchStats->where('team_id', $away->team_id)->where('stat_key', $key)->first();
                if ($homeStat && $awayStat) {
                    $label = str($key)->replace('_', ' ')->title();
                    $lines[] = "- {$label}: {$home->team->name} {$homeStat->stat_value} - {$awayStat->stat_value} {$away->team->name}";
                }
            }
            $lines[] = '';
        }

        $content = implode("\n", $lines);

        return RagDocument::updateOrCreate(
            [
                'documentable_type' => RugbyMatch::class,
                'documentable_id' => $match->id,
                'source_type' => 'match_summary',
            ],
            [
                'content' => $content,
                'metadata' => [
                    'competition' => $competition->name,
                    'season' => $match->season->label,
                    'teams' => [$home->team->name, $away->team->name],
                    'date' => $match->kickoff->toDateString(),
                    'venue' => $match->venue?->name,
                ],
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Generate a player profile document for RAG.
     */
    public function generatePlayerProfile(Player $player): RagDocument
    {
        $player->load([
            'contracts.team',
            'seasonStats.season.competition',
        ]);

        $lines = [];
        $lines[] = "# Player: {$player->first_name} {$player->last_name}";
        $lines[] = "Position: {$player->position}".($player->position_group ? " ({$player->position_group})" : '');
        $lines[] = "Nationality: {$player->nationality}";
        if ($player->dob) {
            $lines[] = "Born: {$player->dob->format('j F Y')} (age {$player->dob->age})";
        }
        if ($player->height_cm) {
            $lines[] = "Height: {$player->height_cm}cm / Weight: {$player->weight_kg}kg";
        }
        $lines[] = '';

        // Career (teams)
        $lines[] = '## Career';
        foreach ($player->contracts->sortByDesc('from_date') as $contract) {
            $period = $contract->from_date->format('Y').' - '.($contract->to_date?->format('Y') ?? 'present');
            $lines[] = "- {$contract->team->name} ({$period})";
        }
        $lines[] = '';

        // Season stats
        $grouped = $player->seasonStats->groupBy('season_id');
        if ($grouped->isNotEmpty()) {
            $lines[] = '## Season Statistics';
            foreach ($grouped as $seasonId => $stats) {
                $season = $stats->first()->season;
                $lines[] = "### {$season->competition->name} {$season->label}";
                foreach ($stats as $stat) {
                    $label = str($stat->stat_key)->replace('_', ' ')->title();
                    $lines[] = "- {$label}: {$stat->stat_value}";
                }
                $lines[] = '';
            }
        }

        $content = implode("\n", $lines);

        return RagDocument::updateOrCreate(
            [
                'documentable_type' => Player::class,
                'documentable_id' => $player->id,
                'source_type' => 'player_profile',
            ],
            [
                'content' => $content,
                'metadata' => [
                    'name' => "{$player->first_name} {$player->last_name}",
                    'position' => $player->position,
                    'nationality' => $player->nationality,
                    'teams' => $player->contracts->pluck('team.name')->unique()->values()->toArray(),
                ],
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Generate a team season review document for RAG.
     */
    public function generateTeamSeasonReview(Team $team, Season $season): RagDocument
    {
        $standing = $team->standings()->where('season_id', $season->id)->first();
        $matches = RugbyMatch::whereHas('matchTeams', fn ($q) => $q->where('team_id', $team->id))
            ->where('season_id', $season->id)
            ->with(['matchTeams.team', 'events'])
            ->orderBy('kickoff')
            ->get();

        // Compute W/L/D from actual matches (reliable even without a standings row).
        $wonCount = 0;
        $lostCount = 0;
        $drawnCount = 0;
        foreach ($matches as $match) {
            $us = $match->matchTeams->firstWhere('team_id', $team->id);
            $them = $match->matchTeams->where('team_id', '!=', $team->id)->first();
            if (! $us || ! $them || $us->score === null || $them->score === null) {
                continue;
            }
            if ($us->score > $them->score) {
                $wonCount++;
            } elseif ($us->score < $them->score) {
                $lostCount++;
            } else {
                $drawnCount++;
            }
        }

        $lines = [];
        $lines[] = "# {$team->name} — {$season->competition->name} {$season->label}";
        $lines[] = '';

        // Natural-language summary — makes the doc indexable by "won/lost" queries
        $lines[] = '## Season Summary';
        $lines[] = "{$team->name} won {$wonCount}, lost {$lostCount}, drew {$drawnCount} matches in the {$season->competition->name} {$season->label} season.";
        $lines[] = '';

        if ($standing) {
            $lines[] = '## Final Standing';
            $lines[] = "Position: {$standing->position}";
            $lines[] = "P{$standing->played} W{$standing->won} D{$standing->drawn} L{$standing->lost}";
            $lines[] = "Points for: {$standing->points_for}, Points against: {$standing->points_against} (diff: {$standing->point_differential})";
            $lines[] = "Bonus points: {$standing->bonus_points}, Total: {$standing->total_points}";
            $lines[] = '';
        }

        $lines[] = '## Results';
        foreach ($matches as $match) {
            $us = $match->matchTeams->firstWhere('team_id', $team->id);
            $them = $match->matchTeams->where('team_id', '!=', $team->id)->first();
            if (! $us || ! $them) {
                continue;
            }

            $resultWord = match (true) {
                $us->score > $them->score => 'won',
                $us->score < $them->score => 'lost',
                default => 'drew',
            };
            $ha = $us->side === 'home' ? 'home' : 'away';
            $lines[] = "- Round {$match->round} ({$ha}): {$team->name} {$resultWord} {$us->score}-{$them->score} vs {$them->team->name}";
        }

        $content = implode("\n", $lines);

        return RagDocument::updateOrCreate(
            [
                'documentable_type' => Team::class,
                'documentable_id' => $team->id,
                'source_type' => "team_season_review:{$season->label}",
            ],
            [
                'content' => $content,
                'metadata' => [
                    'team' => $team->name,
                    'competition' => $season->competition->name,
                    'season' => $season->label,
                ],
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Generate a competition overview document for RAG.
     * Summarises the competition itself, its seasons, and current standings.
     */
    public function generateCompetitionOverview(Competition $competition): RagDocument
    {
        $competition->load(['seasons' => fn ($q) => $q->orderBy('start_date', 'desc')]);

        $lines = [];
        $lines[] = "# Competition: {$competition->name}";
        $details = [
            'Code: '.$competition->code,
            'Format: '.$competition->format,
            'Level: '.($competition->level ?? 'professional'),
        ];
        if ($competition->grade) {
            $details[] = 'Grade: '.$competition->grade;
        }
        if ($competition->country) {
            $details[] = 'Country: '.$competition->country;
        }
        if ($competition->tier) {
            $details[] = 'Tier: '.$competition->tier;
        }
        $lines[] = implode(' · ', $details);
        $lines[] = '';

        // Seasons summary
        $lines[] = '## Seasons';
        $seasons = $competition->seasons;
        if ($seasons->isEmpty()) {
            $lines[] = 'No seasons recorded yet.';
        } else {
            foreach ($seasons as $season) {
                $matchCount = RugbyMatch::where('season_id', $season->id)->count();
                $finishedCount = RugbyMatch::where('season_id', $season->id)->where('status', 'ft')->count();
                $tag = $season->is_current ? ' (current)' : '';
                $lines[] = "- {$season->label}{$tag}: {$season->start_date?->format('Y-m-d')} → {$season->end_date?->format('Y-m-d')}, {$finishedCount}/{$matchCount} matches played";
            }
        }
        $lines[] = '';

        // Current season detail: teams + leader
        $currentSeason = $seasons->firstWhere('is_current', true) ?? $seasons->first();
        if ($currentSeason) {
            $lines[] = "## Current Season: {$currentSeason->label}";

            $teamIds = MatchTeam::whereHas('match', fn ($q) => $q->where('season_id', $currentSeason->id))
                ->distinct()
                ->pluck('team_id');
            $teams = Team::whereIn('id', $teamIds)->orderBy('name')->get();
            $lines[] = 'Teams participating: '.$teams->pluck('name')->implode(', ');
            $lines[] = '';

            if ($competition->has_standings) {
                $standings = Standing::where('season_id', $currentSeason->id)
                    ->with('team')
                    ->orderBy('position')
                    ->limit(10)
                    ->get();
                if ($standings->isNotEmpty()) {
                    $lines[] = '### Top of the Table';
                    foreach ($standings as $s) {
                        $lines[] = "{$s->position}. {$s->team->name} — P{$s->played} W{$s->won} D{$s->drawn} L{$s->lost}, {$s->total_points} pts";
                    }
                    $lines[] = '';
                }
            }

            // Recent results (last 10 finished matches in current season)
            $recent = RugbyMatch::where('season_id', $currentSeason->id)
                ->where('status', 'ft')
                ->with('matchTeams.team')
                ->orderByDesc('kickoff')
                ->limit(10)
                ->get();
            if ($recent->isNotEmpty()) {
                $lines[] = '### Recent Results';
                foreach ($recent as $m) {
                    $home = $m->matchTeams->firstWhere('side', 'home');
                    $away = $m->matchTeams->firstWhere('side', 'away');
                    if (! $home?->team || ! $away?->team) {
                        continue;
                    }
                    $lines[] = "- {$m->kickoff->format('j M')}: {$home->team->name} {$home->score}-{$away->score} {$away->team->name}";
                }
                $lines[] = '';
            }
        }

        $content = implode("\n", $lines);

        return RagDocument::updateOrCreate(
            [
                'documentable_type' => Competition::class,
                'documentable_id' => $competition->id,
                'source_type' => 'competition_overview',
            ],
            [
                'content' => $content,
                'metadata' => [
                    'name' => $competition->name,
                    'code' => $competition->code,
                    'level' => $competition->level,
                    'grade' => $competition->grade,
                    'country' => $competition->country,
                ],
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Generate a referee profile document for RAG.
     */
    public function generateRefereeProfile(Referee $referee): RagDocument
    {
        $lines = [];
        $lines[] = "# Referee: {$referee->first_name} {$referee->last_name}";
        $bio = [];
        if ($referee->nationality) {
            $bio[] = 'Nationality: '.$referee->nationality;
        }
        if ($referee->tier) {
            $bio[] = 'Tier: '.str_replace('_', ' ', $referee->tier);
        }
        if (! empty($bio)) {
            $lines[] = implode(' · ', $bio);
        }
        $lines[] = '';

        // Career stats: matches officiated + role breakdown
        $officials = MatchOfficial::where('referee_id', $referee->id)
            ->with(['match.season.competition', 'match.matchTeams.team'])
            ->get();

        $totalMatches = $officials->pluck('match_id')->unique()->count();
        $roleCounts = $officials->groupBy('role')->map(fn ($g) => $g->count());

        $lines[] = '## Career Summary';
        $lines[] = "Total matches officiated: {$totalMatches}";
        if ($roleCounts->isNotEmpty()) {
            foreach ($roleCounts as $role => $count) {
                $label = str($role)->replace('_', ' ')->title();
                $lines[] = "- {$label}: {$count}";
            }
        }
        $lines[] = '';

        // Competitions worked
        $byCompetition = $officials
            ->map(fn ($o) => $o->match?->season?->competition?->name)
            ->filter()
            ->countBy()
            ->sortDesc();
        if ($byCompetition->isNotEmpty()) {
            $lines[] = '## Competitions';
            foreach ($byCompetition as $name => $count) {
                $lines[] = "- {$name}: {$count} appearances";
            }
            $lines[] = '';
        }

        // Discipline issued across their matches (cards given during matches they officiated)
        $matchIds = $officials->pluck('match_id')->unique();
        if ($matchIds->isNotEmpty()) {
            $cardCounts = MatchEvent::whereIn('match_id', $matchIds)
                ->whereIn('type', ['yellow_card', 'red_card'])
                ->get()
                ->groupBy('type')
                ->map(fn ($g) => $g->count());
            if ($cardCounts->isNotEmpty()) {
                $lines[] = '## Discipline Across Matches';
                $lines[] = 'Yellow cards: '.($cardCounts['yellow_card'] ?? 0);
                $lines[] = 'Red cards: '.($cardCounts['red_card'] ?? 0);
                $lines[] = '';
            }

            // Recent matches (last 10)
            $recentMatches = RugbyMatch::whereIn('id', $matchIds)
                ->with(['season.competition', 'matchTeams.team'])
                ->orderByDesc('kickoff')
                ->limit(10)
                ->get();
            if ($recentMatches->isNotEmpty()) {
                $lines[] = '## Recent Matches';
                foreach ($recentMatches as $m) {
                    $home = $m->matchTeams->firstWhere('side', 'home');
                    $away = $m->matchTeams->firstWhere('side', 'away');
                    if (! $home?->team || ! $away?->team) {
                        continue;
                    }
                    $score = ($home->score !== null && $away->score !== null) ? "{$home->score}-{$away->score}" : 'vs';
                    $lines[] = "- {$m->kickoff->format('j M Y')}: {$home->team->name} {$score} {$away->team->name} ({$m->season->competition->name})";
                }
                $lines[] = '';
            }
        }

        $content = implode("\n", $lines);

        return RagDocument::updateOrCreate(
            [
                'documentable_type' => Referee::class,
                'documentable_id' => $referee->id,
                'source_type' => 'referee_profile',
            ],
            [
                'content' => $content,
                'metadata' => [
                    'name' => trim("{$referee->first_name} {$referee->last_name}"),
                    'nationality' => $referee->nationality,
                    'tier' => $referee->tier,
                    'matches_officiated' => $totalMatches,
                ],
                'generated_at' => now(),
            ]
        );
    }
}
