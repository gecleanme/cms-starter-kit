<?php
// MERGE INTO: config/statamic/graphql.php  (Pro feature)
// The file exists in a fresh Statamic install. Replace its 'resources' and 'queries' arrays with these,
// keep everything else as published. The switch itself is .env: STATAMIC_GRAPHQL_ENABLED=true.
//
// Endpoint: POST https://<cms>/graphql   (body: {"query": "...", "variables": {...}})
// Auth:     header  Authorization: Bearer <STATAMIC_GRAPHQL_AUTH_TOKEN>   (separate from the REST token)
// Explorer: CP > GraphQL (GraphiQL), for logged-in users with the "view graphql" permission.

'resources' => [
    // Same handles and filter allow-lists as the REST API (config/statamic/api.php).
    'collections' => [
        'posts' => ['allowed_filters' => ['slug', 'uri', 'wp_id']],
        'pages' => ['allowed_filters' => ['slug', 'uri', 'wp_id']],
        'events' => ['allowed_filters' => ['slug', 'wp_id']],
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
    'forms' => true,     // form definitions, so Nuxt can render the fields
    'sites' => false,
    'users' => false,    // never list users; public author profiles come from the custom `authors` query below
],

'queries' => [
    \App\GraphQL\Queries\AuthorsQuery::class,   // authors(slug: "jane-doe") -> public author profiles, emails masked
],

// 'auth_token' already reads STATAMIC_GRAPHQL_AUTH_TOKEN. Set it on every server; leave it empty only locally
// if you want to try queries without a header. Introspection is "auto": on locally, off on servers.
