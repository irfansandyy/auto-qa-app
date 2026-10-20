<?php

namespace App\Services;

use App\Models\User;
use App\Services\GitHub\RepositoryUrl;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class RepositoryAccess
{
    public function assertAllowed(User $user, string $url): void
    {
        $grants = config('repository-access.grants', []);
        $repositories = $user->exists && is_array($grants) && ! array_is_list($grants) ? ($grants[$user->getKey()] ?? []) : [];
        if (is_array($repositories) && array_is_list($repositories)) {
            try {
                $requested = strtolower(RepositoryUrl::parse($url)['full_name']);
                foreach ($repositories as $repository) {
                    if (! is_string($repository)) {
                        continue;
                    }
                    try {
                        $allowed = strtolower(RepositoryUrl::parse('https://github.com/'.$repository)['full_name']);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    if ($requested === $allowed) {
                        return;
                    }
                }
            } catch (InvalidArgumentException) {
            }
        }

        throw new AuthorizationException(__('repository-access.denied'));
    }
}
