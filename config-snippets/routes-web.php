<?php
// MERGE INTO: routes/web.php
// Public author profiles for the Nuxt build (Bearer token protected, no session needed).

use App\Http\Controllers\AuthorsController;
use Illuminate\Support\Facades\Route;

Route::get('cms-api/authors', AuthorsController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('cms.authors');
