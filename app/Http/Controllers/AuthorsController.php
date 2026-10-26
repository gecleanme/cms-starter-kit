<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Asset;
use Statamic\Facades\User;

/**
 * Public author profiles for the Nuxt build.
 *
 * Why a custom endpoint: Statamic's REST API only exposes id/name/email for users
 * (UserResource + shallow augmentation), never profile fields, and emails must not
 * reach the static site. This returns exactly the public fields, nothing else.
 *
 *   GET /cms-api/authors            -> all users with an author_slug
 *   GET /cms-api/authors?ids=a,b    -> only these user IDs (as referenced by posts.authors)
 *
 * Auth: same Bearer token as the Statamic API (STATAMIC_API_AUTH_TOKEN).
 */
final class AuthorsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('statamic.api.auth_token');
        abort_if($token !== '' && ! hash_equals($token, (string) $request->bearerToken()), 401);

        $ids = array_filter(explode(',', (string) $request->query('ids', '')));

        $users = User::query()
            ->whereNotNull('author_slug')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->get()
            ->map(fn ($user): array => [
                'id' => (string) $user->id(),
                'name' => $user->name(),
                'slug' => $user->get('author_slug'),
                'uri' => '/author/'.$user->get('author_slug'),
                'job_title' => $user->get('job_title'),
                'bio' => $user->get('bio'),
                'avatar' => $this->asset($user->get('avatar')),
                'socials' => array_values(array_filter((array) ($user->get('socials') ?? []), fn ($row) => ! empty($row['url']))),
                'is_guest' => (bool) $user->get('is_guest'),
            ])
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $users]);
    }

    /**
     * @return array{url: string, alt: ?string, width: ?int, height: ?int}|null
     */
    private function asset(mixed $value): ?array
    {
        $path = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $asset = Asset::find(str_contains($path, '::') ? $path : 'assets::'.$path);

        return $asset ? [
            'url' => $asset->absoluteUrl(),
            'alt' => $asset->get('alt'),
            'width' => $asset->width(),
            'height' => $asset->height(),
        ] : null;
    }
}
