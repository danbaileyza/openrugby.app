<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rugby Hub — Data Source Configuration
    |--------------------------------------------------------------------------
    |
    | Configure API keys, rate limits, and sync preferences for each data
    | source. The daily sync command uses these to stay within budget.
    |
    */

    'sources' => [

        'api_sports' => [
            'enabled'     => env('RUGBY_API_SPORTS_ENABLED', true),
            'key'         => env('RUGBY_API_SPORTS_KEY'),
            'base_url'    => 'https://v1.rugby.api-sports.io',
            'daily_limit' => env('RUGBY_API_SPORTS_DAILY_LIMIT', 100), // free=100, pro=7500
            'priority'    => 1, // primary source for live/recent data
        ],

        'the_sports_db' => [
            'enabled'  => env('RUGBY_SPORTSDB_ENABLED', false),
            'key'      => env('RUGBY_SPORTSDB_KEY', '1'), // free tier key
            'base_url' => 'https://www.thesportsdb.com/api/v1/json',
            'priority' => 2, // secondary: logos, team metadata
        ],

        'rugbypy' => [
            'enabled'  => env('RUGBY_RUGBYPY_ENABLED', true),
            'priority' => 3, // bulk historical seed
            'notes'    => 'Python package — run via Artisan shell or scheduled Python script',
        ],

        'kaggle' => [
            'enabled'  => env('RUGBY_KAGGLE_ENABLED', false),
            'priority' => 4, // one-time historical imports
            'datasets' => [
                'international_results' => 'lylebegbie/international-rugby-union-results-from-18712022',
                'urc_player_profiles'   => 'SCORE/urc-player-profiles-2024-25',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Schedule
    |--------------------------------------------------------------------------
    */

    'sync' => [
        'daily_at'              => '04:00',     // UTC
        'rag_generation_at'     => '05:00',     // UTC — after sync completes
        'backfill_batch_size'   => 50,          // records per backfill run
        'retry_failed_after'    => 60,          // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Configuration
    |--------------------------------------------------------------------------
    */

    'rag' => [
        'embedding_model'   => env('RUGBY_EMBEDDING_MODEL', 'claude'), // or 'openai'
        'chunk_max_tokens'  => 2000,
        'overlap_tokens'    => 200,
        'document_types'    => [
            'match_summary',
            'player_profile',
            'team_season_review',
            'competition_overview',
            'referee_profile',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stat Keys — canonical list for key-value stat tables
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Standings Computation
    |--------------------------------------------------------------------------
    */

    'standings' => [
        'points' => [
            'win'  => 4,
            'draw' => 2,
            'loss' => 0,
        ],

        'bonus' => [
            'try_threshold'          => 4, // 4+ tries = +1 bonus point
            'losing_margin_threshold' => 7, // lose by ≤7 = +1 bonus point
        ],

        // Per-competition overrides, keyed by competitions.code
        'competition_overrides' => [
            'top_14' => [
                'bonus' => [
                    'losing_margin_threshold' => 5,
                ],
            ],
            // 'some_old_comp' => ['bonus' => false], // disables bonus points
        ],

        'countable_statuses' => ['ft'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stat Keys — canonical list for key-value stat tables
    |--------------------------------------------------------------------------
    */

    'stat_keys' => [

        'match_team' => [
            'possession_pct', 'territory_pct',
            'tackles_made', 'tackles_missed', 'tackle_success_pct',
            'carries', 'metres_carried', 'clean_breaks', 'defenders_beaten',
            'offloads', 'passes',
            'scrums_won', 'scrums_lost', 'scrum_success_pct',
            'lineouts_won', 'lineouts_lost', 'lineout_success_pct',
            'rucks_won', 'rucks_lost',
            'turnovers_won', 'turnovers_conceded',
            'penalties_conceded', 'free_kicks_conceded',
            'yellow_cards', 'red_cards',
            'kicks_from_hand', 'kick_metres',
        ],

        'player_match' => [
            'minutes_played',
            'tries', 'try_assists', 'conversions', 'penalties_kicked', 'drop_goals',
            'points',
            'carries', 'metres_carried', 'clean_breaks', 'defenders_beaten',
            'offloads', 'passes',
            'tackles_made', 'tackles_missed',
            'turnovers_won',
            'lineout_steals',
            'yellow_cards', 'red_cards',
        ],

        'player_season' => [
            'appearances', 'starts', 'replacement_appearances',
            'total_minutes',
            'tries', 'conversions', 'penalties_kicked', 'drop_goals', 'total_points',
            'yellow_cards', 'red_cards',
            'total_carries', 'total_metres', 'total_tackles',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nationalities — used in player & referee forms
    |--------------------------------------------------------------------------
    | Focused on rugby-playing nations; add more as needed.
    */

    'nationalities' => [
        'South Africa',
        'Argentina', 'Australia', 'Belgium', 'Botswana', 'Brazil',
        'Canada', 'Chile', 'Czech Republic',
        'England', 'Fiji', 'France',
        'Georgia', 'Germany', 'Hong Kong',
        'Ireland', 'Italy', 'Japan',
        'Kenya', 'Lesotho',
        'Mozambique', 'Namibia', 'Netherlands', 'New Zealand',
        'Papua New Guinea', 'Paraguay', 'Poland', 'Portugal',
        'Romania', 'Russia',
        'Samoa', 'Scotland', 'Singapore', 'Spain', 'Swaziland (Eswatini)',
        'Tonga', 'Ukraine', 'United Arab Emirates', 'United States', 'Uruguay',
        'Wales', 'Zimbabwe',
    ],
];
