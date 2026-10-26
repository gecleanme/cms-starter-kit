<?php
// MERGE INTO: bootstrap/app.php
// The static Nuxt site can't hold a Laravel session, so Statamic form posts are excluded from CSRF.
// Spam protection = form honeypot + Nginx rate limit (deploy/nginx-snippet.conf), Turnstile later (P-21).

->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['!/forms/*']);
})
