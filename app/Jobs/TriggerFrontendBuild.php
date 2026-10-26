<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POSTs to the front end's deploy hook (Forge deploy hook now, Vercel deploy hook later).
 * Unique for 120 s: while one build request is pending, further saves don't queue another.
 */
final class TriggerFrontendBuild implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function handle(): void
    {
        $url = (string) config('services.frontend.deploy_hook');

        if ($url === '') {
            return;
        }

        Http::timeout(20)->post($url)->throw();

        Log::info('Front-end build triggered.');
    }
}
