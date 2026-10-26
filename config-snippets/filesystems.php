<?php
// MERGE INTO: config/filesystems.php  -> 'disks' => [ ... ]
// Lives under storage/ = a Forge shared path, so files survive zero-downtime releases.
// `php artisan storage:link` (already in the deploy script) exposes it at /storage.

'assets' => env('ASSETS_DISK_DRIVER', 'local') === 's3'
    ? [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'root' => 'assets',
        'url' => env('ASSETS_URL'),            // CDN in front of the bucket
        'visibility' => 'public',
        'throw' => false,
    ]
    : [
        'driver' => 'local',
        'root' => storage_path('app/public/assets'),
        'url' => rtrim((string) env('APP_URL'), '/').'/storage/assets',
        'visibility' => 'public',
        'throw' => false,
    ],
