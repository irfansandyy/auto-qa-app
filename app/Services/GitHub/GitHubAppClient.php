<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

class GitHubAppClient
{
    private const API = 'https://api.github.com';

    private const WORKSPACE_FILE_BYTES = 131072;

    private const WORKSPACE_TREE_ENTRIES = 1000;

    private const WORKSPACE_SEARCH_ENTRIES = 10000;

    private const WORKSPACE_SEARCH_BYTES = 2097152;

    private const WORKSPACE_SEARCH_RESULTS = 50;

    private const PULL_FILES_LIMIT = 100;

    private const PULL_PATCH_BYTES = 131072;

    private const INLINE_COMMENT_LIMIT = 40;

    /** @var array<string, array{token: string, installation_id: int, permissions: array, expires_at: int}> */
    private array $tokens = [];

    public static function installationUrl(): ?string
    {
        $slug = config('github.app_slug');

        return is_string($slug) && trim($slug) !== ''
            ? 'https://github.com/apps/'.rawurlencode(trim($slug)).'/installations/new'
            : null;
    }

    public static function hasSigningKey(): bool
    {
        try {
            self::signingConfiguration();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

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

        return [
            ...$repository,
            'default_branch' => $branch,
            'head_sha' => $this->branchHeadSha($repository, $branch, $access['token']),
            'installation_id' => $access['installation_id'],
            'can_push' => ($access['permissions']['contents'] ?? null) === 'write'
                && ! ($data['archived'] ?? false)
                && ! ($data['disabled'] ?? false),
        ];
    }

    public function branchHead(string $repositoryUrl, string $branch): string
    {
        $this->assertRef($branch);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);

        return $this->branchHeadSha($repository, $branch, $access['token']);
    }

    private function branchHeadSha(array $repository, string $branch, string $token): string
    {
        $head = $this->request('GET', $this->repoPath($repository).'/git/ref/heads/'.rawurlencode($branch), $token)->json('object.sha');
        $this->assertSha($head);

        return strtolower($head);
    }

    /** @return array{items: list<array{name: string, sha: string, protected: bool}>, has_more: bool} */
    public function listBranches(string $repositoryUrl, int $page = 1): array
    {
        $query = $this->workspacePage($page);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $response = $this->request('GET', "$repo/branches", $access['token'], $query);
        $branches = [];
        foreach ($response->json() as $branch) {
            if (! is_string($branch['name'] ?? null) || ! $this->validWorkspaceRef($branch['name'])
                || ! $this->validSha($branch['commit']['sha'] ?? null)) {
                continue;
            }

            $branches[] = ['name' => $branch['name'], 'sha' => $branch['commit']['sha'], 'protected' => (bool) ($branch['protected'] ?? false)];
        }

        return ['items' => $branches, 'has_more' => $this->hasNextPage($response)];
    }

    /** @return array{entries: list<array{path: string, name: string, type: 'file'|'dir', size: ?int}>, truncated: bool} */
    public function workspaceTree(string $repositoryUrl, string $ref, string $directory = ''): array
    {
        $this->assertWorkspaceRef($ref);
        if ($directory !== '') {
            $this->assertWorkspacePath($directory);
        }

        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $tree = $this->workspaceDirectory($repo, $ref, $directory, $access['token']);
        $entries = [];
        foreach (array_slice($tree['tree'], 0, self::WORKSPACE_TREE_ENTRIES) as $entry) {
            $name = $entry['path'] ?? null;
            if (! is_string($name) || str_contains($name, '/')) {
                continue;
            }
            $path = $directory === '' ? $name : $directory.'/'.$name;
            $type = match ($entry['mode'] ?? null) {
                '040000' => 'dir',
                '100644', '100755' => 'file',
                default => null,
            };
            if ($type === null || ! $this->safeWorkspacePath($path)) {
                continue;
            }

            $entries[] = ['path' => $path, 'name' => $name, 'type' => $type, 'size' => is_int($entry['size'] ?? null) ? $entry['size'] : null];
        }
        usort($entries, fn (array $first, array $second): int => strcmp($first['type'], $second['type']) ?: strnatcasecmp($first['name'], $second['name']));

        return ['entries' => $entries, 'truncated' => $tree['truncated'] || count($tree['tree']) > self::WORKSPACE_TREE_ENTRIES];
    }

    /** @return array{items: list<array{path: string, name: string}>, truncated: bool} */
    public function searchWorkspaceFiles(string $repositoryUrl, string $ref, string $query): array
    {
        $this->assertWorkspaceRef($ref);
        $query = trim($query);
        if (strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query) || ! $this->validWorkspaceText($query)) {
            throw new InvalidArgumentException('Enter a file or path search up to 200 bytes.');
        }
        if (mb_strlen($query, 'UTF-8') < 2) {
            return ['items' => [], 'truncated' => false];
        }

        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $response = $this->request('GET', "$repo/git/trees/".rawurlencode($ref), $access['token'], ['recursive' => '1'],
            maximumBytes: self::WORKSPACE_SEARCH_BYTES);
        $entries = $response->json('tree');
        if (! is_array($entries)) {
            throw new RuntimeException('GitHub did not return the repository tree for this search.');
        }

        $matches = [];
        foreach (array_slice($entries, 0, self::WORKSPACE_SEARCH_ENTRIES) as $entry) {
            $path = $entry['path'] ?? null;
            if (in_array($entry['mode'] ?? null, ['100644', '100755'], true) && is_string($path) && $this->safeWorkspacePath($path)
                && mb_stripos($path, $query, 0, 'UTF-8') !== false) {
                $matches[] = ['path' => $path, 'name' => basename($path)];
            }
        }
        usort($matches, function (array $first, array $second) use ($query): int {
            $firstNameMatch = mb_stripos($first['name'], $query, 0, 'UTF-8') !== false;
            $secondNameMatch = mb_stripos($second['name'], $query, 0, 'UTF-8') !== false;

            return $secondNameMatch <=> $firstNameMatch ?: strnatcasecmp($first['path'], $second['path']);
        });

