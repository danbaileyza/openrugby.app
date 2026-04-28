<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes (Scheduler)
|--------------------------------------------------------------------------
|
| Daily sync at 04:00 UTC, then RAG document generation at 05:00 UTC.
| Live score sync runs every 15 minutes for supported competitions.
| A full refresh runs daily to backfill details, recompute, and audit.
|
*/

Schedule::command('rugby:sync-daily')->dailyAt('04:00');
Schedule::command('rugby:generate-rag')->dailyAt('05:00');

Schedule::command('rugby:sync-live --all --import-missing --lineups')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Daily results sweep and recompute pass.
Schedule::command('rugby:refresh --details --recompute --audit')
    ->dailyAt('07:00')
    ->withoutOverlapping();
