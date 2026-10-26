<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Asset;

/**
 * Copies alt text and captions from WordPress (media.csv written by wp:pull) onto the
 * Statamic assets that were rsynced into the container with the same YYYY/MM/file paths.
 *
 *   php artisan wp:media-meta storage/app/import/media.csv
 *   php artisan wp:media-meta storage/app/import/media.csv --force   # overwrite existing alt text
 */
final class WpMediaMeta extends Command
{
    protected $signature = 'wp:media-meta
        {csv : Path to media.csv}
        {--container=assets : Asset container handle}
        {--force : Overwrite alt/caption already set in Statamic}';

    protected $description = 'Apply WordPress alt text and captions to migrated Statamic assets (by path).';

    public function handle(): int
    {
        $path = base_path((string) $this->argument('csv'));

        if (! is_file($path) || ($fh = fopen($path, 'rb')) === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($fh, null, ',', '"', '') ?: [];
        $container = (string) $this->option('container');
        $force = (bool) $this->option('force');
        $updated = $unchanged = $missing = 0;
        $missingPaths = [];

        while (($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $row = array_combine($header, array_slice(array_pad($line, count($header), ''), 0, count($header)));
            $assetPath = ltrim((string) ($row['path'] ?? ''), '/');

            if ($assetPath === '') {
                continue;
            }

            $asset = Asset::find("{$container}::{$assetPath}");

            if (! $asset) {
                $missing++;
                $missingPaths[] = $assetPath;

                continue;
            }

            $dirty = false;
            foreach (['alt' => 'alt', 'caption' => 'caption'] as $column => $field) {
                $value = trim((string) ($row[$column] ?? ''));

                if ($value !== '' && ($force || blank($asset->get($field)))) {
                    $asset->set($field, $value);
                    $dirty = true;
                }
            }

            if ($dirty) {
                $asset->save();
                $updated++;
            } else {
                $unchanged++;
            }
        }

        fclose($fh);

        $this->table(['Updated', 'Unchanged', 'Not found in container'], [[$updated, $unchanged, $missing]]);

        if ($missingPaths !== []) {
            $report = dirname($path).'/media-missing.txt';
            file_put_contents($report, implode(PHP_EOL, $missingPaths).PHP_EOL);
            $this->warn("Missing paths written to {$report} (often excluded size variants or files deleted on WP).");
        }

        return self::SUCCESS;
    }
}
