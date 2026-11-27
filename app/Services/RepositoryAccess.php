<?php

namespace App\Services;

use App\Models\User;
use App\Services\GitHub\GitHubAccount;
use App\Services\GitHub\RepositoryUrl;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class RepositoryAccess
{
    public function __construct(private GitHubAccount $github) {}

    public function assertAllowed(User $user, string $url): void
    {
        if (! $this->canView($user, $url)) {
            throw new AuthorizationException(__('repository-access.denied'));
        }
    }

    public function canView(User $user, string $url): bool
    {
        return $this->hasGrant($user, $url, 'repository-access.grants')
            || $this->github->repositoryPermissions($user, $url)['read'];
    }

    public function canWrite(User $user, string $url): bool
    {
        return ($this->hasGrant($user, $url, 'repository-access.grants') && $this->hasGrant($user, $url, 'repository-access.write_grants'))
            || $this->github->repositoryPermissions($user, $url)['write'];
    }

    public function assertCanWrite(User $user, string $url): void
    {
        if (! $this->canWrite($user, $url)) {
            throw new AuthorizationException(__('repository-access.write_denied'));
        }
    }

    private function hasGrant(User $user, string $url, string $configKey): bool
    {
        $grants = config($configKey, []);
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
                        return true;
                    }
                }
            } catch (InvalidArgumentException) {
            }
        }

        return false;
    }
}
