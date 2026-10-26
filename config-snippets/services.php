<?php
// MERGE INTO: config/services.php

'wordpress' => [
    'base_url' => env('WP_BASE_URL'),               // https://www.company-name.com  (read-only export source)
    'user' => env('WP_API_USER'),
    'app_password' => env('WP_API_APP_PASSWORD'),   // WordPress Application Password, revocable
],

'frontend' => [
    'url' => env('FRONTEND_URL'),
    'deploy_hook' => env('FRONTEND_DEPLOY_HOOK_URL'),   // Forge deploy hook of the Nuxt site now, Vercel deploy hook later
    'builds_paused' => (bool) env('FRONTEND_BUILDS_PAUSED', false),
],