        return [
            'items' => array_slice($matches, 0, self::WORKSPACE_SEARCH_RESULTS),
            'truncated' => (bool) $response->json('truncated') || count($entries) > self::WORKSPACE_SEARCH_ENTRIES || count($matches) > self::WORKSPACE_SEARCH_RESULTS,
        ];
    }

    /** @return array{path: string, content: string, sha: string, editable: bool} */
    public function readWorkspaceFile(string $repositoryUrl, string $ref, string $path): array
    {
        $this->assertWorkspaceRef($ref);
        $this->assertWorkspacePath($path);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $entry = $this->workspaceFileEntry($repo, $ref, $path, $access['token']);

        return [
            'path' => $path,
            'content' => $this->workspaceBlob($repo, $entry['sha'], $access['token']),
            'sha' => $entry['sha'],
            'editable' => ($access['permissions']['contents'] ?? null) === 'write',
        ];
    }

    /** @return array{branch: string, sha: string, blob_sha: string} */
    public function commitWorkspaceFile(string $repositoryUrl, string $branch, string $path, string $expectedBlobSha, string $content, string $message): array
    {
        $this->assertWorkspaceRef($branch);
        $this->assertWorkspacePath($path);
        $this->assertSha($expectedBlobSha);
        if (! $this->validWorkspaceText($content) || trim($message) === '' || strlen($message) > 2000) {
            throw new InvalidArgumentException('Use UTF-8 file content up to 128 KB and a nonempty commit message up to 2000 bytes.');
        }

        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertPermission($access, 'contents');
        $metadata = $this->writableWorkspaceRepository($repo, $access['token']);
        $branchDetails = $this->workspaceBranch($repo, $branch, $access['token']);
        if ($branchDetails['protected'] || ! is_string($metadata['default_branch'] ?? null) || $branch === $metadata['default_branch']) {
            throw new RuntimeException('Create or select a non-protected branch before committing file changes.');
        }

        $entry = $this->workspaceFileEntry($repo, $branchDetails['sha'], $path, $access['token']);
        if (strcasecmp($entry['sha'], $expectedBlobSha) !== 0) {
            throw new RuntimeException('The file changed on GitHub. Reload and review the latest version before committing.');
        }
        $original = $this->workspaceBlob($repo, $entry['sha'], $access['token']);
        if ($original === $content) {
            throw new InvalidArgumentException('There are no changes to commit.');
        }

        $response = $this->request('PUT', "$repo/contents/".$this->encodePath($path), $access['token'], [
            'branch' => $branch, 'message' => trim($message), 'sha' => $entry['sha'], 'content' => base64_encode($content),
        ], [200, 409, 422]);
        if ($response->status() === 409) {
            throw new RuntimeException('The file changed on GitHub. Reload and review the latest version before committing.');
        }
        if ($response->status() !== 200) {
            throw new RuntimeException('GitHub could not commit this change. Check the branch rules and reload the file before retrying.');
        }

        $sha = $response->json('commit.sha');
        $blob = $response->json('content.sha');
        $this->assertSha($sha);
        $this->assertSha($blob);

        return ['branch' => $branch, 'sha' => $sha, 'blob_sha' => $blob];
    }

    /** @return array{name: string, sha: string, protected: bool} */
    public function createWorkspaceBranch(string $repositoryUrl, string $sourceBranch, string $newBranch): array
    {
        $this->assertWorkspaceRef($sourceBranch);
        $this->assertWorkspaceRef($newBranch);
        if ($sourceBranch === $newBranch || str_starts_with($newBranch, 'refs/') || $this->validSha($newBranch)) {
            throw new InvalidArgumentException('Provide a new branch name distinct from the source branch.');
        }

        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertPermission($access, 'contents');
        $this->writableWorkspaceRepository($repo, $access['token']);
        $source = $this->workspaceBranch($repo, $sourceBranch, $access['token']);
        $response = $this->request('POST', "$repo/git/refs", $access['token'], [
            'ref' => 'refs/heads/'.$newBranch, 'sha' => $source['sha'],
        ], [201, 409, 422]);
        if ($response->status() !== 201) {
            throw new RuntimeException('GitHub could not create the branch. Choose a new name and check the repository rules.');
        }

        return ['name' => $newBranch, 'sha' => $source['sha'], 'protected' => false];
    }

    /** @return array{items: list<array{sha: string, message: string, author: string, authored_at: string}>, has_more: bool} */
    public function listWorkspaceCommits(string $repositoryUrl, string $ref, int $page = 1): array
    {
        $this->assertWorkspaceRef($ref);
        $query = ['sha' => $ref, ...$this->workspacePage($page)];
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $response = $this->request('GET', "$repo/commits", $access['token'], $query);
        $commits = [];
        foreach ($response->json() as $item) {
            if (! $this->validSha($item['sha'] ?? null)) {
                continue;
            }

            $commits[] = [
                'sha' => $item['sha'],
                'message' => $this->workspaceString($item['commit']['message'] ?? null, 2000),
                'author' => $this->workspaceString($item['author']['login'] ?? $item['commit']['author']['name'] ?? null, 128),
                'authored_at' => $this->workspaceString($item['commit']['author']['date'] ?? null, 64),
            ];
        }

        return ['items' => $commits, 'has_more' => $this->hasNextPage($response)];
    }

    /** @return array{default_branch: string, can_write_contents: bool, can_write_pull_requests: bool} */
    public function workspacePermissions(string $repositoryUrl): array
    {
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $data = $this->request('GET', $repo, $access['token'])->json();
        $writable = ! ($data['archived'] ?? false) && ! ($data['disabled'] ?? false);

        return [
            'default_branch' => $this->workspaceString($data['default_branch'] ?? null, 255),
            'can_write_contents' => $writable && ($access['permissions']['contents'] ?? null) === 'write',
            'can_write_pull_requests' => $writable && ($access['permissions']['pull_requests'] ?? null) === 'write',
        ];
    }

    /** @return array{items: list<array>, has_more: bool} */
    public function listPullRequests(string $repositoryUrl, string $state = 'open', int $page = 1): array
    {
        if (! in_array($state, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('The pull request filter is invalid.');
        }

        $query = ['state' => $state, 'sort' => 'updated', 'direction' => 'desc', ...$this->workspacePage($page)];
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertReadPermission($access, 'pull_requests');
        $response = $this->request('GET', "$repo/pulls", $access['token'], $query);
        $pulls = array_map(fn (array $pull): array => $this->workspacePull($pull, $access), $response->json());

        return ['items' => $pulls, 'has_more' => $this->hasNextPage($response)];
    }

    /** @return array{number: int, title: string, body: string, state: string, draft: bool, merged: bool, mergeable: ?bool, merge_state: ?string, head_ref: string, head_sha: string, base_ref: string, base_sha: ?string, author: string, updated_at: string, changed_files: int, html_url: string, node_id: string, can_merge: bool} */
    public function getPullRequest(string $repositoryUrl, int $number): array
    {
        $this->assertPullNumber($number);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);

        return $this->workspacePullAt($repo, $number, $access);
    }

    /** @return array{files: list<array{path: string, previous_path: ?string, status: string, additions: int, deletions: int, patch: ?string}>, truncated: bool} */
    public function listPullRequestFiles(string $repositoryUrl, int $number): array
    {
        $this->assertPullNumber($number);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertReadPermission($access, 'pull_requests');

        return $this->workspacePullFiles($repo, $number, $access['token']);
    }

    /** @return array{merge_state: ?string, review_decision: ?string, checks: list<array{name: string, state: string, required: bool}>, reviews: list<array{author: string, state: string, submitted_at: string}>, blockers: list<string>, unavailable: list<string>, truncated: bool} */
    public function pullRequestQuality(string $repositoryUrl, int $number, string $reviewedHeadSha, string $baseRef): array
    {
        $this->assertPullNumber($number);
        $this->assertSha($reviewedHeadSha);
        $this->assertWorkspaceRef($baseRef);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $this->assertReadPermission($access, 'pull_requests');

        $hasChecks = $this->hasReadPermission($access, 'checks');
        $hasStatuses = $this->hasReadPermission($access, 'statuses');
        $pull = $this->qualitySnapshot($repository, $number, $access['token'], $hasChecks, $hasStatuses);
        if (isset($pull['headRefOid'], $pull['baseRefName'])
            && (strcasecmp($pull['headRefOid'], $reviewedHeadSha) !== 0 || $pull['baseRefName'] !== $baseRef)) {
            throw new RuntimeException('The pull request changed on GitHub. Reload the diff before checking its status.');
        }

        $mergeState = $this->qualityEnum($pull['mergeStateStatus'] ?? null, ['BEHIND', 'BLOCKED', 'CLEAN', 'DIRTY', 'DRAFT', 'HAS_HOOKS', 'UNKNOWN', 'UNSTABLE']);
        $reviewDecision = $this->qualityEnum($pull['reviewDecision'] ?? null, ['APPROVED', 'CHANGES_REQUESTED', 'REVIEW_REQUIRED']);
        $commit = $pull['commits']['nodes'][0]['commit'] ?? null;
        $contexts = $commit['statusCheckRollup']['contexts'] ?? null;
        $latestReviews = $pull['latestReviews'] ?? null;
        $classic = $pull['baseRef']['refUpdateRule']['requiredStatusCheckContexts'] ?? null;
        $classicChecks = is_array($classic) ? $classic : [];
        $rules = $this->workspaceBranchRules($this->repoPath($repository), $baseRef, $access['token']);
        $truncated = (bool) ($contexts['pageInfo']['hasNextPage'] ?? false) || (bool) ($latestReviews['pageInfo']['hasNextPage'] ?? false)
            || ($rules['truncated'] ?? false) || count($classicChecks) > 100;
        $contextListAvailable = is_array($commit) && $this->validSha($commit['oid'] ?? null)
            && strcasecmp($commit['oid'], $reviewedHeadSha) === 0 && array_key_exists('statusCheckRollup', $commit)
            && ($commit['statusCheckRollup'] === null || is_array($contexts['nodes'] ?? null));
        $unavailable = array_keys(array_filter([
            'merge_status' => $mergeState === null,
            'checks' => ! $hasChecks,
            'statuses' => ! $hasStatuses,
            'check_data' => ($hasChecks || $hasStatuses) && ! $contextListAvailable,
            'reviews' => ! is_array($latestReviews['nodes'] ?? null),
            'rules' => $rules === null,
        ]));

        $requiredChecks = $rules['required_checks'] ?? [];
        foreach (array_slice($classicChecks, 0, 100) as $check) {
            $name = $this->workspaceString($check, 255);
            if ($name !== '') {
                $requiredChecks[] = $name;
            }
        }
        $checks = $this->qualityChecks($contextListAvailable ? ($contexts['nodes'] ?? []) : [], $requiredChecks,
            $contextListAvailable && $hasChecks && $hasStatuses && ! ($contexts['pageInfo']['hasNextPage'] ?? false));
        $reviews = $this->qualityReviews($latestReviews['nodes'] ?? []);

        return [
            'merge_state' => $mergeState,
            'review_decision' => $reviewDecision,
            'checks' => $checks,
            'reviews' => $reviews,
            'blockers' => $this->qualityBlockers($mergeState, $reviewDecision, $checks, isset($pull['mergeQueue']['id']) || ($rules['merge_queue'] ?? false)),
            'unavailable' => $unavailable,
            'truncated' => $truncated,
        ];
    }

    /** @return array{comments: list<array{id: int, path: string, line: ?int, original_line: ?int, side: ?string, body: string, author: string, created_at: string, commit_sha: string, outdated: bool}>, truncated: bool} */
    public function listPullRequestComments(string $repositoryUrl, int $number): array
    {
        $this->assertPullNumber($number);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertReadPermission($access, 'pull_requests');
        $response = $this->request('GET', "$repo/pulls/$number/comments", $access['token'], [
            'sort' => 'created', 'direction' => 'desc', 'per_page' => self::INLINE_COMMENT_LIMIT,
        ]);
        $comments = [];
        $entries = $response->json();
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('GitHub did not return the pull request review comments.');
        }
        foreach (array_slice($entries, 0, self::INLINE_COMMENT_LIMIT) as $entry) {
            if (is_array($entry) && is_int($entry['id'] ?? null) && is_string($entry['path'] ?? null) && $this->safeWorkspacePath($entry['path'])) {
                $comments[] = $this->workspaceReviewComment($entry);
            }
        }

        return ['comments' => $comments, 'truncated' => count($entries) > self::INLINE_COMMENT_LIMIT || $this->hasNextPage($response)];
    }

    /** @return array{id: int, path: string, line: ?int, original_line: ?int, side: ?string, body: string, author: string, created_at: string, commit_sha: string, outdated: bool} */
    public function createPullRequestComment(string $repositoryUrl, int $number, string $reviewedHeadSha, string $path, int $line, string $side, string $body): array
    {
        $this->assertPullNumber($number);
        $this->assertSha($reviewedHeadSha);
        $this->assertWorkspacePath($path);
        $body = trim($body);
        if ($line < 1 || ! in_array($side, ['LEFT', 'RIGHT'], true) || $body === '' || strlen($body) > 4000 || ! $this->validWorkspaceText($body)) {
            throw new InvalidArgumentException('Choose a line in the diff and enter a review comment up to 4,000 bytes.');
        }

        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertPermission($access, 'pull_requests');
        $this->writableWorkspaceRepository($repo, $access['token']);
        $pull = $this->workspacePullAt($repo, $number, $access);
        $this->assertReviewedHead($pull, $reviewedHeadSha);
        if ($pull['state'] !== 'open') {
            throw new RuntimeException('Review comments can be submitted from Auto QA while the pull request is open.');
        }

        $files = $this->workspacePullFiles($repo, $number, $access['token'])['files'];
        $patch = array_column($files, 'patch', 'path')[$path] ?? null;
        if ($patch === null || ! $this->hasReviewLine($patch, $line, $side)) {
            throw new InvalidArgumentException('Select a visible line in the current pull request diff.');
        }

        $response = $this->request('POST', "$repo/pulls/$number/comments", $access['token'], [
            'body' => $body, 'commit_id' => $reviewedHeadSha, 'path' => $path, 'line' => $line, 'side' => $side,
        ], [201, 409, 422]);
        if ($response->status() !== 201) {
            throw new RuntimeException('GitHub did not accept the comment. Reload the pull request and check its current diff.');
        }

        return $this->workspaceReviewComment($response->json());
    }

    /** @return array{number: int, html_url: string} */
    public function createWorkspacePullRequest(string $repositoryUrl, string $head, string $base, string $title): array
    {
        $this->assertWorkspaceRef($head);
        $this->assertWorkspaceRef($base);
        $title = trim($title);
        if ($head === $base || str_starts_with($head, 'refs/') || str_starts_with($base, 'refs/')
            || $this->validSha($head) || $this->validSha($base) || $title === '' || strlen($title) > 256) {
            throw new InvalidArgumentException('Choose distinct named branches and provide a pull request title up to 256 bytes.');
        }

        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $this->assertPermission($access, 'pull_requests');
        $this->writableWorkspaceRepository($this->repoPath($repository), $access['token']);

        return $this->createDraftForBranches($repository, $head, $base, $title, '', $access['token']);
    }

    public function markPullRequestReady(string $repositoryUrl, int $number, string $reviewedHeadSha): void
    {
        $this->assertPullNumber($number);
        $this->assertSha($reviewedHeadSha);
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertPermission($access, 'pull_requests');
        $this->writableWorkspaceRepository($repo, $access['token']);
        $pull = $this->workspacePullAt($repo, $number, $access);
        $this->assertReviewedHead($pull, $reviewedHeadSha);
        if ($pull['state'] !== 'open' || $pull['merged']) {
            throw new RuntimeException('Only an open pull request can be marked ready for review.');
        }
        if (! $pull['draft']) {
            return;
        }
        if (! preg_match('/^[A-Za-z0-9_+=:\/-]{1,128}$/D', $pull['node_id'])) {
            throw new RuntimeException('GitHub did not return a valid pull request identifier.');
        }

        $response = $this->request('POST', '/graphql', $access['token'], [
            'query' => 'mutation MarkReady($id: ID!) { markPullRequestReadyForReview(input: {pullRequestId: $id}) { pullRequest { id isDraft headRefOid } } }',
            'variables' => ['id' => $pull['node_id']],
        ])->json();
        $updated = $response['data']['markPullRequestReadyForReview']['pullRequest'] ?? [];
        if (! empty($response['errors']) || ($updated['id'] ?? null) !== $pull['node_id'] || ($updated['isDraft'] ?? null) !== false) {
            throw new RuntimeException('GitHub could not mark this pull request ready. Check Pull requests write access and the repository rules.');
        }

        $this->assertReviewedHead(['head_sha' => $updated['headRefOid'] ?? null], $reviewedHeadSha);
    }

    /** @return array{sha: string, merged: bool} */
    public function mergePullRequest(string $repositoryUrl, int $number, string $reviewedHeadSha, ?string $reviewedBaseRef = null, ?string $reviewedBaseSha = null): array
    {
        $this->assertPullNumber($number);
        $this->assertSha($reviewedHeadSha);
        if ($reviewedBaseRef !== null) {
            $this->assertWorkspaceRef($reviewedBaseRef);
        }
        if ($reviewedBaseSha !== null) {
            $this->assertSha($reviewedBaseSha);
        }
        [$repo, $access] = $this->workspaceAccess($repositoryUrl);
        $this->assertPermission($access, 'contents');
        $metadata = $this->writableWorkspaceRepository($repo, $access['token']);
        $pull = $this->workspacePullAt($repo, $number, $access);
        $this->assertReviewedHead($pull, $reviewedHeadSha);
        if (($reviewedBaseRef !== null && $pull['base_ref'] !== $reviewedBaseRef)
            || ($reviewedBaseSha !== null && strcasecmp($pull['base_sha'] ?? '', $reviewedBaseSha) !== 0)) {
            throw new RuntimeException('The pull request base changed on GitHub. Reload and review the latest diff before merging.');
        }
        if ($pull['state'] !== 'open' || $pull['draft'] || $pull['merged'] || $pull['mergeable'] === false) {
            throw new RuntimeException('The pull request must be open, ready, and free of merge conflicts.');
        }
        if ($pull['mergeable'] !== true) {
            throw new RuntimeException('GitHub is still checking whether this pull request can merge. Reload the pull request and try again.');
        }
        if (in_array($pull['merge_state'], ['blocked', 'behind', 'dirty', 'draft'], true)) {
            throw new RuntimeException('GitHub reports a merge blocker. Check the current pull request reviews and required checks before retrying.');
        }
        $diff = $this->workspacePullFiles($repo, $number, $access['token']);
        if ($diff['truncated'] || $pull['changed_files'] !== count($diff['files'])) {
            throw new RuntimeException('The full diff is unavailable in Auto QA. Review and merge this pull request on GitHub.');
        }

        $mergeMethod = match (true) {
            ($metadata['allow_merge_commit'] ?? true) => 'merge',
            ($metadata['allow_squash_merge'] ?? false) => 'squash',
            ($metadata['allow_rebase_merge'] ?? false) => 'rebase',
            default => throw new RuntimeException('GitHub has no merge method enabled for this repository.'),
        };
        $response = $this->request('PUT', "$repo/pulls/$number/merge", $access['token'], [
            'sha' => $reviewedHeadSha, 'merge_method' => $mergeMethod,
        ], [200, 405, 409, 422]);
        if ($response->status() === 409) {
            throw new RuntimeException('The pull request changed on GitHub. Reload and review the latest diff before merging.');
        }
        if ($response->status() !== 200 || $response->json('merged') !== true) {
            throw new RuntimeException('GitHub could not merge this pull request. Check required reviews, status checks, conflicts, or merge queue requirements.');
        }

        $sha = $response->json('sha');
        $this->assertSha($sha);

        return ['sha' => $sha, 'merged' => true];
    }

    /** @return array<string, string> */
    public function readFiles(string $repositoryUrl, string $ref, array $paths): array
    {
        return $this->readBoundedFiles($repositoryUrl, $ref, $paths,
            (int) config('github.max_read_file_bytes', 65536), (int) config('github.max_read_total_bytes', 262144));
    }

    /** @return array<string, string> */
    public function readReviewLockfiles(string $repositoryUrl, string $sha): array
    {
        $this->assertSha($sha);

        return $this->readBoundedFiles($repositoryUrl, $sha, ['composer.lock', 'package-lock.json', 'npm-shrinkwrap.json'], 1_000_000, 3_000_000);
    }

    /** @return array<string, string> */
    private function readBoundedFiles(string $repositoryUrl, string $ref, array $paths, int $maxFile, int $maxTotal): array
    {
        $this->assertRef($ref);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $result = [];
        $total = 0;
        $trees = [];

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
    public function repositoryTree(string $repositoryUrl, string $ref, bool $includeMigrations = false): array
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
                || (! $this->sourceCandidate($path) && ! ($includeMigrations && str_starts_with($path, 'database/migrations/')))
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

    public function assertPublicationAllowed(string $repositoryUrl, string $baseBranch, string $deployedSha): void
    {
        $this->assertRef($baseBranch);
        $this->assertSha($deployedSha);
        $repository = RepositoryUrl::parse($repositoryUrl);
        $access = $this->installationAccess($repository);
        $this->assertPermission($access, 'contents');
        $this->assertPermission($access, 'pull_requests');
        $data = $this->request('GET', $this->repoPath($repository), $access['token'])->json();
        if (($data['archived'] ?? false) || ($data['disabled'] ?? false)) {
            throw new RuntimeException('The selected repository is archived or disabled. Enable an active demonstration repository before publishing.');
        }
        $response = $this->request('GET', $this->repoPath($repository).'/git/ref/heads/'.rawurlencode($baseBranch), $access['token'], [], [200, 404]);
        if ($response->status() === 404) {
            throw new RuntimeException('The selected pull request base branch does not exist. Correct the project base branch before publishing.');
        }
        $head = $response->json('object.sha');
        $this->assertSha($head);
        if (strcasecmp($head, $deployedSha) !== 0) {
            throw new RuntimeException('The selected base branch no longer matches the verified deployed commit. Deploy the current source and refresh the deployed commit before publishing a repair.');
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
        $this->assertPermission($access, 'pull_requests');
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
        $commit = $this->request('POST', "$repo/git/commits", $access['token'], [
            'message' => $message, 'tree' => $tree, 'parents' => [$baseSha],
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

        return $this->createDraftForBranches($repository, $branch, $baseBranch, $title, $body, $access['token']);
    }

    /** @return array{number: int, html_url: string} */
    private function createDraftForBranches(array $repository, string $branch, string $baseBranch, string $title, string $body, string $token): array
    {
        $repo = $this->repoPath($repository);
        $filters = [
            'state' => 'open', 'head' => $repository['owner'].':'.$branch, 'base' => $baseBranch, 'per_page' => 100,
        ];
        $existing = $this->request('GET', "$repo/pulls", $token, $filters)->json();
        foreach ($existing as $pull) {
            if (($pull['head']['ref'] ?? null) === $branch && ($pull['base']['ref'] ?? null) === $baseBranch) {
                return $this->pullResult($pull);
            }
        }

        $pull = $this->request('POST', "$repo/pulls", $token, [
            'title' => $title, 'body' => $body, 'head' => $branch, 'base' => $baseBranch, 'draft' => true,
        ], [201, 422]);
        if ($pull->status() === 422) {
            // A concurrent retry may have created it after our first lookup.
            $again = $this->request('GET', "$repo/pulls", $token, $filters)->json();
            foreach ($again as $item) {
                if (($item['head']['ref'] ?? null) === $branch && ($item['base']['ref'] ?? null) === $baseBranch) {
                    return $this->pullResult($item);
                }
            }
            throw new RuntimeException('GitHub could not create the draft PR. Check the branch, base branch, and repository rules.');
        }

        return $this->pullResult($pull->json());
    }

    /** @return array{string, array{token: string, installation_id: int, permissions: array, expires_at: int}} */
    private function workspaceAccess(string $repositoryUrl): array
    {
        $repository = RepositoryUrl::parse($repositoryUrl);

        return [$this->repoPath($repository), $this->installationAccess($repository)];
    }

    private function writableWorkspaceRepository(string $repo, string $token): array
    {
        $data = $this->request('GET', $repo, $token)->json();
        if (($data['archived'] ?? false) || ($data['disabled'] ?? false)) {
            throw new RuntimeException('GitHub has archived or disabled this repository. The workspace is read-only.');
        }

        return $data;
    }

    /** @return array{sha: string, protected: bool} */
    private function workspaceBranch(string $repo, string $branch, string $token): array
    {
        $data = $this->request('GET', "$repo/branches/".rawurlencode($branch), $token)->json();
        $sha = $data['commit']['sha'] ?? null;
        $this->assertSha($sha);

        return ['sha' => $sha, 'protected' => ($data['protected'] ?? null) !== false];
    }

    /** @return array{tree: list<array>, truncated: bool} */
    private function workspaceDirectory(string $repo, string $ref, string $directory, string $token): array
    {
        $tree = $this->workspaceTreeAt($repo, $ref, $token);
        if ($directory === '') {
            return $tree;
        }

        foreach (explode('/', $directory) as $name) {
            $entry = $this->workspaceEntryNamed($tree['tree'], $name);
            if (($entry['mode'] ?? null) !== '040000' || ($entry['type'] ?? null) !== 'tree') {
                throw new InvalidArgumentException('The selected folder is unavailable on this reference.');
            }
            $this->assertSha($entry['sha'] ?? null);
            $tree = $this->workspaceTreeAt($repo, $entry['sha'], $token);
        }

        return $tree;
    }

    /** @return array{tree: list<array>, truncated: bool} */
    private function workspaceTreeAt(string $repo, string $ref, string $token): array
    {
        $data = $this->request('GET', "$repo/git/trees/".rawurlencode($ref), $token)->json();

        return ['tree' => $data['tree'] ?? [], 'truncated' => (bool) ($data['truncated'] ?? false)];
    }

    /** @return array{sha: string, size: int} */
    private function workspaceFileEntry(string $repo, string $ref, string $path, string $token): array
    {
        $directory = str_contains($path, '/') ? dirname($path) : '';
        $name = str_contains($path, '/') ? substr($path, strrpos($path, '/') + 1) : $path;
        $tree = $this->workspaceDirectory($repo, $ref, $directory, $token);
        $entry = $this->workspaceEntryNamed($tree['tree'], $name);
        if (($entry['type'] ?? null) !== 'blob' || ! in_array($entry['mode'] ?? null, ['100644', '100755'], true)) {
            throw new InvalidArgumentException('The selected item is missing or is not a regular file.');
        }
        if (! is_int($entry['size'] ?? null) || $entry['size'] > self::WORKSPACE_FILE_BYTES) {
            throw new InvalidArgumentException('The workspace can display text files up to 128 KB.');
        }
        $this->assertSha($entry['sha'] ?? null);

        return ['sha' => $entry['sha'], 'size' => $entry['size']];
    }

    private function workspaceEntryNamed(array $entries, string $name): array
    {
        foreach ($entries as $entry) {
            if (($entry['path'] ?? null) === $name) {
                return $entry;
            }
        }

        return [];
    }

    private function workspaceBlob(string $repo, string $sha, string $token): string
    {
        $data = $this->request('GET', "$repo/git/blobs/$sha", $token)->json();
        $content = ($data['encoding'] ?? null) === 'base64' && is_string($data['content'] ?? null)
            ? base64_decode(str_replace(["\n", "\r"], '', $data['content']), true)
            : false;
        if ($content === false || ! $this->validWorkspaceText($content)) {
            throw new InvalidArgumentException('The selected file is not supported as UTF-8 text.');
        }

        return $content;
    }

    /** @return array{page: int, per_page: int} */
    private function workspacePage(int $page): array
    {
        if ($page < 1 || $page > 100) {
            throw new InvalidArgumentException('The requested page is outside the available range.');
        }

        return ['page' => $page, 'per_page' => 50];
    }

    private function hasNextPage(Response $response): bool
    {
        return str_contains($response->header('Link') ?? '', 'rel="next"');
    }

    private function assertWorkspaceRef(string $ref): void
    {
        if (! $this->validWorkspaceRef($ref)) {
            throw new InvalidArgumentException('The repository reference is invalid.');
        }
    }

    private function validWorkspaceRef(string $ref): bool
    {
        if ($ref === '' || $ref === '@' || strlen($ref) > 255 || str_starts_with($ref, '-')
            || preg_match('#[\x00-\x20\x7f~^:?*\[\\\\]#', $ref) === 1
            || str_contains($ref, '@{') || str_contains($ref, '..') || str_ends_with($ref, '.')) {
            return false;
        }
        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || str_ends_with($segment, '.lock')) {
                return false;
            }
        }

        return preg_match('//u', $ref) === 1;
    }

    private function assertWorkspacePath(string $path): void
    {
        if (! $this->safeWorkspacePath($path)) {
            throw new InvalidArgumentException('The selected path is invalid or protected.');
        }
    }

    private function safeWorkspacePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 1024 || substr_count($path, '/') > 20 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) !== 0
            || preg_match('//u', $path) !== 1 || str_starts_with(strtolower($path).'/', '.github/workflows/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || ! $this->safePathSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private function validWorkspaceText(string $content): bool
    {
        return strlen($content) <= self::WORKSPACE_FILE_BYTES && ! str_contains($content, "\0") && preg_match('//u', $content) === 1;
    }

    private function workspaceString(mixed $value, int $length): string
    {
        return is_string($value) ? mb_strcut($value, 0, $length, 'UTF-8') : '';
    }

    private function assertPullNumber(int $number): void
    {
        if ($number < 1) {
            throw new InvalidArgumentException('The pull request number is invalid.');
        }
    }

    private function assertReadPermission(array $access, string $permission): void
    {
        if (! $this->hasReadPermission($access, $permission)) {
            throw new RuntimeException('Grant the GitHub App Pull requests read access, then approve the installation update.');
        }
    }

    private function hasReadPermission(array $access, string $permission): bool
    {
        return in_array($access['permissions'][$permission] ?? null, ['read', 'write'], true);
    }

    private function workspacePullAt(string $repo, int $number, array $access): array
    {
        $this->assertReadPermission($access, 'pull_requests');

        return $this->workspacePull($this->request('GET', "$repo/pulls/$number", $access['token'])->json(), $access);
    }

    private function workspacePull(array $pull, array $access): array
    {
        $head = $pull['head']['sha'] ?? null;
        $base = $pull['base']['sha'] ?? null;
        $this->assertSha($head);

        return [
            ...$this->pullResult($pull),
            'title' => $this->workspaceString($pull['title'] ?? null, 512),
            'body' => $this->workspaceString($pull['body'] ?? null, 12000),
            'state' => ($pull['state'] ?? null) === 'open' ? 'open' : 'closed',
            'draft' => (bool) ($pull['draft'] ?? false),
            'merged' => (bool) ($pull['merged'] ?? ! empty($pull['merged_at'])),
            'mergeable' => is_bool($pull['mergeable'] ?? null) ? $pull['mergeable'] : null,
            'merge_state' => $this->qualityEnum(strtoupper($this->workspaceString($pull['mergeable_state'] ?? null, 24)), [
                'BEHIND', 'BLOCKED', 'CLEAN', 'DIRTY', 'DRAFT', 'HAS_HOOKS', 'UNKNOWN', 'UNSTABLE',
            ]),
            'head_ref' => $this->workspaceString($pull['head']['ref'] ?? null, 255),
            'head_sha' => $head,
            'base_ref' => $this->workspaceString($pull['base']['ref'] ?? null, 255),
            'base_sha' => $this->validSha($base) ? $base : null,
            'author' => $this->workspaceString($pull['user']['login'] ?? null, 128),
            'updated_at' => $this->workspaceString($pull['updated_at'] ?? null, 64),
            'changed_files' => (int) ($pull['changed_files'] ?? 0),
            'node_id' => $this->workspaceString($pull['node_id'] ?? null, 128),
            'can_merge' => ($access['permissions']['contents'] ?? null) === 'write',
        ];
    }

    private function workspacePullFiles(string $repo, int $number, string $token): array
    {
        $response = $this->request('GET', "$repo/pulls/$number/files", $token, ['per_page' => self::PULL_FILES_LIMIT, 'page' => 1]);
        $rows = $response->json();
        $truncated = count($rows) > self::PULL_FILES_LIMIT || $this->hasNextPage($response);
        $diff = [];
        $total = 0;
        foreach (array_slice($rows, 0, self::PULL_FILES_LIMIT) as $entry) {
            $path = $this->workspaceString($entry['filename'] ?? null, 1024);
            $previous = $this->workspaceString($entry['previous_filename'] ?? null, 1024);
            $additions = max(0, (int) ($entry['additions'] ?? 0));
            $deletions = max(0, (int) ($entry['deletions'] ?? 0));
            $patch = $entry['patch'] ?? null;
            if (! $this->safeWorkspacePath($path) || ($previous !== '' && ! $this->safeWorkspacePath($previous))) {
                $patch = null;
                $truncated = true;
            } elseif ($additions > 0 || $deletions > 0) {
                if (! is_string($patch) || strlen($patch) > self::PULL_PATCH_BYTES || $total + strlen($patch) > 393216
                    || preg_match_all('/^\+/m', $patch) !== $additions || preg_match_all('/^-/m', $patch) !== $deletions) {
                    $patch = null;
                    $truncated = true;
                }
            } else {
                $patch = null;
            }
            $total += strlen($patch ?? '');

            $diff[] = [
                'path' => $path,
                'previous_path' => $previous === '' ? null : $previous,
                'status' => $this->workspaceString($entry['status'] ?? null, 24),
                'additions' => $additions,
                'deletions' => $deletions,
                'patch' => $patch,
            ];
        }

        return ['files' => $diff, 'truncated' => $truncated];
    }

    private function qualitySnapshot(array $repository, int $number, string $token, bool $checks, bool $statuses): array
    {
        $query = <<<'GRAPHQL'
            query WorkspacePullQuality($owner: String!, $name: String!, $number: Int!, $contexts: Boolean!, $checks: Boolean!, $statuses: Boolean!) {
              repository(owner: $owner, name: $name) {
                pullRequest(number: $number) {
                  headRefOid
                  baseRefName
                  mergeStateStatus
                  reviewDecision
                  mergeQueue { id }
                  baseRef { refUpdateRule { requiredStatusCheckContexts } }
                  latestReviews(first: 20) {
                    nodes { author { login } state submittedAt }
                    pageInfo { hasNextPage }
                  }
                  commits(last: 1) {
                    nodes { commit {
                      oid
                      statusCheckRollup @include(if: $contexts) { contexts(first: 60) {
                        nodes {
                          ... on CheckRun @include(if: $checks) { name status conclusion isRequired(pullRequestNumber: $number) }
                          ... on StatusContext @include(if: $statuses) { name: context state isRequired(pullRequestNumber: $number) }
                        }
                        pageInfo { hasNextPage }
                      } }
                    } }
                  }
                }
              }
            }
            GRAPHQL;
        try {
            $response = $this->request('POST', '/graphql', $token, ['query' => $query, 'variables' => [
                'owner' => $repository['owner'], 'name' => $repository['name'], 'number' => $number,
                'contexts' => $checks || $statuses, 'checks' => $checks, 'statuses' => $statuses,
            ]]);
        } catch (RuntimeException) {
            return [];
        }

        $pull = $response->json('data.repository.pullRequest');

        return is_array($pull) && is_string($pull['headRefOid'] ?? null) && is_string($pull['baseRefName'] ?? null) ? $pull : [];
    }

    /** @return array{required_checks: list<string>, merge_queue: bool, truncated: bool}|null */
    private function workspaceBranchRules(string $repo, string $branch, string $token): ?array
    {
        try {
            $response = $this->request('GET', "$repo/rules/branches/".rawurlencode($branch), $token, ['per_page' => 100]);
        } catch (RuntimeException) {
            return null;
        }

        $rules = $response->json();
        if (! is_array($rules) || ! array_is_list($rules)) {
            return null;
        }

        $requiredChecks = [];
        $mergeQueue = false;
        $truncated = count($rules) > 100 || $this->hasNextPage($response);
        foreach (array_slice($rules, 0, 100) as $rule) {
            if (($rule['type'] ?? null) === 'merge_queue') {
                $mergeQueue = true;
            }
            if (($rule['type'] ?? null) !== 'required_status_checks') {
                continue;
            }
            foreach ($rule['parameters']['required_status_checks'] ?? [] as $entry) {
                $name = $this->workspaceString($entry['context'] ?? null, 255);
                if ($name !== '') {
                    $requiredChecks[$name] = $name;
                }
                if (count($requiredChecks) >= 100) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        return ['required_checks' => array_values($requiredChecks), 'merge_queue' => $mergeQueue, 'truncated' => $truncated];
    }

    /** @return list<array{name: string, state: string, required: bool}> */
    private function qualityChecks(array $contexts, array $requiredChecks, bool $allCheckTypesVisible): array
    {
        $checks = [];
        $priority = ['unavailable' => 0, 'passed' => 1, 'pending' => 2, 'failed' => 3];
        foreach (array_slice($contexts, 0, 60) as $context) {
            $name = $this->workspaceString($context['name'] ?? null, 255);
            if ($name === '') {
                continue;
            }
            $required = ($context['isRequired'] ?? null) === true;
            $state = match ($context['conclusion'] ?? $context['state'] ?? $context['status'] ?? null) {
                'SUCCESS', 'SKIPPED', 'NEUTRAL' => 'passed',
                'EXPECTED', 'PENDING', 'QUEUED', 'WAITING', 'IN_PROGRESS', 'REQUESTED' => 'pending',
                'FAILURE', 'ERROR', 'CANCELLED', 'TIMED_OUT', 'ACTION_REQUIRED', 'STALE', 'STARTUP_FAILURE' => 'failed',
                default => 'unavailable',
            };
            $existing = $checks[$name] ?? null;
            if ($existing === null || ($required && ! $existing['required'])
                || ($required === $existing['required'] && $priority[$state] > $priority[$existing['state']])) {
                $checks[$name] = ['name' => $name, 'state' => $state, 'required' => $required];
            }
        }

        foreach ($requiredChecks as $name) {
            if (! ($checks[$name]['required'] ?? false)) {
                $checks[$name] = ['name' => $name, 'state' => $allCheckTypesVisible ? 'missing' : 'unavailable', 'required' => true];
            }
        }

        $result = array_values($checks);
        usort($result, fn (array $first, array $second): int => $second['required'] <=> $first['required'] ?: strnatcasecmp($first['name'], $second['name']));

        return $result;
    }

    /** @return list<array{author: string, state: string, submitted_at: string}> */
    private function qualityReviews(array $reviews): array
    {
        $result = [];
        foreach (array_slice($reviews, 0, 20) as $review) {
            $state = $this->qualityEnum($review['state'] ?? null, ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED', 'DISMISSED', 'PENDING']);
            if ($state !== null) {
                $result[] = ['author' => $this->workspaceString($review['author']['login'] ?? null, 128), 'state' => $state,
                    'submitted_at' => $this->workspaceString($review['submittedAt'] ?? null, 64)];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function qualityBlockers(?string $mergeState, ?string $reviewDecision, array $checks, bool $mergeQueue): array
    {
        $blockers = array_keys(array_filter([
            'review_required' => $reviewDecision === 'review_required',
            'changes_requested' => $reviewDecision === 'changes_requested',
            'behind' => $mergeState === 'behind',
            'conflicts' => $mergeState === 'dirty',
            'draft' => $mergeState === 'draft',
            'merge_queue' => $mergeQueue,
        ]));
        if ($mergeState === 'blocked' && $blockers === []
            && ! array_any($checks, fn (array $check): bool => $check['required'] && in_array($check['state'], ['failed', 'pending', 'missing'], true))) {
            $blockers[] = 'repository_rules';
        }

        return $blockers;
    }

    private function qualityEnum(mixed $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? strtolower($value) : null;
    }

    private function workspaceReviewComment(array $comment): array
    {
        $path = $comment['path'] ?? null;
        if (! is_int($comment['id'] ?? null) || ! is_string($path) || ! $this->safeWorkspacePath($path)) {
            throw new RuntimeException('GitHub did not return a valid review comment.');
        }
        $line = is_int($comment['line'] ?? null) ? $comment['line'] : null;

        return [
            'id' => $comment['id'], 'path' => $path, 'line' => $line,
            'original_line' => is_int($comment['original_line'] ?? null) ? $comment['original_line'] : null,
            'side' => in_array($comment['side'] ?? null, ['LEFT', 'RIGHT'], true) ? $comment['side'] : null,
            'body' => $this->workspaceString($comment['body'] ?? null, 4000),
            'author' => $this->workspaceString($comment['user']['login'] ?? null, 128),
            'created_at' => $this->workspaceString($comment['created_at'] ?? null, 64),
            'commit_sha' => $this->validSha($comment['commit_id'] ?? null) ? $comment['commit_id'] : '',
            'outdated' => $line === null,
        ];
    }

    private function hasReviewLine(string $patch, int $line, string $side): bool
    {
        $left = $right = null;
        foreach (explode("\n", $patch) as $entry) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $entry, $hunk)) {
                [$left, $right] = [(int) $hunk[1], (int) $hunk[2]];

                continue;
            }
            if ($left === null || $right === null) {
                continue;
            }
            $marker = $entry[0] ?? '';
            if (($side === 'LEFT' && $marker === '-' && $left === $line)
                || ($side === 'RIGHT' && in_array($marker, ['+', ' '], true) && $right === $line)) {
                return true;
            }
            $left += (int) in_array($marker, ['-', ' '], true);
            $right += (int) in_array($marker, ['+', ' '], true);
        }

        return false;
    }

    private function assertReviewedHead(array $pull, string $reviewedHeadSha): void
    {
        if (! is_string($pull['head_sha'] ?? null) || strcasecmp($pull['head_sha'], $reviewedHeadSha) !== 0) {
            throw new RuntimeException('The pull request changed on GitHub. Reload and review the latest diff before continuing.');
        }
    }

    private function installationAccess(array $repository): array
    {
        $key = strtolower($repository['full_name']);
        if (isset($this->tokens[$key]) && $this->tokens[$key]['expires_at'] > time() + 60) {
            return $this->tokens[$key];
        }

        $jwt = $this->jwt();
        $installation = $this->repositoryInstallation($repository, $jwt);
        $id = (int) $installation['id'];

        $permissions = array_intersect_key($installation['permissions'] ?? [], array_flip(['contents', 'pull_requests', 'metadata']));
        foreach (['checks', 'statuses'] as $permission) {
            if (in_array($installation['permissions'][$permission] ?? null, ['read', 'write'], true)) {
                $permissions[$permission] = 'read';
            }
        }
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

    public function installationIdForRepository(string $repositoryUrl): int
    {
        return (int) $this->repositoryInstallation(RepositoryUrl::parse($repositoryUrl), $this->jwt())['id'];
    }

    private function repositoryInstallation(array $repository, string $jwt): array
    {
        $installation = $this->request('GET', $this->repoPath($repository).'/installation', $jwt)->json();
        $id = $installation['id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0 || ! empty($installation['suspended_at'])) {
            throw new RuntimeException('Install and enable the configured GitHub App on the selected repository.');
        }
        $configured = config('github.installation_id');
        if ($configured && (int) $configured !== (int) $id) {
            throw new RuntimeException('The repository belongs to a different GitHub App installation. Check GITHUB_APP_INSTALLATION_ID.');
        }

        return $installation;
    }

    private function jwt(): string
    {
        [$id, $key] = self::signingConfiguration();
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$encode(json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $id], JSON_THROW_ON_ERROR));
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App JWT could not be signed. Check the server OpenSSL configuration.');
        }

        return $unsigned.'.'.$encode($signature);
    }

    /** @return array{string, OpenSSLAsymmetricKey} */
    private static function signingConfiguration(): array
    {
        $id = (string) config('github.app_id', '');
        $path = (string) config('github.private_key_path', '');
        if (! preg_match('/^[1-9][0-9]*$/D', $id) || $path === '') {
            throw new RuntimeException('Configure GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY_PATH, then install the GitHub App on your repository.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY_PATH must point to a readable server-side GitHub App PEM key.');
        }
        $pem = @file_get_contents($path);
        $key = $pem === false ? false : @openssl_pkey_get_private($pem);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The GitHub App private key must be a valid RSA PEM key.');
        }

        return [$id, $key];
    }

    private function request(string $method, string $path, string $token, array $data = [], array $statuses = [200, 201], ?int $maximumBytes = null): Response
    {
        try {
            $request = Http::acceptJson()->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => (string) config('github.api_version', '2026-03-10'),
                'User-Agent' => 'Auto-QA-GitHub-App',
            ])->withToken($token)->withoutRedirecting()->connectTimeout(10)->timeout(30);
            if ($maximumBytes !== null) {
                $request->withOptions(['progress' => static function ($total, $downloaded) use ($maximumBytes): void {
                    if ($total > $maximumBytes || $downloaded > $maximumBytes) {
                        throw new RuntimeException('This repository exceeds the file search size limit. Use the folder explorer to browse it.');
                    }
                }]);
            }
            $response = $request->send($method, self::API.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new RuntimeException('GitHub could not be reached. Check server network access and retry the operation.');
        }

        if ($maximumBytes !== null && strlen($response->body()) > $maximumBytes) {
            throw new RuntimeException('This repository exceeds the file search size limit. Use the folder explorer to browse it.');
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
            if (! $this->safePathSegment($segment)) {
                return false;
            }
        }

        return ! str_starts_with(strtolower($path), '.github/workflows/');
    }

    private function safePathSegment(string $segment): bool
    {
        return ! in_array(strtolower($segment), ['.', '..', '.git', '.aws', '.ssh', 'secrets', 'credentials', 'private_keys', 'private-keys'], true)
            && ! preg_match('/^(?:\.env(?:\..*)?|auth\.json|credentials(?:\..*)?|secrets?(?:\..*)?)$/iD', $segment)
            && ! preg_match('/\.(?:pem|key|p12|pfx)$/iD', $segment);
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
        if (! $this->validSha($sha)) {
            throw new InvalidArgumentException('An exact 40-character Git commit or object SHA is required.');
        }
    }

    private function validSha(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[a-f0-9]{40}$/iD', $sha) === 1;
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
