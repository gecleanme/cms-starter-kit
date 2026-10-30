<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use GraphQL\Type\Definition\Type;
use Statamic\Facades\GraphQL;
use Statamic\Facades\User;
use Statamic\GraphQL\Queries\Query;
use Statamic\GraphQL\Types\UserType;

/**
 * GraphQL: public author profiles (= users with an author slug), for author pages and post bylines.
 *
 *   { authors { id name author_slug job_title bio avatar { url alt } socials { platform url } is_guest } }
 *   { authors(slug: "jane-doe") { id name author_slug job_title bio } }
 *
 * The built-in `users` query stays disabled (it would list every staff account).
 * Emails and CP links are masked for every User in GraphQL by AppServiceProvider::maskPrivateUserFields().
 * Registered in config/statamic/graphql.php under 'queries'.
 */
final class AuthorsQuery extends Query
{
    protected $attributes = [
        'name' => 'authors',
        'description' => 'Public author profiles (users with an author slug).',
    ];

    public function type(): Type
    {
        return GraphQL::listOf(GraphQL::type(UserType::NAME));
    }

    public function args(): array
    {
        return [
            'slug' => GraphQL::string(),
        ];
    }

    public function resolve($root, $args)
    {
        $query = User::query()->whereNotNull('author_slug');

        if (filled($args['slug'] ?? null)) {
            $query->where('author_slug', $args['slug']);
        }

        return $query->get()
            ->filter(fn ($user) => filled($user->get('author_slug')))
            ->sortBy(fn ($user) => mb_strtolower((string) $user->name()))
            ->values();
    }
}
