<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Core entities ───────────────────────────────────────

        Schema::create('competitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // "United Rugby Championship"
            $table->string('code')->unique();                // "urc", "currie_cup", "six_nations"
            $table->enum('format', ['union', 'league', 'sevens']);
            $table->string('country')->nullable();           // null for international
            $table->string('tier')->nullable();              // "tier_1", "tier_2"
            $table->string('logo_url')->nullable();
            $table->string('external_id')->nullable()->index(); // API-Sports / rugbypy ID
            $table->string('external_source')->nullable();
            $table->timestamps();
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('competition_id')->constrained()->cascadeOnDelete();
            $table->string('label');                         // "2025-26"
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'label']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // "Stormers"
            $table->string('short_name')->nullable();        // "STO"
            $table->string('country');
            $table->enum('type', ['club', 'national', 'franchise', 'provincial', 'invitational']);
            $table->string('logo_url')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->string('founded_year')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob')->nullable();
            $table->string('nationality')->nullable();
            $table->string('position');                      // "loosehead_prop", "flyhalf", etc.
            $table->string('position_group')->nullable();    // "front_row", "back_three", etc.
            $table->integer('height_cm')->nullable();
            $table->integer('weight_kg')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('referees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nationality')->nullable();
            $table->string('tier')->nullable();              // "world_rugby_panel", "national"
            $table->string('photo_url')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // "DHL Stadium"
            $table->string('city');
            $table->string('country');
            $table->integer('capacity')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('surface')->nullable();           // "natural", "hybrid"
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();
        });

        // ─── Junction / registration tables ──────────────────────

        Schema::create('team_season', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('season_id')->constrained()->cascadeOnDelete();
            $table->string('pool')->nullable();              // pool stage grouping
            $table->timestamps();

            $table->unique(['team_id', 'season_id']);
        });

        Schema::create('player_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['player_id', 'is_current']);
            $table->index(['team_id', 'is_current']);
        });

        // ─── Match core ──────────────────────────────────────────

        Schema::create('matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('season_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('kickoff');
            $table->enum('status', ['scheduled', 'live', 'ft', 'postponed', 'cancelled', 'abandoned'])->default('scheduled');
            $table->integer('round')->nullable();
            $table->string('stage')->nullable();             // "pool", "quarter_final", "final"
            $table->integer('attendance')->nullable();
            $table->text('weather_conditions')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('external_source')->nullable();
            $table->timestamps();

            $table->index(['season_id', 'kickoff']);
            $table->index(['status']);
        });

        Schema::create('match_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['home', 'away']);
            $table->integer('score')->nullable();
            $table->integer('ht_score')->nullable();
            $table->integer('tries')->nullable();
            $table->integer('conversions')->nullable();
            $table->integer('penalties_kicked')->nullable();
            $table->integer('drop_goals')->nullable();
            $table->integer('bonus_points')->default(0);
            $table->boolean('is_winner')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'side']);
            $table->index(['team_id', 'is_winner']);
        });

        // ─── Match detail (granular) ─────────────────────────────

        Schema::create('match_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->integer('minute');
            $table->integer('second')->nullable();
            $table->enum('type', [
                'try', 'conversion', 'penalty_goal', 'drop_goal',
                'penalty_miss', 'conversion_miss',
                'yellow_card', 'red_card',
                'substitution_on', 'substitution_off',
                'scrum_won', 'scrum_lost',
                'lineout_won', 'lineout_lost',
                'turnover', 'maul',
                'penalty_conceded', 'free_kick',
                'tmo_review',
            ]);
            $table->integer('phase')->nullable();            // phase of play
            $table->json('meta')->nullable();                // flexible: {assist_player_id, kick_type, reason}
            $table->timestamps();

            $table->index(['match_id', 'minute']);
            $table->index(['player_id', 'type']);
            $table->index(['team_id', 'type']);
        });

        Schema::create('match_lineups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->integer('jersey_number');
            $table->enum('role', ['starter', 'replacement']);
            $table->string('position');                      // position for this match
            $table->boolean('captain')->default(false);
            $table->integer('minutes_played')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
            $table->index(['player_id']);
        });

        Schema::create('match_officials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('referee_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['referee', 'assistant_referee_1', 'assistant_referee_2', 'tmo', 'reserve_referee']);
            $table->timestamps();

            $table->unique(['match_id', 'referee_id', 'role']);
        });

        // ─── Statistics (key-value for flexibility) ──────────────

        Schema::create('match_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('stat_key');                      // "tackles_made", "metres_carried", "possession_pct"
            $table->decimal('stat_value', 10, 2);
            $table->timestamps();

            $table->unique(['match_id', 'team_id', 'stat_key']);
            $table->index(['stat_key']);
        });

        Schema::create('player_match_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('stat_key');                      // "tackles", "carries", "metres", "offloads"
            $table->decimal('stat_value', 10, 2);
            $table->timestamps();

            $table->unique(['match_id', 'player_id', 'stat_key']);
            $table->index(['player_id', 'stat_key']);
        });

        Schema::create('player_season_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('season_id')->constrained()->cascadeOnDelete();
            $table->string('stat_key');
            $table->decimal('stat_value', 10, 2);
            $table->timestamps();

            $table->unique(['player_id', 'season_id', 'stat_key']);
        });

        // ─── Standings / ladder ──────────────────────────────────

        Schema::create('standings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('season_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('pool')->nullable();
            $table->integer('position');
            $table->integer('played')->default(0);
            $table->integer('won')->default(0);
            $table->integer('drawn')->default(0);
            $table->integer('lost')->default(0);
            $table->integer('points_for')->default(0);
            $table->integer('points_against')->default(0);
            $table->integer('tries_for')->default(0);
            $table->integer('tries_against')->default(0);
            $table->integer('bonus_points')->default(0);
            $table->integer('total_points')->default(0);
            $table->integer('point_differential')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'team_id', 'pool']);
        });

        // ─── RAG optimisation ────────────────────────────────────

        Schema::create('rag_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type');                   // "match_summary", "player_profile", "season_review"
            $table->uuidMorphs('documentable');              // polymorphic: match, player, team, season
            $table->text('content');                         // pre-rendered natural language text
            $table->json('embedding')->nullable();           // vector embedding for similarity search
            $table->json('metadata')->nullable();            // {competition, season, teams, date}
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['source_type']);
        });

        // ─── Data ingestion tracking ─────────────────────────────

        Schema::create('data_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source');                        // "api_sports", "rugbypy", "kaggle", "scraper"
            $table->string('entity_type');                   // "matches", "players", "stats"
            $table->enum('status', ['pending', 'running', 'completed', 'failed']);
            $table->integer('records_processed')->default(0);
            $table->integer('records_created')->default(0);
            $table->integer('records_updated')->default(0);
            $table->integer('records_failed')->default(0);
            $table->json('error_log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('rag_documents');
        Schema::dropIfExists('standings');
        Schema::dropIfExists('player_season_stats');
        Schema::dropIfExists('player_match_stats');
        Schema::dropIfExists('match_stats');
        Schema::dropIfExists('match_officials');
        Schema::dropIfExists('match_lineups');
        Schema::dropIfExists('match_events');
        Schema::dropIfExists('match_teams');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('player_contracts');
        Schema::dropIfExists('team_season');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('referees');
        Schema::dropIfExists('players');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('competitions');
    }
};
