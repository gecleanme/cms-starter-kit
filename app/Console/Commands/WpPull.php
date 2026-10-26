<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Facades\Entry;

/**
 * Read-only export of production WordPress (core REST API + Application Password) into
 * CSV files shaped for the Statamic Importer (Utilities > Importer).
 *
 * Never writes to WordPress. Never writes Statamic content (except reading wp_id for the orphan report).
 *
 *   php artisan wp:pull
 *   php artisan wp:pull --only=posts,categories --since=2026-10-01
 *   php artisan wp:pull --only=posts --limit=10          # small rehearsal file
 */
final class WpPull extends Command
{
    protected $signature = 'wp:pull
        {--only= : Comma list of: posts,pages,events,investors,press_releases,workloads,geo_pages,categories,tags,users,media,settings}
        {--since= : Only items modified after this date (Y-m-d). Terms, authors and the full media list are always pulled in full}
        {--limit= : Max items per post type (rehearsal files)}
        {--status=publish,future,draft,pending,private : WordPress statuses to pull}
        {--out=storage/app/import : Output directory}';

    protected $description = 'Export WordPress content via the REST API into Statamic Importer CSVs (read-only).';

    /** Statamic collection handle => WordPress REST base. */
    private const POST_TYPES = [
        'posts' => 'posts',
        'pages' => 'pages',
        'events' => 'event',
        'investors' => 'investor',
        'press_releases' => 'press-release',
        'workloads' => 'workload',
        'geo_pages' => 'geo-location-page',
    ];

    private const COLUMNS = [
        'wp_id', 'title', 'slug', 'published', 'status', 'date', 'modified',
        'author_slugs', 'categories', 'tags', 'excerpt', 'content', 'has_blocks',
        'featured_image_url', 'featured_image_alt', 'parent_wp_id', 'menu_order', 'wp_url',
        'seo_title', 'seo_description', 'seo_canonical', 'seo_noindex', 'og_title', 'og_description', 'og_image',
    ];

    private string $base;

    private string $out;

    /** @var array<string, array<int, string>> taxonomy => [term id => slug] */
    private array $termSlugs = ['categories' => [], 'tags' => []];

    /** @var array<int, string> user id => slug */
    private array $userSlugs = [];

    /** @var array<int, array{url: string, alt: string}> */
    private array $media = [];

    /** @var array<int, string> */
    private array $report = [];

    /** @var array<string, array<int, string>> collection => [wp_id => slug] (published only) */
    private array $publishedSlugs = [];

