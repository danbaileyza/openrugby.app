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

// SA schools — split into two entries so each source can use the right
// strategy on prod:
//   - schoolrugby.co.za: scrape live (server is allowed)
//   - schoolboyrugby.co.za: re-import the committed JSON (server's IP is
//     blocked by their WAF; we ship updated JSON via the repo from dev)
Schedule::command('rugby:sync-schools --source=schoolrugby')
    ->dailyAt('05:30')
    ->withoutOverlapping();
Schedule::command('rugby:sync-schools --source=schoolboyrugby --skip-scrape')
    ->dailyAt('05:45')
    ->withoutOverlapping();

// Sunday-evening top-up — most weekend results post Sunday afternoon, so
// pulling again at 21:00 UTC catches them same-day instead of Monday morning.
Schedule::command('rugby:sync-schools --source=schoolrugby')
    ->weeklyOn(0, '21:00')
    ->withoutOverlapping();

// Weekly: backfill schoolrugby external_ids on schools we discovered via
// schoolboyrugby (short names). Uses the committed directory JSON when
// schoolrugby.co.za blocks the prod IP.
Schedule::command('rugby:link-school-ids')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping();
