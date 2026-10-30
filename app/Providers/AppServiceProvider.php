<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\QueueFrontendBuild;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;
use Statamic\Facades\GraphQL;
use Statamic\Importer\WordPress\Gutenberg;
use Statamic\Statamic;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Gravity Forms form ID (prod) => Statamic form handle.
     */
    public const GRAVITY_FORMS_MAP = [
        1 => 'sign_up',
        2 => 'contact',
        3 => 'job_application',
        4 => 'newsletter',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerFrontendRebuilds();
        $this->maskPrivateUserFields();

        Statamic::booted(function (): void {
            $this->registerGutenbergHooks();
        });
    }

    /**
     * Any content change that affects the public site queues ONE debounced Nuxt build.
     * The listener's handle() is untyped on purpose so Laravel's event discovery does not register it twice.
     */
    private function registerFrontendRebuilds(): void
    {
        Event::listen([
            EntrySaved::class,
            EntryDeleted::class,
            EntryScheduleReached::class,
            TermSaved::class,
            TermDeleted::class,
            GlobalVariablesSaved::class,
            NavTreeSaved::class,
            CollectionTreeSaved::class,
            UserSaved::class,    // author profiles (name, photo, bio) are shown on posts and /author/ pages
            UserDeleted::class,
        ], QueueFrontendBuild::class);
    }

    /**
     * GraphQL exposes every User field wherever a user appears (e.g. a post's `authors`).
     * Overriding the resolvers keeps emails and control-panel links out of every GraphQL response,
     * even for someone holding the API token. (REST does the same via 'excluded_keys' in config/statamic/api.php.)
     */
    private function maskPrivateUserFields(): void
    {
        foreach (['email', 'edit_url'] as $field) {
            GraphQL::addField('User', $field, fn () => [
                'type' => GraphQL::string(),
                'resolve' => fn () => null,
            ]);
        }
    }

    /**
     * Custom WordPress blocks -> Bard sets during import.
     * Core blocks (paragraph, heading, list, image, gallery, embed, table, quote, buttons...) are handled by the Importer.
     *
     * Payload (statamic/importer src/WordPress/Gutenberg.php): ['block' => [blockName, attrs, innerHTML, innerBlocks, ...],
     * 'config' => mapping config, 'blueprint' => Blueprint, 'field' => Field]. Return a Bard node to replace the block,
     * or $next($payload) to fall through to the Importer's default handling.
     *
     * IMPORTANT: Statamic binds hook closures to the Gutenberg instance, so `$this` inside them is NOT this provider.
     * That's why the helpers below are public static and called by class name.
     */
    private function registerGutenbergHooks(): void
    {
        if (! class_exists(Gutenberg::class)) {
            return; // Importer removed after migration - nothing to do.
        }

        // Gravity Forms block: <!-- wp:gravityforms/form {"formId":"2", ...} /-->
        Gutenberg::hook('gravityforms/form', function (array $payload, Closure $next) {
            $handle = AppServiceProvider::GRAVITY_FORMS_MAP[(int) ($payload['block']['attrs']['formId'] ?? 0)] ?? null;

            return $handle ? AppServiceProvider::bardFormSet($handle) : $next($payload);
        });

        // Shortcode block: <!-- wp:shortcode -->[gravityform id="2" title="false"]<!-- /wp:shortcode -->
        Gutenberg::hook('core/shortcode', function (array $payload, Closure $next) {
            $html = (string) ($payload['block']['innerHTML'] ?? '');

            if (preg_match('/\[gravityforms?\s+[^\]]*id=["\']?(\d+)/i', $html, $m)) {
                $handle = AppServiceProvider::GRAVITY_FORMS_MAP[(int) $m[1]] ?? null;

                if ($handle) {
                    return AppServiceProvider::bardFormSet($handle);
                }
            }

            return $next($payload);
        });
    }

    /**
     * A Bard "set" node using the `form` set defined in resources/fieldsets/rich_content.yaml.
     *
     * @return array<string, mixed>
     */
    public static function bardFormSet(string $handle): array
    {
        return [
            'type' => 'set',
            'attrs' => [
                'id' => Str::lower(Str::random(8)),
                'values' => [
                    'type' => 'form',
                    'form' => $handle,
                ],
            ],
        ];
    }
}
