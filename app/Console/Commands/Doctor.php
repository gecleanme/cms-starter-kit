<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Throwable;

/**
 * Read-only health check: verifies every setting the setup guides rely on.
 * Changes nothing. Run it after setup and after every .env edit:
 *
 *   php artisan cms:doctor
 */
final class Doctor extends Command
{
    protected $signature = 'cms:doctor';

    protected $description = 'Check the CMS configuration used by the migration guides (read-only).';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private int $fails = 0;

    public function handle(): int
    {
        $this->check('Statamic Pro enabled', config('statamic.editions.pro') === true, 'STATAMIC_PRO_ENABLED=true in .env (edit the existing line; do not add a second one)');
        $this->check('REST API enabled', (bool) config('statamic.api.enabled'), 'STATAMIC_API_ENABLED=true');
        $this->check('API token set', filled(config('statamic.api.auth_token')), 'STATAMIC_API_AUTH_TOKEN=<long random string>');
        $this->check('API exposes posts', is_array(config('statamic.api.resources.collections')) && array_key_exists('posts', (array) config('statamic.api.resources.collections')), 'merge config-snippets/statamic-api.php');
        $this->check('API hides emails', in_array('email', (array) config('statamic.api.excluded_keys'), true), "'excluded_keys' => ['email', 'edit_url'] in config/statamic/api.php");
        $this->check('Revisions enabled', (bool) config('statamic.revisions.enabled'), 'STATAMIC_REVISIONS_ENABLED=true');
        $this->check('Git automation off', ! config('statamic.git.enabled'), 'STATAMIC_GIT_ENABLED=false');
        $this->check('Users stored in database', config('statamic.users.repository') === 'eloquent', "'repository' => 'eloquent' in config/statamic/users.php");

        foreach (['entries', 'collection_trees', 'terms', 'global_set_variables', 'navigation_trees', 'form_submissions', 'revisions', 'tokens'] as $repo) {
            $this->check("DB storage: {$repo}", config("statamic.eloquent-driver.{$repo}.driver") === 'eloquent', 'php please install:eloquent-driver --repositories=... (guide step)');
        }
        foreach (['collections', 'blueprints', 'fieldsets', 'taxonomies', 'global_sets', 'navigations', 'forms'] as $repo) {
            $this->check("Git storage: {$repo}", config("statamic.eloquent-driver.{$repo}.driver", 'file') === 'file', "set '{$repo}' driver back to 'file' in config/statamic/eloquent-driver.php");
        }

        $this->check('Assets disk defined', is_array(config('filesystems.disks.assets')), 'merge config-snippets/filesystems.php');
        $this->check('App timezone is UTC', config('app.timezone') === 'UTC', 'APP_TIMEZONE=UTC (dates are imported in UTC)');
        $this->warnIf('Display timezone set', filled(config('statamic.system.display_timezone')), "set 'display_timezone' in config/statamic/system.php (wp:pull report shows the WordPress timezone)");
        $this->warnIf('Queue uses database', config('queue.default') === 'database', 'QUEUE_CONNECTION=database');
        $this->check('Form CORS configured', in_array('!/forms/*', (array) config('cors.paths'), true), 'merge config-snippets/cors.php');
        $this->check('Authors route registered', Route::has('cms.authors'), 'merge config-snippets/routes-web.php');
        $this->warnIf('WordPress source set', filled(config('services.wordpress.base_url')), 'WP_BASE_URL (only needed for imports)');
        $this->warnIf('Front-end deploy hook set', filled(config('services.frontend.deploy_hook')), 'FRONTEND_DEPLOY_HOOK_URL (empty is fine locally)');

        foreach (['posts', 'pages', 'events', 'investors', 'press_releases', 'workloads', 'geo_pages', 'redirects'] as $c) {
            $this->check("Collection {$c}", Collection::handleExists($c), 'copy the starter kit content/ folder, then php please stache:warm');
        }
        foreach (['site_settings', 'newsletter_cta', 'tracking', 'careers'] as $g) {
            $this->check("Global {$g}", (bool) GlobalSet::findByHandle($g), 'copy the starter kit content/ folder, then php please stache:warm');
        }

        $this->database();

        $this->table(['Check', 'Result', 'Fix'], $this->rows);

        if ($this->fails > 0) {
            $this->error("{$this->fails} check(s) failed. Fix them before continuing.");

            return self::FAILURE;
        }

        $this->info('All required checks passed.');

        return self::SUCCESS;
    }

    private function database(): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            $name = DB::connection()->getDatabaseName();
            $this->rows[] = ['Database in use', "{$driver}: {$name}", 'Must be the CMS database, never the SaaS one'];

            $this->check('Author columns on users', Schema::hasColumn('users', 'author_slug'), 'php artisan migrate');
            $this->check('Statamic user columns', Schema::hasColumn('users', 'super'), 'php please auth:migration && php artisan migrate');

            if ($driver === 'mysql') {
                $grants = collect(DB::select('SHOW GRANTS'))->map(fn ($g) => (string) array_values((array) $g)[0])->implode(' | ');
                $global = (bool) preg_match('/GRANT\s+(ALL|.*\b(CREATE|DROP|ALTER|INSERT|UPDATE|DELETE)\b).*\bON\s+\*\.\*/i', $grants);
                $this->warnIf('DB user limited to its own database', ! $global, 'Use a MySQL user that only has rights on the CMS database (Forge: Databases > user > only this database)');
            }
        } catch (Throwable $e) {
            $this->check('Database reachable', false, $e->getMessage());
        }
    }

    private function check(string $label, bool $ok, string $fix): void
    {
        $this->rows[] = [$label, $ok ? '<info>OK</info>' : '<error>FAIL</error>', $ok ? '' : $fix];
        $this->fails += $ok ? 0 : 1;
    }

    private function warnIf(string $label, bool $ok, string $fix): void
    {
        $this->rows[] = [$label, $ok ? '<info>OK</info>' : '<comment>WARN</comment>', $ok ? '' : $fix];
    }
}
