<?php

namespace App\Services\Rugby\Import\Sources;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Rugby\Import\BaseImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Imports rugby data from API-Sports Rugby API.
 * Free tier: 100 requests/day. Paid: from $10/month.
 *
 * Endpoints used:
 *   GET /leagues          → competitions + seasons
 *   GET /teams?league=X   → teams per competition
 *   GET /games?league=X&season=Y → matches + scores
 *
 * @see https://api-sports.io/documentation/rugby/v1
 */
class ApiSportsImporter extends BaseImporter
{
    private string $baseUrl = 'https://v1.rugby.api-sports.io';
    private string $apiKey;
    private int $requestsToday = 0;
    private int $dailyLimit = 100;

    public function __construct(
        private string $entityTypeOverride = 'matches',
        private ?int $leagueId = null,
        private ?int $seasonYear = null,
    ) {
        $this->apiKey = config('services.api_sports.key');
        $this->dailyLimit = config('services.api_sports.daily_limit', 100);
    }

    public function source(): string { return 'api_sports'; }
    public function entityType(): string { return $this->entityTypeOverride; }

    protected function fetch(): iterable
    {
        return match ($this->entityTypeOverride) {
            'competitions' => $this->fetchLeagues(),
            'teams'        => $this->fetchTeams(),
            'matches'      => $this->fetchMatches(),
            default        => [],
        };
    }

    protected function transform(array $raw): array
    {
        return match ($this->entityTypeOverride) {
            'competitions' => $this->transformLeague($raw),
            'teams'        => $this->transformTeam($raw),
            'matches'      => $this->transformMatch($raw),
            default        => $raw,
        };
    }

    protected function upsert(array $data): void
    {
        match ($this->entityTypeOverride) {
            'competitions' => $this->upsertCompetition($data),
            'teams'        => $this->upsertTeam($data),
            'matches'      => $this->upsertMatch($data),
            default        => null,
        };
    }

    // ─── Fetchers ────────────────────────────────────────────

    private function fetchLeagues(): iterable
    {
        $response = $this->apiGet('/leagues');
        return $response['response'] ?? [];
    }

    private function fetchTeams(): iterable
    {
        if (! $this->leagueId) return [];

        $params = ['league' => $this->leagueId];
        if ($this->seasonYear) {
            $params['season'] = $this->seasonYear;
        }

        $response = $this->apiGet('/teams', $params);
        return $response['response'] ?? [];
    }

    private function fetchMatches(): iterable
    {
        if (! $this->leagueId || ! $this->seasonYear) return [];

        $response = $this->apiGet('/games', [
            'league' => $this->leagueId,
            'season' => $this->seasonYear,
        ]);

        return $response['response'] ?? [];
    }

    // ─── Transformers ────────────────────────────────────────

    private function transformLeague(array $raw): array
    {
        $name = $raw['name'] ?? $raw['league']['name'] ?? 'Unknown';

        return [
            'name'            => $name,
            'code'            => Competition::canonicalCodeFromName($name),
            'format'          => $this->detectFormat($raw),
            'country'         => $raw['country']['name'] ?? null,
            'external_id'     => (string) ($raw['id'] ?? $raw['league']['id'] ?? null),
            'external_source' => 'api_sports',
            // Pass seasons through for post-processing
            '_seasons'        => $raw['seasons'] ?? [],
        ];
    }

    private function transformTeam(array $raw): array
    {
        return [
            'name'            => $raw['name'] ?? 'Unknown',
            'short_name'      => $raw['code'] ?? null,
            'country'         => is_array($raw['country'] ?? null)
                ? ($raw['country']['name'] ?? 'Unknown')
                : ($raw['country'] ?? 'Unknown'),
            'type'            => 'club',
            'logo_url'        => $raw['logo'] ?? null,
            'external_id'     => (string) ($raw['id'] ?? null),
            'external_source' => 'api_sports',
        ];
    }

    private function transformMatch(array $raw): array
    {
        return [
            'external_id'     => (string) ($raw['id'] ?? null),
            'external_source' => 'api_sports',
            'kickoff'         => $raw['date'] ?? null,
            'status'          => $this->mapStatus($raw['status']['short'] ?? 'NS'),
            'round'           => $raw['week'] ?? null,
            '_league_id'      => $raw['league']['id'] ?? null,
            '_season_year'    => $raw['league']['season'] ?? $this->seasonYear,
            '_home'           => $raw['teams']['home'] ?? null,
            '_away'           => $raw['teams']['away'] ?? null,
            '_scores'         => $raw['scores'] ?? null,
        ];
    }

    // ─── Upserters ───────────────────────────────────────────

    private function upsertCompetition(array $data): void
    {
        $seasons = $data['_seasons'] ?? [];
        unset($data['_seasons']);

        $competition = Competition::where('external_id', $data['external_id'])
            ->where('external_source', 'api_sports')
            ->first();

        if (! $competition) {
            $competition = Competition::where('code', $data['code'])->first();
        }

        if ($competition) {
            $updateData = $data;

            if ($competition->external_source && $competition->external_source !== 'api_sports') {
                unset($updateData['external_id'], $updateData['external_source']);
            }

            $competition->fill($updateData);
            $competition->save();
        } else {
            $competition = Competition::create($data);
        }

        $this->import->increment('records_created');

        // Create season records from the API response
        // API shape: {"season": 2026, "current": true, "start": "2026-03-14", "end": "2026-10-17"}
        foreach ($seasons as $seasonData) {
            $yearStr = (string) ($seasonData['season'] ?? $seasonData);
            $isCurrent = (bool) ($seasonData['current'] ?? false);
            $startDate = $seasonData['start'] ?? "{$yearStr}-01-01";
            $endDate = $seasonData['end'] ?? "{$yearStr}-12-31";

            Season::updateOrCreate(
                [
                    'competition_id' => $competition->id,
                    'label'          => $yearStr,
                ],
                [
                    'start_date'      => $startDate,
                    'end_date'        => $endDate,
                    'is_current'      => $isCurrent,
                    'external_id'     => $yearStr,
                    'external_source' => 'api_sports',
                ]
            );
        }
    }

