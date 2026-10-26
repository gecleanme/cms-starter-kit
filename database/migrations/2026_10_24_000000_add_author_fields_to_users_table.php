<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statamic's Eloquent users store each blueprint field as a column on `users`
 * (Statamic\Auth\Eloquent\User::set() writes model attributes), so the author-profile
 * fields in resources/blueprints/user.yaml need real columns. `avatar` already exists
 * (added by `php please auth:migration`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('author_slug')->nullable()->unique()->after('name');
            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();
            $table->json('socials')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->unsignedBigInteger('wp_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['author_slug']);
            $table->dropColumn(['author_slug', 'job_title', 'bio', 'socials', 'is_guest', 'wp_user_id']);
        });
    }
};
