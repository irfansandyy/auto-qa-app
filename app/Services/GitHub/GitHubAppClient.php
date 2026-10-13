<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GitHubAppClient
{
    private const API = 'https://api.github.com';

    /** @var array<string, array{token: string, installation_id: int, permissions: array, expires_at: int}> */
    private array $tokens = [];

    /** @return array{owner: string, name: string, full_name: string, default_branch: string, head_sha: string, installation_id: int, can_push: bool} */
    public function inspectRepository(string $repositoryUrl): array
    {
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $data = $this->request('GET', $this->repoPath($repository), $access['token'])->json();
        $branch = $data['default_branch'] ?? '';
        if (! is_string($branch) || $branch === '') {
            throw new RuntimeException('This repository has no default branch. Add an initial commit before connecting it.');
        }

        $head = $this->request('GET', $this->repoPath($repository).'/git/ref/heads/'.rawurlencode($branch), $access['token'])->json('object.sha');
        $this->assertSha($head);

        return [
            ...$repository,
            'default_branch' => $branch,
            'head_sha' => $head,
            'installation_id' => $access['installation_id'],
            'can_push' => ($access['permissions']['contents'] ?? null) === 'write'
                && ($data['permissions']['push'] ?? true) === true
                && ! ($data['archived'] ?? false)
                && ! ($data['disabled'] ?? false),
        ];
    }

    /** @return array<string, string> */
    public function readFiles(string $repositoryUrl, string $ref, array $paths): array
    {
        $this->assertRef($ref);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $result = [];
        $total = 0;
        $trees = [];
        $maxFile = (int) config('github.max_read_file_bytes', 65536);
        $maxTotal = (int) config('github.max_read_total_bytes', 262144);

        foreach (array_slice(array_unique(array_filter($paths, 'is_string')), 0, 20) as $path) {
            if (! is_string($path) || ! $this->safePath($path)) {
                continue;
            }

            try {
                $this->fileMode($this->repoPath($repository), $ref, $path, $access['token'], $trees);
            } catch (InvalidArgumentException) {
                continue;
            }

            $response = $this->request('GET', $this->repoPath($repository).'/contents/'.$this->encodePath($path), $access['token'], ['ref' => $ref], [200, 404]);
            if ($response->status() === 404) {
                continue;
            }

            $file = $response->json();
            if (($file['type'] ?? null) !== 'file' || ($file['encoding'] ?? null) !== 'base64'
                || ! is_numeric($file['size'] ?? null) || $file['size'] > $maxFile
                || ! is_string($file['content'] ?? null)) {
                continue;
            }

            $content = base64_decode(str_replace(["\n", "\r"], '', $file['content']), true);
            if ($content === false || strlen($content) > $maxFile || str_contains($content, "\0")
                || preg_match('//u', $content) !== 1 || $total + strlen($content) > $maxTotal) {
                continue;
            }

            $result[$path] = $content;
            $total += strlen($content);
        }

        return $result;
    }

    /** @return list<array{path: string, size: int}> */
    public function repositoryTree(string $repositoryUrl, string $ref): array
    {
        $this->assertRef($ref);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $data = $this->request('GET', $this->repoPath($repository).'/git/trees/'.rawurlencode($ref), $access['token'], ['recursive' => '1'])->json();
        if ($data['truncated'] ?? false) {
            throw new RuntimeException('The repository tree is too large for this prototype. Connect a smaller Laravel demonstration repository.');
        }

        $files = [];
        foreach ($data['tree'] ?? [] as $entry) {
            $path = $entry['path'] ?? '';
            if (($entry['type'] ?? null) !== 'blob' || ($entry['mode'] ?? null) === '120000'
                || ! is_string($path) || ! $this->safePath($path)
                || ! $this->sourceCandidate($path)
                || ($entry['size'] ?? PHP_INT_MAX) > (int) config('github.max_read_file_bytes', 65536)) {
                continue;
            }
            $files[] = ['path' => $path, 'size' => (int) $entry['size']];
            if (count($files) >= 500) {
                break;
            }
        }

        return $files;
    }

    public function archiveSnapshot(string $repositoryUrl, string $sha, string $destination): void
    {
        $this->assertSha($sha);
        $parent = realpath(dirname($destination));
        $root = realpath(storage_path('app/qa'));
        if ($parent === false || $root === false
            || ($parent !== $root && ! str_starts_with($parent, $root.DIRECTORY_SEPARATOR))
            || is_link($destination) || file_exists($destination)) {
            throw new InvalidArgumentException('Create a new snapshot destination inside storage/app/qa before downloading repository source.');
        }

        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $response = $this->request('GET', $this->repoPath($repository).'/tarball/'.$sha, $access['token'], [], [302]);
        $location = $response->header('Location');
        $parts = parse_url($location ?? '');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'codeload.github.com'
            || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment'])
            || ! str_starts_with(strtolower($parts['path'] ?? ''), '/'.strtolower($repository['full_name']).'/')) {
            throw new RuntimeException('GitHub returned an unsupported snapshot download location.');
        }

        $temporary = tempnam($parent, '.snapshot-');
        if ($temporary === false) {
            throw new RuntimeException('The snapshot directory is not writable.');
        }

        try {
            $limit = (int) config('github.max_archive_bytes', 20971520);
            // A fresh request deliberately omits Authorization on the codeload host.
            $download = Http::withoutRedirecting()->connectTimeout(10)->timeout(120)->withOptions([
                'sink' => $temporary,
                'on_headers' => static function ($headers) use ($limit): void {
                    if ((int) $headers->getHeaderLine('Content-Length') > $limit) {
                        throw new RuntimeException('Snapshot exceeds the download limit.');
                    }
                },
                'progress' => static function ($total, $downloaded) use ($limit): void {
                    if ($downloaded > $limit) {
                        throw new RuntimeException('Snapshot exceeds the download limit.');
                    }
                },
            ])->get($location);
            if (! $download->successful() || filesize($temporary) === 0 || filesize($temporary) > $limit) {
                throw new RuntimeException('Snapshot download failed or exceeds the download limit.');
            }
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('The snapshot could not be saved.');
            }
        } catch (Throwable) {
            // Download URLs can contain signed credentials. Never include their URI or exception text.
            throw new RuntimeException('Repository snapshot download failed. Check access, available storage, and the configured archive size limit.');
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array{branch: string, sha: string} */
    public function commitRepair(string $repositoryUrl, string $baseSha, string $branchName, array $files, string $message): array
    {
        $this->assertSha($baseSha);
        $this->assertRepairBranch($branchName);
        $this->assertRepairFiles($files);
        if (trim($message) === '' || strlen($message) > 2000) {
            throw new InvalidArgumentException('Provide a nonempty commit message shorter than 2000 bytes.');
        }

        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $this->assertPermission($access, 'contents');
        $repo = $this->repoPath($repository);
        $existing = $this->request('GET', $repo.'/git/ref/heads/'.rawurlencode($branchName), $access['token'], [], [200, 404]);
        if ($existing->status() !== 404) {
            throw new RuntimeException('This repair branch already exists. Use a new agent/fix-* branch; existing branches are never overwritten.');
        }

        $base = $this->request('GET', "$repo/git/commits/$baseSha", $access['token'])->json();
        $baseTree = $base['tree']['sha'] ?? null;
        $this->assertSha($baseTree);
        $trees = [];
        $entries = [];
        foreach ($files as $path => $content) {
            $mode = $this->fileMode($repo, $baseTree, $path, $access['token'], $trees);
            $blob = $this->request('POST', "$repo/git/blobs", $access['token'], ['content' => base64_encode($content), 'encoding' => 'base64'])->json('sha');
            $this->assertSha($blob);
            $entries[] = ['path' => $path, 'mode' => $mode, 'type' => 'blob', 'sha' => $blob];
        }

        $tree = $this->request('POST', "$repo/git/trees", $access['token'], ['base_tree' => $baseTree, 'tree' => $entries])->json('sha');
        $this->assertSha($tree);
        $author = $this->botAuthor($access['token']);
        $commit = $this->request('POST', "$repo/git/commits", $access['token'], [
            'message' => $message, 'tree' => $tree, 'parents' => [$baseSha], 'author' => $author, 'committer' => $author,
        ])->json('sha');
        $this->assertSha($commit);
        $this->request('POST', "$repo/git/refs", $access['token'], ['ref' => 'refs/heads/'.$branchName, 'sha' => $commit]);

        return ['branch' => $branchName, 'sha' => $commit];
    }

    /** @return array{number: int, html_url: string} */
    public function createDraftPullRequest(string $repositoryUrl, string $branch, string $baseBranch, string $title, string $body): array
    {
        $this->assertRepairBranch($branch);
        $this->assertRef($baseBranch);
        if ($branch === $baseBranch || trim($title) === '' || strlen($title) > 256 || strlen($body) > 65536) {
            throw new InvalidArgumentException('Provide a distinct base branch, a short PR title, and a bounded description.');
        }

        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $this->assertPermission($access, 'pull_requests');
        $repo = $this->repoPath($repository);
        $existing = $this->request('GET', "$repo/pulls", $access['token'], [
            'state' => 'open', 'head' => $repository['owner'].':'.$branch, 'base' => $baseBranch, 'per_page' => 100,
        ])->json();
        foreach ($existing as $pull) {
            if (($pull['head']['ref'] ?? null) === $branch && ($pull['base']['ref'] ?? null) === $baseBranch) {
                return $this->pullResult($pull);
            }
        }

        $pull = $this->request('POST', "$repo/pulls", $access['token'], [
            'title' => $title, 'body' => $body, 'head' => $branch, 'base' => $baseBranch, 'draft' => true,
        ], [201, 422]);
        if ($pull->status() === 422) {
            // A concurrent retry may have created it after our first lookup.
            $again = $this->request('GET', "$repo/pulls", $access['token'], [
                'state' => 'open', 'head' => $repository['owner'].':'.$branch, 'base' => $baseBranch, 'per_page' => 100,
            ])->json();
            foreach ($again as $item) {
                if (($item['head']['ref'] ?? null) === $branch && ($item['base']['ref'] ?? null) === $baseBranch) {
                    return $this->pullResult($item);
                }
            }
            throw new RuntimeException('GitHub could not create the draft PR. Check the branch, base branch, and repository rules.');
        }

        return $this->pullResult($pull->json());
    }

    private function installationAccess(array $repository): array
    {
        $key = strtolower($repository['full_name']);
        if (isset($this->tokens[$key]) && $this->tokens[$key]['expires_at'] > time() + 60) {
            return $this->tokens[$key];
        }

        $jwt = $this->jwt();
        $installation = $this->request('GET', $this->repoPath($repository).'/installation', $jwt)->json();
        $id = $installation['id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0 || ! empty($installation['suspended_at'])) {
            throw new RuntimeException('Install and enable the configured GitHub App on the selected repository.');
        }
        $configured = config('github.installation_id');
        if ($configured && (int) $configured !== (int) $id) {
            throw new RuntimeException('The repository belongs to a different GitHub App installation. Check GITHUB_APP_INSTALLATION_ID.');
        }

        $permissions = array_intersect_key($installation['permissions'] ?? [], array_flip(['contents', 'pull_requests', 'metadata']));
        if (! isset($permissions['contents'])) {
            throw new RuntimeException('Grant the GitHub App repository Contents access and approve its updated installation permissions.');
        }
        $data = $this->request('POST', '/app/installations/'.$id.'/access_tokens', $jwt, [
            'repositories' => [$repository['name']], 'permissions' => $permissions,
        ])->json();
        if (! is_string($data['token'] ?? null) || $data['token'] === '') {
            throw new RuntimeException('GitHub did not return an installation token. Check the App installation.');
        }
        $expires = strtotime($data['expires_at'] ?? '') ?: 0;
        if ($expires <= time()) {
            throw new RuntimeException('GitHub returned an expired installation token. Check the server clock.');
        }

        return $this->tokens[$key] = [
            'token' => $data['token'], 'installation_id' => (int) $id,
            'permissions' => $data['permissions'] ?? [], 'expires_at' => $expires,
        ];
    }

    private function jwt(): string
    {
        $id = (string) config('github.app_id', '');
        $path = (string) config('github.private_key_path', '');
        if (! preg_match('/^[1-9][0-9]*$/D', $id) || $path === '') {
            throw new RuntimeException('Configure GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY_PATH, then install the GitHub App on your repository. See docs/GITHUB_SETUP.md.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY_PATH must point to a readable server-side GitHub App PEM key.');
        }
        $pem = file_get_contents($path);
        $key = $pem === false ? false : @openssl_pkey_get_private($pem);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The GitHub App private key must be a valid RSA PEM key.');
        }

        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$encode(json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $id], JSON_THROW_ON_ERROR));
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App JWT could not be signed. Check the server OpenSSL configuration.');
        }

        return $unsigned.'.'.$encode($signature);
    }

    private function request(string $method, string $path, string $token, array $data = [], array $statuses = [200, 201]): Response
    {
        try {
            $response = Http::acceptJson()->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => (string) config('github.api_version', '2026-03-10'),
                'User-Agent' => 'Auto-QA-GitHub-App',
            ])->withToken($token)->withoutRedirecting()->connectTimeout(10)->timeout(30)
                ->send($method, self::API.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new RuntimeException('GitHub could not be reached. Check server network access and retry the operation.');
        }

        if (! in_array($response->status(), $statuses, true)) {
            $hint = match ($response->status()) {
                401 => 'Check the GitHub App ID, private key, and server clock.',
                403 => 'Check App permissions, installation approval, repository rules, and GitHub rate limits.',
                404 => 'Check the repository URL, App installation, and requested commit or file.',
                301, 302, 307, 308 => 'Update the repository URL; authenticated API redirects are not followed.',
                default => 'Check repository access and retry after inspecting the run status.',
            };
            throw new RuntimeException('GitHub API request failed (HTTP '.$response->status().'). '.$hint);
        }

        return $response;
    }

    private function botAuthor(string $token): array
    {
        $app = $this->request('GET', '/app', $this->jwt())->json();
        $slug = $app['slug'] ?? '';
        if (! is_string($slug) || ! preg_match('/^[a-z0-9-]+$/D', $slug)) {
            throw new RuntimeException('GitHub did not return a valid App identity for commit attribution.');
        }
        $login = $slug.'[bot]';
        $user = $this->request('GET', '/users/'.rawurlencode($login), $token)->json();
        if (($user['login'] ?? null) !== $login || ! is_numeric($user['id'] ?? null) || (int) $user['id'] <= 0) {
            throw new RuntimeException('The GitHub App bot identity could not be verified. Install the App before creating a repair commit.');
        }

        return ['name' => $login, 'email' => $user['id'].'+'.$login.'@users.noreply.github.com'];
    }

    private function fileMode(string $repo, string $rootTree, string $path, string $token, array &$trees): string
    {
        $sha = $rootTree;
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if (! isset($trees[$sha])) {
                $trees[$sha] = $this->request('GET', "$repo/git/trees/$sha", $token)->json('tree') ?? [];
            }
            $entry = null;
            foreach ($trees[$sha] as $candidate) {
                if (($candidate['path'] ?? '') === $segment) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                return '100644';
            }
            if ($index === count($segments) - 1) {
                if (($entry['type'] ?? '') !== 'blob' || ! in_array($entry['mode'] ?? '', ['100644', '100755'], true)) {
                    throw new InvalidArgumentException('Repair paths cannot replace directories, submodules, or symbolic links.');
                }

                return $entry['mode'];
            }
            if (($entry['type'] ?? '') !== 'tree') {
                throw new InvalidArgumentException('Repair paths cannot traverse files, submodules, or symbolic links.');
            }
            $sha = $entry['sha'];
            $this->assertSha($sha);
        }

        return '100644';
    }

    private function assertRepairFiles(array $files): void
    {
        if ($files === [] || count($files) > 5) {
            throw new InvalidArgumentException('A repair must contain between one and five files.');
        }
        $bytes = 0;
        foreach ($files as $path => $content) {
            if (! is_string($path) || ! $this->safePath($path)
                || ! preg_match('~^(app|resources|routes|tests)/~', $path)) {
                throw new InvalidArgumentException('Repair files must use safe paths inside app, resources, routes, or tests; workflows and secrets are excluded.');
            }
            if (! is_string($content) || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                throw new InvalidArgumentException('Repair files must contain UTF-8 text.');
            }
            $bytes += strlen($content);
        }
        if ($bytes > 65536) {
            throw new InvalidArgumentException('Combined repair file content must not exceed 64 KB.');
        }
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || ! preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['.', '..', '.git', '.aws', '.ssh', 'secrets', 'credentials', 'private_keys', 'private-keys'], true)
                || preg_match('/^(?:\.env(?:\..*)?|auth\.json|credentials(?:\..*)?|secrets?(?:\..*)?)$/iD', $segment)
                || preg_match('/\.(?:pem|key|p12|pfx)$/iD', $segment)) {
                return false;
            }
        }

        return ! str_starts_with(strtolower($path), '.github/workflows/');
    }

    private function sourceCandidate(string $path): bool
    {
        return preg_match('~^(app|resources|routes|tests|config)/~', $path) === 1
            || in_array($path, ['composer.json', 'composer.lock', 'package.json', 'phpunit.xml', 'phpunit.xml.dist'], true);
    }

    private function assertRepairBranch(string $branch): void
    {
        if (! preg_match('~^agent/fix-[a-z0-9][a-z0-9._-]{0,95}$~D', $branch)
            || str_contains($branch, '..') || str_ends_with($branch, '.') || str_ends_with($branch, '.lock')) {
            throw new InvalidArgumentException('Repairs can only create a new agent/fix-* branch.');
        }
    }

    private function assertRef(mixed $ref): void
    {
        if (! is_string($ref) || strlen($ref) > 255 || ! preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]*$~D', $ref)
            || str_contains($ref, '..') || str_ends_with($ref, '/') || str_ends_with($ref, '.lock')) {
            throw new InvalidArgumentException('The repository reference is invalid.');
        }
    }

    private function assertSha(mixed $sha): void
    {
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/iD', $sha)) {
            throw new InvalidArgumentException('An exact 40-character Git commit or object SHA is required.');
        }
    }

    private function assertPermission(array $access, string $permission): void
    {
        if (($access['permissions'][$permission] ?? '') !== 'write') {
            throw new RuntimeException('Grant the GitHub App '.($permission === 'contents' ? 'Contents' : 'Pull requests').' read/write permission, then approve the installation update.');
        }
    }

    private function repoPath(array $repository): string
    {
        return '/repos/'.$repository['owner'].'/'.$repository['name'];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function pullResult(array $pull): array
    {
        $number = $pull['number'] ?? null;
        $url = $pull['html_url'] ?? '';
        if (! is_numeric($number) || (int) $number < 1 || ! is_string($url)
            || ! preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/pull/[1-9][0-9]*$~D', $url)) {
            throw new RuntimeException('GitHub did not return a valid pull request link.');
        }

        return ['number' => (int) $number, 'html_url' => $url];
    }
}
