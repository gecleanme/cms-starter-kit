# Applying this starter kit (one time)

Placeholders used everywhere in this kit — search and replace them before committing:

| Placeholder        | Replace with                                   | Example                 |
|--------------------|-------------------------------------------------|-------------------------|
| `company-name`     | your company / domain name (no TLD)             | `contoso`               |
| `company-name.com` | the public marketing domain                     | `contoso.com`           |
| `project-name`     | the project prefix used for repos and sites     | `contoso-site`          |
| `project_name_cms` | database + DB user name (underscores, no dash)  | `contoso_site_cms`      |

Find every placeholder that is left (PowerShell, from the project folder):

```powershell
Get-ChildItem -Recurse -File -Exclude *.lock | Select-String -Pattern 'company-name|project-name|project_name' | Select-Object Path, LineNumber, Line
```

## Steps (PowerShell, Windows + Herd)

Assumes the new Statamic project is at `C:\Code\project-name-cms` and this kit was unzipped to `C:\Code\cms-starter-kit`.

1. Copy the kit over the project (this overwrites `README.md` and `app/Providers/AppServiceProvider.php` in the brand-new project only — nothing else exists there yet):
   ```powershell
   robocopy C:\Code\cms-starter-kit C:\Code\project-name-cms /E /XF STARTER-KIT.md
   ```
   `robocopy` prints a summary table; exit codes 0–3 mean success.
2. Merge the env additions into `.env.example` and `.env`. The tool replaces keys that already exist instead of adding duplicates, and backs the file up first:
   ```powershell
   cd C:\Code\project-name-cms
   php tools/env-merge.php .env.example.additions .env            # preview
   php tools/env-merge.php .env.example.additions .env --write
   php tools/env-merge.php .env.example.additions .env.example --write
   Remove-Item .env.example.additions
   ```
   Then set `STATAMIC_API_AUTH_TOKEN` in `.env` to the output of `php -r "echo bin2hex(random_bytes(32));"`.
3. Merge each file in `config-snippets/` into the file named in its header comment (api, filesystems, services, cors, bootstrap/app, routes/web, User model casts, composer scripts). Each snippet says exactly where it goes.
4. Add to `.gitignore`:
   ```
   /storage/app/import
   /storage/app/public/assets
   ```
5. Database storage + database users (Pro is already on via `STATAMIC_PRO_ENABLED=true` from step 2):
   ```powershell
   php please install:eloquent-driver --repositories=entries,collection_trees,terms,global_variables,nav_trees,form_submissions,revisions,tokens
   php please auth:migration
   php artisan migrate
   php please stache:warm
   ```
6. Check everything:
   ```powershell
   php artisan cms:doctor
   ```
   Read-only. Every row should say OK (WARN rows are fine locally).
7. Edit `database/seed/globals.json` (replace the `company-name` values), then:
   ```powershell
   php artisan cms:seed-globals          # dry run: shows what would change
   php artisan cms:seed-globals --write  # saves; only fills fields that are empty
   ```
8. Optional: copy `pint.json` from the SaaS repo; `composer require --dev laravel/pint larastan/larastan`.
9. Commit: `git add -A; git commit -m "Statamic 6 skeleton + starter kit"`.

## Commands this kit adds

| Command | What it does | Changes data? |
|---|---|---|
| `cms:doctor` | Checks every setting the guides rely on | No |
| `cms:seed-globals` | Fills empty global fields from `database/seed/globals.json` | Only with `--write` |
| `wp:pull` | Reads WordPress (REST API) into CSVs under `storage/app/import` | No (reads WordPress, writes local CSVs) |
| `wp:import-users` | Creates/updates CP users (authors) from `users.csv` | Yes (users only, matched by email) |
| `wp:media-meta` | Fills asset alt/caption/credit from `media.csv` | Yes (only empty fields unless `--force`) |
| `wp:rewrite-upload-links` | Points rich-text links to `/wp-content/uploads/...` at migrated assets | Only with `--write` |
| `php tools/env-merge.php` | Merges env keys without duplicates, backup in `storage/app/env-backups/` | Only with `--write` |
| `php tools/csv-split.php` | Splits a big CSV into parts for the Importer upload | No (writes new part files) |

Verified against source (Oct 2026): statamic/importer `Gutenberg` hook payload keys and closure binding; eloquent-driver
repository names for `--repositories` and config keys; `pro:enable` writing `STATAMIC_PRO_ENABLED`; Statamic form AJAX
responses. Not executed end-to-end (Packagist was unreachable from the build environment), so run `php artisan cms:doctor`
and open each blueprint in the CP once to confirm.
