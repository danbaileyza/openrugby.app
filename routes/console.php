<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes (Scheduler)
|--------------------------------------------------------------------------
|
| Daily sync at 04:00 UTC, then RAG document generation at 05:00 UTC.
| The sync stays within the free API-Sports tier (100 requests/day).
|
*/

Schedule::command('rugby:sync-daily')->dailyAt('04:00');
Schedule::command('rugby:generate-rag')->dailyAt('05:00');

// Weekend results sweep — runs Sunday night and Monday morning (most rugby is Fri-Sun)
Schedule::command('rugby:refresh --details --recompute --audit')
    ->twiceDailyAt(23, 7)
    ->days([0, 1]); // Sunday, Monday
