<?php
// MERGE INTO: routes/console.php
// Daily front-end rebuild. The static site decides "upcoming" vs "past" events when it is built,
// so it must be rebuilt once a day even when nobody publishes anything.
// (Scheduled posts are already covered: EntryScheduleReached queues a build when their date arrives.)
// Needs the Forge scheduler (php artisan schedule:run every minute), which the runbook sets up.

use App\Jobs\TriggerFrontendBuild;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new TriggerFrontendBuild)
    ->dailyAt('03:17')
    ->when(fn () => filled(config('services.frontend.deploy_hook')) && ! config('services.frontend.builds_paused'))
    ->name('frontend-daily-rebuild')
    ->withoutOverlapping();
