# Forge > project-name-cms site > Deployments > Deploy script (zero-downtime site)
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize          # config/route/view/event caches live in this release only
$FORGE_PHP artisan storage:link
$FORGE_PHP please stache:warm        # blueprints, collections, forms (from git)

# No optimize:clear (it runs cache:clear on the cache shared with the live release).
# No npm build (the Statamic control panel ships pre-built).

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
