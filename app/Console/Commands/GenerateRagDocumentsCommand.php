<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\RagDocument;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Services\Rugby\Rag\DocumentGenerator;
use Illuminate\Console\Command;

/**
 * Generates/refreshes RAG documents from structured rugby data.
 *
 * These documents are the bridge between your relational database and
 * your AI bot. Instead of the bot querying SQL directly, it searches
 * these pre-rendered natural language documents via embeddings.
 *
 * Schedule: Schedule::command('rugby:generate-rag')->dailyAt('05:00');
 */
class GenerateRagDocumentsCommand extends Command
{
    protected $signature = 'rugby:generate-rag
                            {--type=all : Type: match_summary, player_profile, team_season, competition_overview, referee_profile, all}
                            {--since= : Only regenerate docs for data updated since (date)}
                            {--fresh : Regenerate all documents from scratch}';

    protected $description = 'Generate natural language RAG documents from rugby data';

    public function handle(DocumentGenerator $generator): int
    {
        $type = $this->option('type');
        $since = $this->option('since') ? now()->parse($this->option('since')) : null;

        if ($this->option('fresh')) {
            $this->warn('Clearing all existing RAG documents...');
            RagDocument::truncate();
        }

        // Match summaries — chunk to avoid memory exhaustion on large datasets
        if (in_array($type, ['all', 'match_summary'])) {
            $query = RugbyMatch::where('status', 'ft');
            if ($since) {
                $query->where('updated_at', '>=', $since);
            }

            $total = $query->count();
            $this->info("Generating match summaries for {$total} matches...");
            $bar = $this->output->createProgressBar($total);

            $query->chunkById(100, function ($matches) use ($generator, $bar) {
                foreach ($matches as $match) {
                    $generator->generateMatchSummary($match);
                    $bar->advance();
                }
                // Free memory between chunks
                gc_collect_cycles();
            });
            $bar->finish();
            $this->newLine();
        }

        // Player profiles
        if (in_array($type, ['all', 'player_profile'])) {
            $query = Player::where('is_active', true);
            if ($since) {
                $query->where('updated_at', '>=', $since);
            }

            $total = $query->count();
            $this->info("Generating player profiles for {$total} players...");
            $bar = $this->output->createProgressBar($total);

            $query->chunkById(200, function ($players) use ($generator, $bar) {
                foreach ($players as $player) {
                    $generator->generatePlayerProfile($player);
                    $bar->advance();
                }
                gc_collect_cycles();
            });
            $bar->finish();
            $this->newLine();
        }

        // Team season reviews — generate for any season that has matches AND teams
        if (in_array($type, ['all', 'team_season'])) {
            $seasons = Season::whereHas('matches', fn ($q) => $q->where('status', 'ft'))
                ->with('competition')
                ->get();

            $this->info("Found {$seasons->count()} seasons with completed matches for team reviews.");

            foreach ($seasons as $season) {
                // Get teams that actually played in this season (via match_teams)
                $teamIds = MatchTeam::whereHas('match', fn ($q) => $q->where('season_id', $season->id))
                    ->distinct()
                    ->pluck('team_id');

                $teams = Team::whereIn('id', $teamIds)->get();

                if ($teams->isEmpty()) {
                    continue;
                }

                $this->info("  {$season->competition->name} {$season->label}: {$teams->count()} teams...");

                $bar = $this->output->createProgressBar($teams->count());
                foreach ($teams as $team) {
                    $generator->generateTeamSeasonReview($team, $season);
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            }
        }

        // Competition overviews
        if (in_array($type, ['all', 'competition_overview'])) {
            $query = Competition::query();
            if ($since) {
                $query->where('updated_at', '>=', $since);
            }
            $competitions = $query->get();

            $this->info("Generating overviews for {$competitions->count()} competitions...");
            $bar = $this->output->createProgressBar($competitions->count());
            foreach ($competitions as $competition) {
                $generator->generateCompetitionOverview($competition);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
        }

        // Referee profiles
        if (in_array($type, ['all', 'referee_profile'])) {
            $query = Referee::query();
            if ($since) {
                $query->where('updated_at', '>=', $since);
            }

            $total = $query->count();
            $this->info("Generating profiles for {$total} referees...");
            $bar = $this->output->createProgressBar($total);

            $query->chunkById(200, function ($refs) use ($generator, $bar) {
                foreach ($refs as $ref) {
                    $generator->generateRefereeProfile($ref);
                    $bar->advance();
                }
                gc_collect_cycles();
            });
            $bar->finish();
            $this->newLine();
        }

        $total = RagDocument::count();
        $this->info("Done. Total RAG documents in database: {$total}");

        return self::SUCCESS;
    }
}
