<?php

namespace App\Services\GitHub;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class GitHubAccount
{
    private const OAUTH = 'https://github.com/login/oauth';

    public function __construct(private GitHubAppClient $app) {}

    public static function hasOAuthCredentials(): bool
    {
        return filled(config('github.client_id')) && filled(config('github.client_secret'));
    }

    public static function callbackUrl(): ?string
    {
        $base = trim((string) config('app.url'));
        $parts = parse_url($base);
        if (! filter_var($base, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($base, '/').route('settings.github.callback', absolute: false);
    }

    public function authorizeUrl(string $state, string $verifier, string $callback): string
    {
        return self::OAUTH.'/authorize?'.http_build_query([
            'client_id' => config('github.client_id'),
            'redirect_uri' => $callback,
            'state' => $state,
            'scope' => 'offline_access',
            'prompt' => 'select_account',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(User $user, string $code, string $verifier, string $callback): void
    {
        $credentials = $this->exchange(['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $callback]);
        $identity = $this->api('/user', $credentials['token']);
        $id = $identity->json('id');
        $login = $identity->json('login');
        if (! $identity->ok() || ! is_int($id) || $id < 1 || ! is_string($login) || ! preg_match('/\A[A-Za-z0-9-]{1,100}\z/', $login)) {
            throw new RuntimeException('GitHub could not confirm the linked account.');
        }

        $user->forceFill(['github_id' => $id, 'github_login' => $login, 'github_credentials' => $credentials])->save();
    }

    /** @return array{read: bool, write: bool} */
    public function repositoryPermissions(User $user, string $url): array
    {
        $denied = ['read' => false, 'write' => false];
        if (! $user->exists || $user->github_id === null || ! self::hasOAuthCredentials()) {
            return $denied;
        }

        try {
            $repository = RepositoryUrl::parse($url);
            $token = $this->currentToken($user);
            if ($token === null) {
                return $denied;
            }
            $installation = $this->app->installationIdForRepository($url);
            for ($page = 1; $page <= 10; $page++) {
                $response = $this->api('/user/installations/'.$installation.'/repositories', $token, ['per_page' => 100, 'page' => $page]);
                $repositories = $response->json('repositories');
                if (! $response->ok() || ! is_array($repositories)) {
                    return $denied;
                }
                foreach ($repositories as $entry) {
                    $name = $entry['full_name'] ?? null;
                    if (is_string($name) && strcasecmp($name, $repository['full_name']) === 0) {
                        $read = ($entry['permissions']['pull'] ?? false) === true;
                        $write = $read && ($entry['permissions']['push'] ?? false) === true
                            && ! ($entry['archived'] ?? false) && ! ($entry['disabled'] ?? false);

                        return ['read' => $read, 'write' => $write];
                    }
                }
                if (count($repositories) < 100 || (is_int($response->json('total_count')) && $response->json('total_count') <= $page * 100)) {
                    break;
                }
            }
        } catch (ConnectionException|InvalidArgumentException|LockTimeoutException|RuntimeException) {
            return $denied;
        }

        return $denied;
    }

    private function currentToken(User $user): ?string
    {
        $credentials = $user->github_credentials;
        if (empty($credentials['token'])) {
            return null;
        }
        if ($this->freshToken($credentials)) {
            return $credentials['token'];
        }

        return Cache::lock('github-account-refresh:'.$user->getKey(), 20)->block(3, function () use ($user): ?string {
            $linked = $user->fresh();
            $credentials = $linked?->github_credentials;
            if ($linked?->github_id !== $user->github_id || empty($credentials['token'])) {
                return null;
            }
            if ($this->freshToken($credentials)) {
                return $credentials['token'];
            }
            if (empty($credentials['refresh'])) {
                return null;
            }

            $replacement = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh']]);
            $lockedValue = $linked->getRawOriginal('github_credentials');
            $linked->forceFill(['github_credentials' => $replacement]);
            $updated = User::query()->whereKey($user->getKey())->where('github_credentials', $lockedValue)
                ->update(['github_credentials' => $linked->getAttributes()['github_credentials']]);

            return $updated === 1 ? $replacement['token'] : null;
        });
    }

    private function freshToken(array $credentials): bool
    {
        return $credentials['expires_at'] === null || $credentials['expires_at'] > now()->addMinute()->getTimestamp();
    }

    /** @return array{token: string, refresh: ?string, expires_at: ?int} */
    private function exchange(array $parameters): array
    {
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(8)
            ->post(self::OAUTH.'/access_token', [...$parameters,
                'client_id' => config('github.client_id'), 'client_secret' => config('github.client_secret'),
            ]);
        $token = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        if (! $response->ok() || ! is_string($token) || $token === '' || ! empty($response->json('error'))) {
            throw new RuntimeException('GitHub did not authorize this account.');
        }

        return ['token' => $token, 'refresh' => is_string($refresh) ? $refresh : null,
            'expires_at' => is_int($expiresIn) && $expiresIn > 0 ? now()->getTimestamp() + $expiresIn : null];
    }

    private function api(string $path, string $token, array $query = []): Response
    {
        return Http::withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => config('github.api_version')])
            ->withToken($token)->withoutRedirecting()->connectTimeout(4)->timeout(8)->get('https://api.github.com'.$path, $query);
    }
}
