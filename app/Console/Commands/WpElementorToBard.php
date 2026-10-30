<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * Rebuilds the Bard body of Elementor-built WordPress posts from Elementor's own layout data (`_elementor_data`).
 *
 * Why: on prod, posts are built with Elementor. WordPress keeps only a simplified copy of an Elementor layout in
 * post_content, which loses videos, buttons and galleries. Elementor's JSON keeps every widget, in order.
 *
 * Input: a WP All Export CSV of Posts with the columns `ID` and `_elementor_data` (Custom Fields > _elementor_data).
 * Run AFTER the posts import and the media copy (images are matched to migrated assets).
 *
 * Widget mapping (anything else is listed in the report for a manual look):
 *   heading           -> Bard heading (h1 -> h2, h5/h6 -> h4; the post title is the page's only h1)
 *   text-editor       -> Bard paragraphs, lists, links, inline images
 *   image             -> `image` set (image + caption)
 *   video             -> `video_embed` set (YouTube / Vimeo / hosted URL)
 *   button, cpl-button-> `cta` set (label + link, primary/solid/md)
 *   image-carousel,
 *   image-gallery,
 *   gallery           -> `gallery` set
 *   shortcode         -> `form` set when it is a Gravity Forms shortcode
 *   spacer, divider   -> dropped (spacing is the design system's job)
 *
 *   php artisan wp:elementor-to-bard storage/app/import/posts_elementor.csv            # dry run + report
 *   php artisan wp:elementor-to-bard storage/app/import/posts_elementor.csv --write    # save
 */
final class WpElementorToBard extends Command
{
    protected $signature = 'wp:elementor-to-bard
        {csv : WP All Export CSV with ID + _elementor_data}
        {--write : Save changes (otherwise dry run)}
        {--collection=posts : Collection to update}
        {--field=content : Bard field to rebuild}
        {--id-column=ID : CSV column holding the WordPress post ID}
        {--data-column=_elementor_data : CSV column holding the Elementor JSON}
        {--all : Also convert entries whose wp_builder is not "builder"}
        {--container=assets : Asset container of the migrated uploads}';

    protected $description = 'Rebuild Bard content of Elementor-built posts from _elementor_data.';

    private string $uploadsBase = '';

    /** @var array<string, int> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $report = [];

    /** @var array<int, string> */
    private array $unresolved = [];

    private ?Augmentor $augmentor = null;

    public function handle(): int
    {
        $wp = rtrim((string) config('services.wordpress.base_url'), '/');
        if ($wp === '') {
            $this->error('Set WP_BASE_URL in .env (the WordPress site address), so image URLs can be matched to assets.');

            return self::FAILURE;
        }
        $this->uploadsBase = $wp.'/wp-content/uploads/';

        $converted = 0;
        $notFound = 0;
        $notBuilder = 0;
        $invalid = 0;

        foreach ($this->rows((string) $this->argument('csv')) as $row) {
            $wpId = trim((string) ($row[$this->option('id-column')] ?? ''));
            $raw = (string) ($row[$this->option('data-column')] ?? '');

            if ($wpId === '' || trim($raw) === '') {
                continue;
            }

            $entry = $this->findEntry($wpId);
            if (! $entry) {
                $notFound++;
                $this->report[] = "wp_id {$wpId}: no entry in '{$this->option('collection')}' (import the posts first)";

                continue;
            }

            if (! $this->option('all') && $entry->get('wp_builder') !== 'builder') {
                $notBuilder++;

                continue;
            }

            $elements = $this->decode($raw);
            if ($elements === null) {
                $invalid++;
                $this->report[] = "wp_id {$wpId} ({$entry->slug()}): _elementor_data is not valid JSON - skipped";

                continue;
            }

            $this->augmentor = new Augmentor($entry->blueprint()->field((string) $this->option('field'))->fieldtype());
            $before = $this->skipped;
            $nodes = $this->walk($elements, $entry->slug());

            if ($nodes === []) {
                $this->report[] = "wp_id {$wpId} ({$entry->slug()}): nothing convertible found - left unchanged";

                continue;
            }

            $converted++;
            $diff = collect($this->skipped)->map(fn ($n, $t) => $n - ($before[$t] ?? 0))->filter()->map(fn ($n, $t) => "{$t} x{$n}")->implode(', ');
            $this->report[] = "wp_id {$wpId} ({$entry->slug()}): ".count($nodes).' blocks'.($diff ? "; skipped: {$diff}" : '');

            if ($this->option('write')) {
                $entry->set((string) $this->option('field'), array_values($nodes))->saveQuietly();
            }
        }

        $this->table(
            ['Converted', 'Not Elementor (skipped)', 'No matching entry', 'Invalid JSON'],
            [[$converted, $notBuilder, $notFound, $invalid]]
        );

        if ($this->skipped !== []) {
            arsort($this->skipped);
            $this->line('Widgets not converted (check these posts by hand): '.collect($this->skipped)->map(fn ($n, $t) => "{$t} ({$n})")->implode(', '));
        }

        $dir = storage_path('app/import');
        @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/elementor-report.txt", implode(PHP_EOL, array_merge(
            $this->report,
            $this->unresolved === [] ? [] : ['', 'Images not found among migrated assets (kept as external URLs or skipped):'],
            array_unique($this->unresolved),
        )).PHP_EOL);
        $this->info("Report: {$dir}/elementor-report.txt");

        if (! $this->option('write')) {
            $this->comment('Dry run - nothing was saved. Re-run with --write to save.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $elements
     * @return array<int, array<string, mixed>>
     */
    private function walk(array $elements, string $where): array
    {
        $nodes = [];

        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }

            if (($el['elType'] ?? '') !== 'widget') {
                // section / column / container: keep reading order, drop the layout
                array_push($nodes, ...$this->walk((array) ($el['elements'] ?? []), $where));

                continue;
            }

            array_push($nodes, ...$this->widget((string) ($el['widgetType'] ?? ''), (array) ($el['settings'] ?? []), $where));
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<int, array<string, mixed>>
     */
    private function widget(string $type, array $s, string $where): array
    {
        switch ($type) {
            case 'heading':
                $title = trim((string) ($s['title'] ?? ''));
                if ($title === '') {
                    return [];
                }
                $level = (int) ltrim((string) ($s['header_size'] ?? 'h2'), 'h') ?: 2;
                $node = $this->html("<h2>{$title}</h2>")[0] ?? null;
                if (! $node) {
                    return [];
                }
                $node['attrs']['level'] = max(2, min(4, $level));

                return [$node];

            case 'text-editor':
                return $this->html((string) ($s['editor'] ?? ''), $where);

            case 'image':
                $path = $this->assetPath((string) ($s['image']['url'] ?? ''), $where);
                if (! $path) {
                    return [];
                }

                return [$this->set('image', [
                    'image' => $path,
                    'caption' => (string) (($s['caption_source'] ?? '') === 'custom' ? ($s['caption'] ?? '') : ''),
                ])];

            case 'video':
                $url = match ((string) ($s['video_type'] ?? 'youtube')) {
                    'vimeo' => (string) ($s['vimeo_url'] ?? ''),
                    'hosted' => (string) ($s['hosted_url']['url'] ?? $s['external_url']['url'] ?? ''),
                    'dailymotion' => (string) ($s['dailymotion_url'] ?? ''),
                    default => (string) ($s['youtube_url'] ?? ''),
                };

                return $url === '' ? [] : [$this->set('video_embed', ['url' => $url, 'caption' => ''])];

            case 'button':
            case 'cpl-button':
                $label = trim(strip_tags((string) ($s['text'] ?? $s['button_text'] ?? '')));
                $url = (string) ($s['link']['url'] ?? $s['button_link']['url'] ?? '');
                if ($label === '' || $url === '' || str_starts_with($url, '#elementor-action')) {
                    $this->skip($type.' (popup/empty)');

                    return [];
                }

                return [$this->set('cta', [
                    'cta_label' => $label,
                    'cta_link' => $url,
                    'cta_color' => 'primary',
                    'cta_variant' => 'solid',
                    'cta_size' => 'md',
                ])];

            case 'image-carousel':
            case 'image-gallery':
            case 'gallery':
                $items = (array) ($s['carousel'] ?? $s['wp_gallery'] ?? $s['gallery'] ?? []);
                $paths = collect($items)
                    ->map(fn ($i) => is_array($i) ? $this->assetPath((string) ($i['url'] ?? ''), $where) : null)
                    ->filter()->values()->all();

                return count($paths) < 1 ? [] : [$this->set('gallery', ['images' => $paths, 'caption' => ''])];

            case 'shortcode':
                if (preg_match('/\[gravityforms?\s+[^\]]*id=["\']?(\d+)/i', (string) ($s['shortcode'] ?? ''), $m)
                    && ($handle = AppServiceProvider::GRAVITY_FORMS_MAP[(int) $m[1]] ?? null)) {
                    return [AppServiceProvider::bardFormSet($handle)];
                }
                $this->skip('shortcode');

                return [];

            case 'spacer':
            case 'divider':
                return [];

            default:
                $this->skip($type === '' ? 'unknown' : $type);

                return [];
        }
    }

    /**
     * HTML -> Bard nodes, with <img> pointed at migrated assets.
     *
     * @return array<int, array<string, mixed>>
     */
    private function html(string $html, string $where = ''): array
    {
        if (trim(strip_tags($html, '<img><iframe>')) === '') {
            return [];
        }

        $doc = $this->augmentor->renderHtmlToProsemirror($html);

        return collect($doc['content'] ?? [])->map(fn ($n) => $this->fixImages($n, $where))->filter()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function fixImages(array $node, string $where): ?array
    {
        if (($node['type'] ?? '') === 'image') {
            $src = (string) ($node['attrs']['src'] ?? '');
            if ($path = $this->assetPath($src, $where, false)) {
                $node['attrs']['src'] = 'asset::'.$this->option('container').'::'.$path;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = collect($node['content'])->map(fn ($c) => $this->fixImages($c, $where))->filter()->values()->all();
        }

        return $node;
    }

    /** Uploads URL -> path of the migrated asset ("2024/06/photo.jpg"), trying the original if a size variant was used. */
    private function assetPath(string $url, string $where, bool $required = true): ?string
    {
        if ($url === '') {
            return null;
        }

        $path = str_starts_with($url, $this->uploadsBase) ? Str::after($url, $this->uploadsBase) : null;
        if ($path !== null) {
            $path = urldecode(Str::before(Str::before($path, '?'), '#'));
            foreach (array_unique([$path, (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $path)]) as $candidate) {
                if ($candidate !== '' && Asset::find($this->option('container').'::'.$candidate)) {
                    return $candidate;
                }
            }
        }

        $this->unresolved[] = "{$where}: {$url}".($required ? ' (block skipped)' : ' (kept as external image)');

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function set(string $type, array $values): array
    {
        return [
            'type' => 'set',
            'attrs' => [
                'id' => Str::lower(Str::random(8)),
                'values' => ['type' => $type, ...$values],
            ],
        ];
    }

    private function skip(string $type): void
    {
        $this->skipped[$type] = ($this->skipped[$type] ?? 0) + 1;
    }

    private function findEntry(string $wpId): ?EntryContract
    {
        foreach ([$wpId, (int) $wpId] as $value) {
            $entry = Entry::query()
                ->where('collection', $this->option('collection'))
                ->where('wp_id', $value)
                ->first();

            if ($entry) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int, mixed>|null */
    private function decode(string $raw): ?array
    {
        foreach ([$raw, stripslashes($raw)] as $candidate) {
            $data = json_decode($candidate, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function rows(string $relative): \Generator
    {
        $path = is_file($relative) ? $relative : base_path($relative);
        $fh = is_file($path) ? fopen($path, 'rb') : false;

        if ($fh === false) {
            $this->error("Cannot read {$path}");

            return;
        }

        $header = array_map(fn ($h) => trim((string) $h, "\xEF\xBB\xBF \t"), fgetcsv($fh, null, ',', '"', '') ?: []);

        while (($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
            yield array_combine($header, array_slice(array_pad($line, count($header), ''), 0, count($header)));
        }

        fclose($fh);
    }
}
