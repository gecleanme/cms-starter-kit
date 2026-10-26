<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\TriggerFrontendBuild;

/**
 * Registered explicitly in AppServiceProvider::registerFrontendRebuilds().
 * handle() is intentionally untyped so Laravel's listener auto-discovery ignores it (no double registration).
 */
final class QueueFrontendBuild
{
    public function handle(object $event): void
    {
        if (blank(config('services.frontend.deploy_hook'))) {
            return; // Local dev, or the Nuxt site isn't wired up yet.
        }

        if (config('services.frontend.builds_paused')) {
            return; // Set FRONTEND_BUILDS_PAUSED=true during large imports, then trigger one build manually.
        }

        // ShouldBeUnique on the job collapses bursts of saves into one build.
        TriggerFrontendBuild::dispatch()->delay(now()->addSeconds(60));
    }
}