    private function upsertTeam(array $data): void
    {
        $team = Team::updateOrCreate(
            ['external_id' => $data['external_id'], 'external_source' => 'api_sports'],
            $data,
        );
        $this->import->increment('records_created');

        // Link team to the season being synced via team_season pivot
        if ($this->leagueId && $this->seasonYear) {
            $competition = Competition::where('external_id', (string) $this->leagueId)
                ->where('external_source', 'api_sports')
                ->first();

            if ($competition) {
                $season = Season::where('competition_id', $competition->id)
                    ->where('label', (string) $this->seasonYear)
                    ->first();

                if ($season) {
                    $team->seasons()->syncWithoutDetaching([$season->id]);
                }
            }
        }
    }

    private function upsertMatch(array $data): void
    {
        $home = $data['_home'] ?? null;
        $away = $data['_away'] ?? null;
        $scores = $data['_scores'] ?? null;
        $leagueId = $data['_league_id'] ?? null;
        $seasonYear = $data['_season_year'] ?? null;

        unset($data['_home'], $data['_away'], $data['_scores'], $data['_league_id'], $data['_season_year']);

        // Find the season for this match
        if ($leagueId && $seasonYear) {
            $competition = Competition::where('external_id', (string) $leagueId)
                ->where('external_source', 'api_sports')
                ->first();

            if ($competition) {
                $season = Season::where('competition_id', $competition->id)
                    ->where('label', (string) $seasonYear)
                    ->first();

                if ($season) {
                    $data['season_id'] = $season->id;
                }
            }
        }

        // Skip if we can't link to a season
        if (! isset($data['season_id'])) {
            Log::warning('Skipping match — no season found', [
                'external_id' => $data['external_id'],
                'league_id' => $leagueId,
                'season_year' => $seasonYear,
            ]);
            return;
        }

        $match = RugbyMatch::updateOrCreate(
            ['external_id' => $data['external_id'], 'external_source' => 'api_sports'],
            $data,
        );

        $this->import->increment('records_created');

        // Create/update home and away match_teams
        foreach (['home' => $home, 'away' => $away] as $side => $teamData) {
            if (! $teamData) continue;

            $team = Team::where('external_id', (string) ($teamData['id'] ?? null))
                ->where('external_source', 'api_sports')
                ->first();

            if (! $team) {
                // Auto-create the team if we haven't seen it yet
                $team = Team::create([
                    'name'            => $teamData['name'] ?? 'Unknown',
                    'country'         => 'Unknown',
                    'type'            => 'club',
                    'logo_url'        => $teamData['logo'] ?? null,
                    'external_id'     => (string) ($teamData['id'] ?? null),
                    'external_source' => 'api_sports',
                ]);
            }

            // Determine scores
            $score = null;
            $htScore = null;
            if ($scores) {
                $score = $scores[$side] ?? null;
                // API-Sports sometimes nests halftime scores
                if (isset($scores['halftime'])) {
                    $htScore = $scores['halftime'][$side] ?? null;
                }
            }

            $isWinner = null;
            if ($score !== null && $scores) {
                $otherSide = $side === 'home' ? 'away' : 'home';
                $otherScore = $scores[$otherSide] ?? null;
                if ($score !== null && $otherScore !== null) {
                    $isWinner = $score > $otherScore;
                }
            }

            MatchTeam::updateOrCreate(
                ['match_id' => $match->id, 'side' => $side],
                [
                    'team_id'   => $team->id,
                    'score'     => $score,
                    'ht_score'  => $htScore,
                    'is_winner' => $isWinner,
                ],
            );
        }
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function apiGet(string $endpoint, array $params = []): array
    {
        if ($this->requestsToday >= $this->dailyLimit) {
            throw new \RuntimeException("API-Sports daily limit ({$this->dailyLimit}) reached.");
        }

        $response = Http::withHeaders([
            'x-apisports-key' => $this->apiKey,
        ])->get($this->baseUrl . $endpoint, $params);

        $this->requestsToday++;

        if ($response->failed()) {
            throw new \RuntimeException("API-Sports request failed: {$response->status()}");
        }

        return $response->json();
    }

    private function detectFormat(array $raw): string
    {
        $name = strtolower($raw['name'] ?? $raw['league']['name'] ?? '');
        if (str_contains($name, 'sevens') || str_contains($name, '7s')) return 'sevens';
        if (str_contains($name, 'league') || str_contains($name, 'nrl') || str_contains($name, 'super league')) return 'league';
        return 'union';
    }

    private function mapStatus(string $apiStatus): string
    {
        return match ($apiStatus) {
            'NS'          => 'scheduled',
            '1H', '2H', 'HT', 'LIVE' => 'live',
            'FT', 'AET'  => 'ft',
            'PST'         => 'postponed',
            'CANC'        => 'cancelled',
            'ABD'         => 'abandoned',
            default       => 'scheduled',
        };
    }
}
