<?php
// After `php artisan config:publish cors`, set in config/cors.php:

'paths' => ['api/*', '!/forms/*'],
'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
'allowed_origins' => array_values(array_filter([env('FRONTEND_URL'), 'http://localhost:3000'])),
'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept'],
'supports_credentials' => false,
