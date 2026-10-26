<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\GlobalSet;

/**
 * Seeds global values that WordPress keeps in theme settings (not exportable as content),
 * from database/seed/globals.json. Edit that file first (it ships with company-name placeholders).
 *
 * Safe by default: dry run, and only EMPTY fields are filled.
 *
 *   php artisan cms:seed-globals            # dry run: shows what would change
 *   php artisan cms:seed-globals --write    # save
 *   php artisan cms:seed-globals --write --force   # also overwrite fields that already have a value
 */
final class SeedGlobals extends Command
{
    protected $signature = 'cms:seed-globals
        {--file=database/seed/globals.json : JSON file with the values}
        {--write : Save the values (otherwise dry run)}
        {--force : Overwrite fields that already have a value}';

    protected $description = 'Seed site settings / newsletter CTA globals from a JSON file.';

    public function handle(): int
    {
        $path = base_path((string) $this->option('file'));
        $values = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($values)) {
            $this->error("Cannot read valid JSON from {$path}");

            return self::FAILURE;
        }

        if (str_contains((string) file_get_contents($path), 'company-name') && $this->option('write')) {
            $this->error('The file still contains "company-name" placeholders. Replace them first.');

            return self::FAILURE;
        }

        foreach ($values as $handle => $fields) {
            if (str_starts_with((string) $handle, '//') || ! is_array($fields)) {
                continue;
            }

            $set = GlobalSet::findByHandle($handle);

            if (! $set) {
                $this->error("Global set '{$handle}' not found. Deploy the starter kit first.");

                continue;
            }

            $variables = $set->inDefaultSite();
            $changes = [];

            foreach ($fields as $key => $value) {
                if ($this->option('force') || blank($variables->get($key))) {
                    $changes[$key] = $value;
                }
            }

            $this->line("<info>{$handle}</info>: ".($changes ? implode(', ', array_keys($changes)) : 'nothing to change'));

            if ($changes && $this->option('write')) {
                $variables->merge($changes)->save();
            }
        }

        if (! $this->option('write')) {
            $this->comment('Dry run - nothing was saved. Re-run with --write to save.');
        }

        return self::SUCCESS;
    }
}
