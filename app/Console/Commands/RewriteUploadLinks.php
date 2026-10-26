<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;

/**
 * After the imports: turns links inside rich text (Bard) that still point at
 * https://<wordpress>/wp-content/uploads/... (PDFs, full-size images, downloads) into links to the
 * migrated Statamic asset (statamic://asset::assets::YYYY/MM/file), so they survive WordPress being switched off.
 *
 * Images themselves were already converted by the Importer; this handles <a href="..."> links.
 * Only rewrites when the file exists in the asset container. Everything else is listed for manual review.
 *
 * Safe by default: dry run. Saves quietly (no revisions, no front-end rebuild per entry).
 *
 *   php artisan wp:rewrite-upload-links              # dry run: report only
 *   php artisan wp:rewrite-upload-links --write      # save changes
 */
final class RewriteUploadLinks extends Command
{
    protected $signature = 'wp:rewrite-upload-links
        {--write : Save changes (otherwise dry run)}
        {--container=assets : Asset container handle}
        {--collection=* : Limit to these collections (default: all)}';

    protected $description = 'Point rich-text links to WordPress uploads at the migrated Statamic assets.';

    private string $base = '';

    private string $container = 'assets';

    private int $rewritten = 0;

    /** @var array<int, string> */
    private array $unresolved = [];

    public function handle(): int
    {
        $wp = rtrim((string) config('services.wordpress.base_url'), '/');

        if ($wp === '') {
            $this->error('Set WP_BASE_URL in .env (the WordPress site address, e.g. https://www.company-name.com).');

            return self::FAILURE;
        }

        $this->base = $wp.'/wp-content/uploads/';
        $this->container = (string) $this->option('container');
        $collections = (array) $this->option('collection');
        $changedEntries = 0;

        $query = Entry::query();
        if ($collections !== []) {
            $query->whereIn('collection', $collections);
        }

        foreach ($query->get() as $entry) {
            $before = $this->rewritten;
            $data = $entry->data()->all();
            $new = $this->walk($data, "{$entry->collectionHandle()}/{$entry->slug()}");

            if ($this->rewritten > $before) {
                $changedEntries++;

                if ($this->option('write')) {
                    $entry->data($new)->saveQuietly();
                }
            }
        }

        $this->table(['Links rewritten', 'Entries changed', 'Left for manual review'], [[$this->rewritten, $changedEntries, count($this->unresolved)]]);

        if ($this->unresolved !== []) {
            $report = storage_path('app/import/upload-links-unresolved.txt');
            @mkdir(dirname($report), 0775, true);
            file_put_contents($report, implode(PHP_EOL, array_unique($this->unresolved)).PHP_EOL);
            $this->warn("Unresolved references written to {$report}");
        }

        if (! $this->option('write')) {
            $this->comment('Dry run - nothing was saved. Re-run with --write to save.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function walk(array $node, string $where): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $where);

                continue;
            }

            if (! is_string($value) || ! str_contains($value, $this->base)) {
                continue;
            }

            // Bard link marks store the URL in attrs.href
            if ($key === 'href' && str_starts_with($value, $this->base)) {
                $path = $this->resolve(Str::after($value, $this->base));

                if ($path !== null) {
                    $node[$key] = "statamic://asset::{$this->container}::{$path}";
                    $this->rewritten++;

                    continue;
                }
            }

            $this->unresolved[] = "{$where}  [{$key}]  ".Str::limit($value, 160);
        }

        return $node;
    }

    /** Finds the asset path for an uploads URL; falls back to the original if a WP size variant was linked. */
    private function resolve(string $path): ?string
    {
        $path = urldecode(Str::before(Str::before($path, '?'), '#'));

        foreach ([$path, preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $path)] as $candidate) {
            if ($candidate && Asset::find("{$this->container}::{$candidate}")) {
                return $candidate;
            }
        }

        return null;
    }
}
