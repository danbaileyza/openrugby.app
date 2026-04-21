<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Competition data is now sourced from API-Sports via `rugby:sync-daily`.
 * This seeder is intentionally empty — run the sync command instead.
 */
class CompetitionSeeder extends Seeder
{
    public function run(): void
    {
        // No-op: competitions are created by the API importer.
        // Run: php artisan rugby:sync-daily
    }
}