    public function handle(): int
    {
        $this->base = rtrim((string) config('services.wordpress.base_url'), '/');

        if ($this->base === '') {
            $this->error('Set WP_BASE_URL (and WP_API_USER / WP_API_APP_PASSWORD) in .env.');

            return self::FAILURE;
        }

        $this->out = base_path((string) $this->option('out'));
        if (! is_dir($this->out) && ! mkdir($this->out, 0775, true) && ! is_dir($this->out)) {
            throw new RuntimeException("Cannot create {$this->out}");
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $wants = fn (string $key): bool => $only === [] || in_array($key, $only, true);

        $this->report[] = 'wp:pull report - '.now()->toDateTimeString().' - '.$this->base;
        $this->report[] = 'Options: '.json_encode($this->options(), JSON_UNESCAPED_SLASHES);
        $this->report[] = '';

        // Lookups first: every post-type export needs them (always pulled in full).
        $this->pullSettings($wants('settings'));
        $this->pullTerms('categories', $wants('categories'));
        $this->pullTerms('tags', $wants('tags'));
        $this->pullUsers($wants('users'));
        $this->pullMedia($wants('media'));

        foreach (self::POST_TYPES as $handle => $restBase) {
            if ($wants($handle)) {
                $this->pullPostType($handle, $restBase);
            }
        }

        $this->reportCollisions();
        $this->reportOrphans($only);

        file_put_contents($this->out.'/report.txt', implode(PHP_EOL, $this->report).PHP_EOL);
        $this->info("Done. Files and report.txt are in {$this->out}");

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- lookups

    private function pullTerms(string $taxonomy, bool $write): void
    {
        $items = $this->fetchAll("/wp-json/wp/v2/{$taxonomy}", ['context' => 'view', 'hide_empty' => 'false']);

        foreach ($items as $term) {
            $this->termSlugs[$taxonomy][(int) $term['id']] = (string) $term['slug'];
        }

        if (! $write) {
            return;
        }

        $rows = array_map(fn (array $t): array => [
            'wp_id' => $t['id'],
            'title' => $this->decode($t['name'] ?? ''),
            'slug' => $t['slug'] ?? '',
            'description' => trim(strip_tags((string) ($t['description'] ?? ''))),
            'parent_slug' => isset($t['parent']) && $t['parent'] ? ($this->termSlugs[$taxonomy][(int) $t['parent']] ?? '') : '',
            'count' => $t['count'] ?? 0,
            'wp_url' => $t['link'] ?? '',
        ] + $this->seoColumns($t, (string) ($t['link'] ?? '')), $items);

        $this->writeCsv("{$taxonomy}.csv", $rows);
        $this->report[] = sprintf('%-16s %5d terms', $taxonomy, count($rows));
    }

    /** WordPress role => Statamic role (resources/users/roles.yaml). "skip" = not imported. */
    private const ROLE_MAP = [
        'administrator' => 'admin',
        'editor' => 'editor',
        'author' => 'editor',
        'contributor' => 'editor', // WP contributors couldn't publish; review in CP (report lists them)
        'wpseo_manager' => 'marketing',
        'wpseo_editor' => 'marketing',
    ];

    /**
     * Site name, tagline and timezone from the public REST index (/wp-json).
     */
    private function pullSettings(bool $write): void
    {
        if (! $write) {
            return;
        }

        $index = (array) $this->request(false)->get($this->base.'/wp-json')->json();
        $settings = Arr::only($index, ['name', 'description', 'url', 'home', 'gmt_offset', 'timezone_string']);
        file_put_contents($this->out.'/settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $tz = ($settings['timezone_string'] ?? '') ?: 'UTC'.(($settings['gmt_offset'] ?? 0) >= 0 ? '+' : '').($settings['gmt_offset'] ?? 0);
        $this->report[] = "settings         site \"{$this->decode((string) ($settings['name'] ?? ''))}\", timezone {$tz}";
        $this->report[] = "                 -> set 'display_timezone' => '{$tz}' in config/statamic/system.php (keep APP_TIMEZONE=UTC; dates are exported in UTC)";
    }

    /**
     * Users = authors (Statamic CP users, like WordPress).
     * With an ADMIN application password, context=edit returns emails and roles for every user.
     * Otherwise falls back to the public list (users with published posts, no email/role).
     */
    private function pullUsers(bool $write): void
    {
        $public = $this->fetchAll('/wp-json/wp/v2/users', ['context' => 'view']);
        $authorIds = [];

        foreach ($public as $user) {
            $this->userSlugs[(int) $user['id']] = (string) $user['slug'];
            $authorIds[(int) $user['id']] = true;
        }

        if (! $write) {
            return;
        }

        $full = null;
        try {
            $full = $this->fetchAll('/wp-json/wp/v2/users', ['context' => 'edit'], auth: true);
        } catch (RuntimeException $e) {
            $this->warn('users: no context=edit access (needs an Administrator application password) - emails/roles will be missing.');
        }

        $source = $full ?? $public;
        $contributors = [];

        $rows = array_map(function (array $u) use ($authorIds, &$contributors): array {
            $this->userSlugs[(int) $u['id']] = (string) $u['slug'];
            $wpRoles = (array) ($u['roles'] ?? []);
            $mapped = collect($wpRoles)->map(fn ($r) => self::ROLE_MAP[$r] ?? null)->filter()->first();
            $isAuthor = isset($authorIds[(int) $u['id']]);

            if (in_array('contributor', $wpRoles, true)) {
                $contributors[] = (string) $u['slug'];
            }

            return [
                'wp_user_id' => $u['id'],
                'email' => (string) ($u['email'] ?? ''),
                'name' => $this->decode((string) ($u['name'] ?? '')),
                // Only people who publish get a public author page.
                'author_slug' => $isAuthor ? (string) ($u['slug'] ?? '') : '',
                'bio' => trim(strip_tags((string) ($u['description'] ?? ''))),
                'wp_roles' => implode('|', $wpRoles),
                // No CP role for subscribers etc.; keep them only if they authored posts.
                'statamic_role' => $mapped ?? ($isAuthor ? '' : ($full ? 'skip' : '')),
                'has_published_posts' => $isAuthor ? 'true' : 'false',
            ];
        }, $source);

        $this->writeCsv('users.csv', $rows);
        $this->report[] = sprintf('%-16s %5d (%s)%s', 'users', count($rows),
            $full ? 'all users, with emails and roles' : 'PUBLIC authors only - no emails/roles; rerun with an admin app password',
            $contributors ? ' | WP contributors mapped to editor: '.implode(', ', $contributors) : '');
    }

    private function pullMedia(bool $write): void
    {
        if (! $write) {
            return; // Featured images are resolved on demand in resolveMedia().
        }

        $items = $this->fetchAll('/wp-json/wp/v2/media', ['context' => 'view']);
        $uploads = $this->base.'/wp-content/uploads/';

        $rows = array_map(function (array $m) use ($uploads): array {
            $url = (string) ($m['source_url'] ?? '');
            $path = (string) Arr::get($m, 'media_details.file', Str::after($url, $uploads));
            $this->media[(int) $m['id']] = ['url' => $url, 'alt' => (string) ($m['alt_text'] ?? '')];

            return [
                'wp_id' => $m['id'],
                'url' => $url,
                'path' => ltrim($path, '/'),
                'alt' => (string) ($m['alt_text'] ?? ''),
                'caption' => trim(strip_tags((string) Arr::get($m, 'caption.rendered', ''))),
                'title' => $this->decode((string) Arr::get($m, 'title.rendered', '')),
                'mime' => $m['mime_type'] ?? '',
                'width' => Arr::get($m, 'media_details.width', ''),
                'height' => Arr::get($m, 'media_details.height', ''),
            ];
        }, $items);

        $this->writeCsv('media.csv', $rows);
        $this->report[] = sprintf('%-16s %5d items', 'media', count($rows));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function resolveMedia(array $ids): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($ids)), array_keys($this->media)));

        foreach (array_chunk($missing, 100) as $chunk) {
            $items = $this->fetchAll('/wp-json/wp/v2/media', ['context' => 'view', 'include' => implode(',', $chunk)]);

            foreach ($items as $m) {
                $this->media[(int) $m['id']] = ['url' => (string) ($m['source_url'] ?? ''), 'alt' => (string) ($m['alt_text'] ?? '')];
            }
        }
    }

