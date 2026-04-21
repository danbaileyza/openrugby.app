<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed SA-focused competitions
        $this->call([
            CompetitionSeeder::class,
        ]);
    }
}
