<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * Creates/updates control-panel users (= authors, like WordPress) from:
 *   - users.csv          written by wp:pull  (WordPress users)
 *   - guest_authors.csv  from WP All Export        (Co-Authors Plus guest authors; optional)
 *
 * Why not the Importer's "Users" type: it can't set roles (User::merge() drops them) and needs
 * every field mapped by hand. This command is idempotent: users are matched by email, then author_slug.
 *
 * Nobody gets a password. Staff set one via "Forgot password" (needs MAIL_*), or an admin sets it in the CP.
 *
 *   php artisan wp:import-users storage/app/import/users.csv
 *   php artisan wp:import-users storage/app/import/users.csv --guests=storage/app/import/guest_authors.csv
 */
final class WpImportUsers extends Command
{
    protected $signature = 'wp:import-users
        {csv : users.csv from wp:pull}
        {--guests= : guest_authors.csv from WP All Export (columns: slug, display_name, email, description)}
        {--sync-roles : Re-apply mapped roles to existing users (default: only new users get roles)}
        {--force : Overwrite profile fields (bio, job title) that were already edited in Statamic}';

    protected $description = 'Import WordPress users and Co-Authors Plus guests as Statamic users (authors).';

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        foreach ($this->rows((string) $this->argument('csv')) as $row) {
            if (($row['statamic_role'] ?? '') === 'skip') {
                $this->skipped++; // e.g. subscribers with no posts

                continue;
            }

            $this->upsert(
                email: trim((string) ($row['email'] ?? '')),
                name: (string) ($row['name'] ?? ''),
                slug: (string) ($row['author_slug'] ?? ''),
                bio: (string) ($row['bio'] ?? ''),
                role: (string) ($row['statamic_role'] ?? ''),
                wpUserId: (int) ($row['wp_user_id'] ?? 0) ?: null,
                guest: false,
            );
        }

        if ($guests = $this->option('guests')) {
            foreach ($this->rows((string) $guests) as $row) {
                $slug = Str::after((string) ($row['slug'] ?? ''), 'cap-');

                $this->upsert(
                    email: trim((string) ($row['email'] ?? '')),
                    name: (string) ($row['display_name'] ?? $row['title'] ?? $slug),
                    slug: $slug,
                    bio: (string) ($row['description'] ?? ''),
                    role: '',
                    wpUserId: null,
                    guest: true,
                );
            }
        }

        $this->table(['Created', 'Updated', 'Skipped'], [[$this->created, $this->updated, $this->skipped]]);
        $this->line('Next: admins set passwords (CP > Users > user > Send password reset / set password).');

        return self::SUCCESS;
    }

    private function upsert(string $email, string $name, string $slug, string $bio, string $role, ?int $wpUserId, bool $guest): void
    {
        $slug = Str::slug($slug);

        if ($slug === '' && $email === '') {
            $this->skipped++;

            return;
        }

        // Guests (and WP users pulled without admin rights) may have no email; Statamic requires one.
        // RFC 2606 ".invalid" can never receive mail, so it is obviously a placeholder.
        $email = $email !== '' ? Str::lower($email) : "{$slug}@authors.invalid";

        /** @var UserContract|null $user */
        $user = User::findByEmail($email)
            ?? ($slug !== '' ? User::query()->where('author_slug', $slug)->first() : null);

        $isNew = $user === null;
        $user ??= User::make()->email($email);

        $user->set('name', $name !== '' ? $name : ($user->name() ?: $slug));

        if ($slug !== '' && (blank($user->get('author_slug')) || $this->option('force'))) {
            $user->set('author_slug', $slug);
        }

        if ($bio !== '' && (blank($user->get('bio')) || $this->option('force'))) {
            $user->set('bio', trim(strip_tags($bio)));
        }

        if ($wpUserId) {
            $user->set('wp_user_id', $wpUserId);
        }

        $user->set('is_guest', $guest);

        if (! $guest && $role !== '' && ($isNew || $this->option('sync-roles'))) {
            $user->assignRole($role);
        }

        $user->save();
        $isNew ? $this->created++ : $this->updated++;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function rows(string $relative): \Generator
    {
        $path = base_path($relative);
        $fh = is_file($path) ? fopen($path, 'rb') : false;

        if ($fh === false) {
            $this->error("Cannot read {$path}");

            return;
        }

        $header = array_map(fn ($h) => Str::snake(trim((string) $h, "\xEF\xBB\xBF \t")), fgetcsv($fh, null, ',', '"', '') ?: []);

        while (($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
            yield array_combine($header, array_slice(array_pad($line, count($header), ''), 0, count($header)));
        }

        fclose($fh);
    }
}