    // ---------------------------------------------------------------- post types

    private function pullPostType(string $handle, string $restBase): void
    {
        $query = ['context' => 'edit', 'status' => (string) $this->option('status'), 'orderby' => 'id', 'order' => 'asc'];

        if ($since = $this->option('since')) {
            $query['modified_after'] = Carbon::parse((string) $since)->startOfDay()->toIso8601String();
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        try {
            $items = $this->fetchAll("/wp-json/wp/v2/{$restBase}", $query, $limit, auth: true);
        } catch (RuntimeException $e) {
            $this->warn("Skipping {$handle}: {$e->getMessage()}");
            $this->report[] = sprintf('%-16s SKIPPED (%s)', $handle, $e->getMessage());

            return;
        }

        $this->resolveMedia(array_map(fn (array $i): int => (int) ($i['featured_media'] ?? 0), $items));

        // Yoast's yoast_head_json is normally present in every context. If not, fetch it for published items in view context.
        $seo = [];
        if ($items !== [] && ! array_key_exists('yoast_head_json', $items[0])) {
            foreach ($this->fetchAll("/wp-json/wp/v2/{$restBase}", ['context' => 'view', '_fields' => 'id,yoast_head_json']) as $v) {
                $seo[(int) $v['id']] = $v;
            }
        }

        $rows = [];
        $statuses = [];
        $noBlocks = 0;

        foreach ($items as $item) {
            $row = $this->mapItem($item, $seo[(int) $item['id']] ?? $item);
            $rows[] = $row;
            $statuses[$row['status']] = ($statuses[$row['status']] ?? 0) + 1;
            $noBlocks += $row['has_blocks'] === 'false' && $row['content'] !== '' ? 1 : 0;

            if ($row['status'] === 'publish') {
                $this->publishedSlugs[$handle][(int) $row['wp_id']] = $row['slug'];
            }
        }

        $this->writeCsv("{$handle}.csv", $rows, self::COLUMNS);

        ksort($statuses);
        $this->report[] = sprintf(
            '%-16s %5d  [%s]  no-block-markup: %d',
            $handle,
            count($rows),
            collect($statuses)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', '),
            $noBlocks,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $seoSource
     * @return array<string, string|int>
     */
    private function mapItem(array $item, array $seoSource): array
    {
        $status = (string) ($item['status'] ?? '');
        $title = $this->decode((string) (Arr::get($item, 'title.raw') ?? Arr::get($item, 'title.rendered', '')));
        $content = (string) (Arr::get($item, 'content.raw') ?? Arr::get($item, 'content.rendered', ''));
        $excerpt = (string) (Arr::get($item, 'excerpt.raw') ?? strip_tags((string) Arr::get($item, 'excerpt.rendered', '')));
        $slug = (string) ($item['slug'] ?? '');
        $featured = $this->media[(int) ($item['featured_media'] ?? 0)] ?? ['url' => '', 'alt' => ''];

        return [
            'wp_id' => (int) $item['id'],
            'title' => $title,
            // Drafts may have no slug yet in WordPress; derive one so the Importer can create them.
            'slug' => $slug !== '' ? $slug : Str::slug($title ?: 'untitled-'.$item['id']),
            // "future" = scheduled: published + future date -> Statamic treats it as scheduled.
            'published' => in_array($status, ['publish', 'future'], true) ? 'true' : 'false',
            'status' => $status,
            // WordPress *_gmt fields are UTC without an offset; drafts may have none, then fall back to local `date`.
            'date' => $this->utc($item['date_gmt'] ?? null, $item['date'] ?? null),
            'modified' => $this->utc($item['modified_gmt'] ?? null, $item['modified'] ?? null),
            'author_slugs' => (string) ($this->userSlugs[(int) ($item['author'] ?? 0)] ?? ''),
            'categories' => $this->slugs('categories', (array) ($item['categories'] ?? [])),
            'tags' => $this->slugs('tags', (array) ($item['tags'] ?? [])),
            'excerpt' => trim($excerpt),
            'content' => $content,
            'has_blocks' => str_contains($content, '<!-- wp:') ? 'true' : 'false',
            'featured_image_url' => $featured['url'],
            'featured_image_alt' => $featured['alt'],
            'parent_wp_id' => (int) ($item['parent'] ?? 0) ?: '',
            'menu_order' => (int) ($item['menu_order'] ?? 0),
            'wp_url' => (string) ($item['link'] ?? ''),
        ] + $this->seoColumns($seoSource, (string) ($item['link'] ?? ''));
    }

    /**
     * Yoast's resolved output (no %%title%% variables).
     *
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private function seoColumns(array $source, string $ownUrl = ''): array
    {
        $y = (array) ($source['yoast_head_json'] ?? []);
        $canonical = (string) ($y['canonical'] ?? '');

        return [
            'seo_title' => $this->decode((string) ($y['title'] ?? '')),
            'seo_description' => $this->decode((string) ($y['description'] ?? '')),
            // Only keep a canonical that points somewhere else; self-canonicals are rebuilt by Nuxt.
            'seo_canonical' => $canonical !== '' && rtrim($canonical, '/') !== rtrim($ownUrl, '/') ? $canonical : '',
            'seo_noindex' => (($y['robots']['index'] ?? 'index') === 'noindex') ? 'true' : 'false',
            'og_title' => $this->decode((string) ($y['og_title'] ?? '')),
            'og_description' => $this->decode((string) ($y['og_description'] ?? '')),
            'og_image' => (string) Arr::get($y, 'og_image.0.url', ''),
        ];
    }

    // ---------------------------------------------------------------- report

    private function reportCollisions(): void
    {
        $pages = array_flip($this->publishedSlugs['pages'] ?? []);
        $posts = array_flip($this->publishedSlugs['posts'] ?? []);
        $clash = array_keys(array_intersect_key($pages, $posts));

        $this->report[] = '';
        $this->report[] = 'Page/post slug collisions (both route at /{slug}): '.(count($clash) ?: 'none');
        foreach ($clash as $slug) {
            $this->report[] = "  - /{$slug}/";
        }
    }

    /**
     * @param  array<int, string>  $only
     */
    private function reportOrphans(array $only): void
    {
        if ($this->option('since') || $this->option('limit')) {
            $this->report[] = 'Orphan check skipped (partial pull).';

            return;
        }

        $this->report[] = '';
        $this->report[] = 'Orphans (in Statamic with a wp_id, no longer returned by WordPress):';

        foreach (array_keys(self::POST_TYPES) as $handle) {
            if ($only !== [] && ! in_array($handle, $only, true)) {
                continue;
            }

            $pulled = array_flip(array_map('intval', array_column($this->readCsvColumn("{$handle}.csv", 'wp_id'), 0)));

            $orphans = Entry::query()->where('collection', $handle)->get()
                ->filter(fn ($e) => $e->get('wp_id') && ! isset($pulled[(int) $e->get('wp_id')]))
                ->map(fn ($e) => "  - {$handle}: {$e->get('title')} (wp_id {$e->get('wp_id')}, {$e->id()})");

            foreach ($orphans as $line) {
                $this->report[] = $line;
            }
        }
    }

    // ---------------------------------------------------------------- http + csv helpers

    /**
     * Fetch every page of a collection endpoint (100 per page, X-WP-TotalPages).
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $path, array $query = [], ?int $limit = null, bool $auth = false): array
    {
        $results = [];
        $page = 1;

        do {
            $response = $this->request($auth)->get($this->base.$path, $query + ['per_page' => 100, 'page' => $page]);

            if ($response->status() === 400 && $page > 1) {
                break; // rest_post_invalid_page_number
            }

            $this->guard($response, $path);

            $results = array_merge($results, (array) $response->json());
            $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);
            $this->output->write('.');

            if ($limit !== null && count($results) >= $limit) {
                return array_slice($results, 0, $limit);
            }

            $page++;
        } while ($page <= $totalPages);

        $this->newLine();

        return $results;
    }

    private function request(bool $auth): PendingRequest
    {
        $request = Http::acceptJson()
            ->withUserAgent('cms-wp-pull/1.0 (+statamic migration)')
            ->timeout(60)
            ->retry(3, 2000, throw: false);

        $user = (string) config('services.wordpress.user');
        $password = (string) config('services.wordpress.app_password');

        return $auth && $user !== '' ? $request->withBasicAuth($user, $password) : $request;
    }

    private function guard(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $hint = match ($response->status()) {
            401 => 'check WP_API_USER / WP_API_APP_PASSWORD (Application Password, not the login password)',
            403 => 'the user lacks permission for context=edit, or Cloudflare/WP Engine blocked the request (see Phase 0)',
            404 => 'endpoint not found - is the post type exposed in REST (show_in_rest)?',
            default => 'unexpected response',
        };

        throw new RuntimeException("{$path} returned {$response->status()}: {$hint}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>|null  $columns
     */
    private function writeCsv(string $file, array $rows, ?array $columns = null): void
    {
        $columns ??= $rows === [] ? ['wp_id'] : array_keys($rows[0]);
        $fh = fopen($this->out.'/'.$file, 'wb');

        if ($fh === false) {
            throw new RuntimeException("Cannot write {$file}");
        }

        fputcsv($fh, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn (string $c) => (string) ($row[$c] ?? ''), $columns), ',', '"', '');
        }
        fclose($fh);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsvColumn(string $file, string $column): array
    {
        $path = $this->out.'/'.$file;
        if (! is_file($path) || ($fh = fopen($path, 'rb')) === false) {
            return [];
        }

        $header = fgetcsv($fh, null, ',', '"', '') ?: [];
        $index = array_search($column, $header, true);
        $values = [];

        while ($index !== false && ($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $values[] = [(string) ($line[$index] ?? '')];
        }
        fclose($fh);

        return $values;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function slugs(string $taxonomy, array $ids): string
    {
        return collect($ids)->map(fn ($id) => $this->termSlugs[$taxonomy][(int) $id] ?? null)->filter()->implode('|');
    }

    private function utc(?string $gmt, ?string $local): string
    {
        $value = $gmt && ! str_starts_with($gmt, '0000') ? Carbon::parse($gmt, 'UTC') : Carbon::parse($local ?: 'now');

        return $value->utc()->format('Y-m-d H:i:s'); // keep APP_TIMEZONE=UTC so the Importer reads this as UTC
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
