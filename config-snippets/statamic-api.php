<?php
// MERGE INTO: config/statamic/api.php  (Pro feature; enabled via STATAMIC_API_ENABLED=true)
// Replace the 'resources' array with this one. Keep the rest of the file as published.

'resources' => [
    'collections' => [
        'posts' => ['allowed_filters' => ['slug', 'uri', 'wp_id', 'taxonomy:categories', 'taxonomy:tags', 'authors']],
        'pages' => ['allowed_filters' => ['slug', 'uri', 'wp_id']],
        'events' => ['allowed_filters' => ['slug', 'wp_id', 'start_date']],
        'investors' => ['allowed_filters' => ['slug', 'wp_id']],
        'press_releases' => ['allowed_filters' => ['slug', 'wp_id']],
        'workloads' => ['allowed_filters' => ['slug', 'uri', 'wp_id']],
        'geo_pages' => ['allowed_filters' => ['slug', 'uri', 'wp_id']],
        'redirects' => true,
    ],
    'navs' => true,
    'taxonomies' => [
        'categories' => ['allowed_filters' => ['slug']],
        'tags' => ['allowed_filters' => ['slug']],
    ],
    'assets' => ['assets' => true],
    'globals' => true,
    'forms' => true,   // form definitions (fields, validation) so Nuxt can render forms
    'sites' => false,
    'users' => false,  // never expose users
],

// Taxonomy filters use the form filter[taxonomy:categories]=news (comma = any of). Unpublished entries are
// excluded automatically unless a 'status'/'published' filter is allowed - so never allow-list those.
// 'auth_token' already reads STATAMIC_API_AUTH_TOKEN - set it, and Nuxt sends `Authorization: Bearer <token>`.
// Applied to every augmented item in API responses (verified in Statamic\Data\AbstractAugmented):
// 'email' keeps user emails out of nested `authors` on posts; author profiles come from /cms-api/authors.
'excluded_keys' => ['email', 'edit_url'],
