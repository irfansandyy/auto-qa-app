<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\RepositoryUrl;
use App\Services\RepositoryAccess;
use App\Services\RepositoryAudit;
use App\Services\RepositoryDiff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RepositoryWorkspace extends Component
{
    public const SECTIONS = ['files', 'changes', 'git', 'pull-requests'];

    #[Locked]
    public string $section = 'files';

    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public ?string $mountedRepositoryUrl = null;

    #[Locked]
    public ?string $repositoryBranch = null;

    #[Locked]
    public string $repositoryDirectory = '';

    #[Locked]
    public array $repositoryBranches = [];

    #[Locked]
    public array $repositoryTree = [];

    #[Locked]
    public ?array $repositoryFile = null;

    #[Locked]
    public array $repositoryCommits = [];

    #[Locked]
    public array $repositoryPullRequests = [];

    #[Locked]
    public ?array $repositoryPullRequest = null;

    #[Locked]
    public ?array $repositorySearchResults = null;

    #[Locked]
    public ?array $repositoryQuality = null;

    #[Locked]
    public array $repositoryComments = [];

    #[Locked]
    public bool $repositoryCommentsTruncated = false;

    #[Locked]
    public bool $repositoryCommentsAvailable = false;

    #[Locked]
    public ?array $repositoryCommentAnchor = null;

    #[Locked]
    public array $repositoryChanges = [];

    #[Locked]
    public ?string $repositoryChangePath = null;

    #[Locked]
    public ?int $repositoryChangeRunId = null;

    #[Locked]
    public ?int $repositoryChangePullNumber = null;

    #[Locked]
    public bool $repositoryFollowsLatestRun = true;

    #[Locked]
    public ?string $repositoryRunVersion = null;

    #[Locked]
    public bool $repositoryTreeTruncated = false;

    #[Locked]
    public bool $repositoryChangesTruncated = false;

    #[Locked]
    public bool $repositoryHasMoreBranches = false;

    #[Locked]
    public bool $repositoryHasMoreCommits = false;

    #[Locked]
    public bool $repositoryHasMorePullRequests = false;

    #[Locked]
    public string $repositoryPullRequestState = 'open';

    #[Locked]
    public bool $editingRepositoryFile = false;

    #[Locked]
    public ?string $repositoryError = null;

    #[Locked]
    public bool $repositoryAccessDenied = false;

    #[Locked]
    public ?string $repositoryNotice = null;

    #[Locked]
    public array $repositoryPermissions = [];

    #[Locked]
    public int $branchPage = 1;

    #[Locked]
    public int $commitPage = 1;

    #[Locked]
    public int $pullPage = 1;

    public string $repositoryEditorContent = '';

    public string $repositoryCommitMessage = '';

    public string $repositoryNewBranch = '';

    public string $repositoryNewPullTitle = '';

    public string $repositoryNewPullBase = '';

    public string $repositorySearch = '';

    public string $repositoryCommentBody = '';

    private GitHubAppClient $github;

    private RepositoryAccess $access;

    public function boot(GitHubAppClient $github, RepositoryAccess $access): void
    {
        $this->github = $github;
        $this->access = $access;
    }

    public function mount(string $section, ?int $projectId = null, ?int $runId = null, ?int $pullNumber = null): void
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $this->section = $section;
        $this->projectId = $projectId;
        $this->mountedRepositoryUrl = $this->project()?->repository_url;
        $this->repositoryChangeRunId = $section === 'changes' ? $runId : null;
        $this->repositoryFollowsLatestRun = $this->repositoryChangeRunId === null;

        if ($this->mountedRepositoryUrl) {
            $this->loadRepository();

            if ($this->repositoryError === null && $section === 'pull-requests' && $pullNumber !== null) {
                $this->openRepositoryPullRequest($pullNumber);
            }
        }
    }

    public function hydrate(): void
    {
        $locale = auth()->user()?->locale;
        app()->setLocale(in_array($locale, ['en', 'id'], true) ? $locale : 'en');

        if ($this->repositoryPermissions !== [] || $this->repositoryChanges !== []) {
            $this->repositoryUrl();
        }
    }

    public function loadRepository(): void
    {
        $this->operation(function (string $url): void {
            if ($this->section === 'changes') {
                $this->loadRun($url, $this->repositoryFollowsLatestRun ? null : $this->repositoryChangeRunId);

                return;
            }

            $this->repositoryPermissions = $this->github->workspacePermissions($url);
            if ($this->section === 'pull-requests') {
                $this->loadPullRequestList($url, 1);
                if ($this->repositoryPullRequest !== null) {
                    $this->loadPullRequest($url, $this->repositoryPullRequest['number']);
                }

                return;
            }

            $this->loadBranches($url, 1);
            $lastBranch = session($this->branchPreferenceKey($url));
            $this->repositoryBranch ??= in_array($lastBranch, array_column($this->repositoryBranches, 'name'), true)
                ? $lastBranch
                : $this->repositoryPermissions['default_branch'];
            if ($this->section === 'files') {
                $this->loadDirectory($url, $this->repositoryDirectory);
                if ($this->repositoryFile !== null && ! $this->editingRepositoryFile) {
                    $this->repositoryFile = $this->github->readWorkspaceFile($url, $this->repositoryBranch, $this->repositoryFile['path']);
                }
            } else {
                if ($this->repositoryNewPullBase === '') {
                    $this->repositoryNewPullBase = $this->repositoryPermissions['default_branch'];
                }
                $this->loadCommits($url, 1);
            }
        });
    }

    public function loadMoreRepositoryBranches(): void
    {
        if ($this->repositoryHasMoreBranches) {
            $this->operation(fn (string $url) => $this->loadBranches($url, $this->branchPage + 1));
        }
    }

    public function loadMoreRepositoryCommits(): void
    {
        if ($this->section === 'git' && $this->repositoryHasMoreCommits) {
            $this->operation(fn (string $url) => $this->loadCommits($url, $this->commitPage + 1));
        }
    }

    public function loadMoreRepositoryPullRequests(): void
    {
        if ($this->section === 'pull-requests' && $this->repositoryHasMorePullRequests) {
            $this->operation(fn (string $url) => $this->loadPullRequestList($url, $this->pullPage + 1));
        }
    }

    public function selectRepositoryBranch(string $name): void
    {
        abort_unless(in_array($this->section, ['files', 'git'], true), 404);

        if ($this->editingRepositoryFile || ! in_array($name, array_column($this->repositoryBranches, 'name'), true)) {
            return;
        }

        $this->operation(function (string $url) use ($name): void {
            $this->repositoryBranch = $name;
            $this->reset(['repositoryFile', 'repositoryDirectory', 'repositoryTree', 'repositoryCommits', 'repositoryTreeTruncated', 'repositoryHasMoreCommits', 'repositorySearchResults', 'repositorySearch']);

            if ($this->section === 'files') {
                $this->loadDirectory($url, '');
            } else {
                $this->loadCommits($url, 1);
            }
            session()->put($this->branchPreferenceKey($url), $name);
        });
    }

    private function branchPreferenceKey(string $url): string
    {
        return 'autoqa.workspace.branches.'.$this->projectId.'.'.hash('sha256', $url);
    }

    public function openRepositoryDirectory(string $path): void
    {
        abort_unless($this->section === 'files', 404);

        if (! $this->editingRepositoryFile) {
            $this->operation(fn (string $url) => $this->loadDirectory($url, $path));
        }
    }

    public function openRepositoryFile(string $path): void
    {
        abort_unless($this->section === 'files', 404);

        if (! $this->editingRepositoryFile && $this->repositoryBranch !== null) {
            $this->operation(function (string $url) use ($path): void {
                $this->repositoryFile = $this->github->readWorkspaceFile($url, $this->repositoryBranch, $path);
            });
        }
    }

    public function searchRepositoryFiles(): void
    {
        abort_unless($this->section === 'files' && $this->repositoryBranch !== null, 404);
        if ($this->editingRepositoryFile) {
            return;
        }

        $this->repositorySearch = trim($this->repositorySearch);
        $this->validate(['repositorySearch' => ['required', 'string', 'min:2', 'max:120']]);

        $this->operation(function (string $url): void {
            $this->repositorySearchResults = null;
            $this->repositorySearchResults = $this->github->searchWorkspaceFiles($url, $this->repositoryBranch, $this->repositorySearch);
        });
    }

    public function clearRepositorySearch(): void
    {
        $this->reset(['repositorySearchResults', 'repositorySearch']);
        $this->resetValidation('repositorySearch');
    }

    public function editRepositoryFile(): void
    {
        abort_unless($this->section === 'files' && $this->canEditFile(), 403);

        $this->operation(function (string $url): void {
            $this->repositoryEditorContent = $this->repositoryFile['content'];
            $this->editingRepositoryFile = true;
            $this->resetValidation();
        }, write: true);
    }

    public function cancelRepositoryEdit(): void
    {
        $this->reset(['editingRepositoryFile', 'repositoryEditorContent', 'repositoryCommitMessage', 'repositoryError']);
        $this->resetValidation();
    }

    public function commitRepositoryFile(): void
    {
        abort_unless($this->section === 'files' && $this->editingRepositoryFile && $this->canEditFile(), 403);
        $this->validate([
            'repositoryEditorContent' => ['present', 'nullable', 'string', 'max:131072'],
            'repositoryCommitMessage' => ['required', 'string', 'max:250'],
        ]);

        $this->operation(function (string $url): void {
            $content = $this->repositoryEditorContent;
            $original = $this->repositoryFile['content'];
            if (str_contains($original, "\r\n") && ! str_contains(str_replace("\r\n", '', $original), "\n")) {
                $content = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $content));
            }
            if ($content === $original) {
                $this->addError('repositoryEditorContent', __('repository-actions.no_edit'));

                return;
            }

            $commit = $this->auditWrite('commit', ['branch' => $this->repositoryBranch, 'path' => $this->repositoryFile['path']], fn () => $this->github->commitWorkspaceFile(
                $url,
                $this->repositoryBranch,
                $this->repositoryFile['path'],
                $this->repositoryFile['sha'],
                $content,
                trim($this->repositoryCommitMessage),
            ));
            $this->repositoryFile['content'] = $content;
            $this->repositoryFile['sha'] = $commit['blob_sha'];
            $this->cancelRepositoryEdit();
            $this->repositoryNotice = __('repository-actions.committed', ['sha' => substr($commit['sha'], 0, 7)]);
        }, write: true);
    }

    public function createRepositoryBranch(): void
    {
        abort_unless(in_array($this->section, ['git', 'files'], true) && $this->repositoryBranch !== null && ! $this->editingRepositoryFile, 403);
        $this->validate(['repositoryNewBranch' => ['required', 'string', 'max:200']]);

        $this->operation(function (string $url): void {
            $name = trim($this->repositoryNewBranch);
            $branch = $this->auditWrite('branch_created', ['branch' => $name], fn () => $this->github->createWorkspaceBranch($url, $this->repositoryBranch, $name));
            $this->repositoryBranches[] = $branch;
            $this->repositoryNewBranch = '';
            $this->selectRepositoryBranch($branch['name']);
            $this->repositoryNotice = __('repository-actions.branch_created', ['branch' => $branch['name']]);
        }, write: true);
    }

    public function createRepositoryPullRequest(): void
    {
        abort_unless($this->section === 'git' && $this->repositoryBranch !== null, 403);
        $this->validate([
            'repositoryNewPullTitle' => ['required', 'string', 'max:200'],
            'repositoryNewPullBase' => ['required', 'string', 'max:200', 'different:repositoryBranch'],
        ]);

        $this->operation(function (string $url): void {
            $pull = $this->auditWrite('draft_opened', ['branch' => $this->repositoryBranch], fn () => $this->github->createWorkspacePullRequest($url, $this->repositoryBranch, $this->repositoryNewPullBase, $this->repositoryNewPullTitle));
            $this->redirectRoute('dashboard', ['panel' => 'pull-requests', 'project' => $this->projectId, 'pull' => $pull['number']]);
        }, write: true);
    }

    public function setRepositoryPullRequestState(string $state): void
    {
        abort_unless($this->section === 'pull-requests' && in_array($state, ['open', 'closed', 'all'], true), 404);
        $this->operation(function (string $url) use ($state): void {
            $this->repositoryPullRequestState = $state;
            $this->reset(['repositoryPullRequest', 'repositoryChangesTruncated']);
            $this->resetReview();
            $this->loadPullRequestList($url, 1);
        });
    }

    public function openRepositoryPullRequest(int $number): void
    {
        abort_unless($this->section === 'pull-requests' && $number > 0, 404);

        $this->operation(fn (string $url) => $this->loadPullRequest($url, $number));
    }

    public function loadRepositoryReview(): void
    {
        abort_unless($this->section === 'pull-requests' && $this->repositoryPullRequest !== null, 404);

        $this->operation(function (string $url): void {
            $pull = $this->repositoryPullRequest;
            try {
                $this->repositoryQuality = $this->github->pullRequestQuality($url, $pull['number'], $pull['head_sha'], $pull['base_ref']);
            } catch (RuntimeException|InvalidArgumentException) {
                $this->repositoryQuality = ['unavailable' => ['merge_status', 'check_data', 'reviews']];
            }

            try {
                $comments = $this->github->listPullRequestComments($url, $pull['number']);
                $this->repositoryComments = $comments['comments'];
                $this->repositoryCommentsTruncated = $comments['truncated'];
                $this->repositoryCommentsAvailable = true;
            } catch (RuntimeException|InvalidArgumentException) {
                $this->repositoryComments = [];
                $this->repositoryCommentsAvailable = false;
            }
        });
    }

    public function beginRepositoryComment(string $path, int $line, string $side, RepositoryDiff $diff): void
    {
        abort_unless($this->section === 'pull-requests' && $this->repositoryPullRequest !== null, 404);
        $this->operation(function (string $url) use ($path, $line, $side, $diff): void {
            abort_unless($this->canCommentOnPullRequest(), 403);
            $file = collect($this->repositoryPullRequest['files'])->firstWhere('path', $path);
            abort_unless($diff->containsChangedLine($file['patch'] ?? null, $line, $side), 404);
            $this->repositoryCommentAnchor = ['path' => $path, 'line' => $line, 'side' => $side];
            $this->repositoryCommentBody = '';
            $this->resetValidation('repositoryCommentBody');
        }, write: true);
    }

    public function cancelRepositoryComment(): void
    {
        $this->reset(['repositoryCommentAnchor', 'repositoryCommentBody']);
        $this->resetValidation('repositoryCommentBody');
    }

    public function submitRepositoryComment(): void
    {
        abort_unless($this->section === 'pull-requests' && $this->repositoryCommentAnchor !== null && $this->canCommentOnPullRequest(), 403);
        $this->repositoryCommentBody = trim($this->repositoryCommentBody);
        $this->validate(['repositoryCommentBody' => ['required', 'string', 'max:2000']]);

        $this->operation(function (string $url): void {
            $pull = $this->repositoryPullRequest;
            $anchor = $this->repositoryCommentAnchor;
            $comment = $this->auditWrite('comment', ['pull_number' => $pull['number'], ...$anchor], fn () => $this->github->createPullRequestComment(
                $url, $pull['number'], $pull['head_sha'], $anchor['path'], $anchor['line'], $anchor['side'], $this->repositoryCommentBody,
            ));
            $this->repositoryComments[] = $comment;
            $this->cancelRepositoryComment();
            $this->repositoryNotice = __('repository-review.comment_posted');
        }, write: true);
    }

    public function markRepositoryPullRequestReady(): void
    {
        abort_unless($this->section === 'pull-requests' && $this->repositoryPullRequest !== null, 404);
        $this->operation(function (string $url): void {
            $pull = $this->repositoryPullRequest;
            $this->auditWrite('draft_ready', ['pull_number' => $pull['number']], fn () => $this->github->markPullRequestReady($url, $pull['number'], $pull['head_sha']));
            $this->repositoryNotice = __('repository-actions.pull_ready');
            $this->loadPullRequest($url, $pull['number']);
            $this->loadPullRequestList($url, 1);
            $this->repositoryQuality = null;
        }, write: true);
    }

    public function mergeRepositoryPullRequest(): void
    {
        abort_unless($this->section === 'pull-requests'
            && ($this->repositoryPullRequest['can_merge'] ?? false)
            && ($this->repositoryPullRequest['mergeable'] ?? false) === true
            && ! $this->hasKnownMergeBlockers()
            && ! $this->repositoryChangesTruncated, 403);
        $this->operation(function (string $url): void {
            $pull = $this->repositoryPullRequest;
            $result = $this->auditWrite('merge', ['pull_number' => $pull['number'], 'branch' => $pull['base_ref']], fn () => $this->github->mergePullRequest($url, $pull['number'], $pull['head_sha'], $pull['base_ref'], $pull['base_sha'] ?? null));
            $this->repositoryPullRequest['merged'] = $result['merged'];
            $this->repositoryPullRequest['state'] = 'closed';
            $this->repositoryNotice = __('repository-actions.pull_merged', ['number' => $pull['number']]);
            $this->loadPullRequestList($url, 1);
        }, write: true);
    }

    public function selectRepositoryChangeRun(int $id): void
    {
        abort_unless($this->section === 'changes', 404);

        $this->operation(function (string $url) use ($id): void {
            $this->loadRun($url, $id);
            $this->repositoryFollowsLatestRun = false;
        });
    }

    public function refreshRunChanges(): void
    {
        if ($this->section === 'changes' && $this->repositoryFollowsLatestRun) {
            $this->operation(fn (string $url) => $this->loadRun($url, null, onlyChanged: true));
        }
    }

    public function openRepositoryChange(string $path, RepositoryDiff $diff): void
    {
        abort_unless($this->section === 'changes', 404);
        $this->operation(function (string $url) use ($path, $diff): void {
            $run = $this->project()->runs()->findOrFail($this->repositoryChangeRunId);
            $index = array_search($path, array_column($this->repositoryChanges, 'path'), true);
            abort_if($index === false, 404);
            $this->repositoryChangePath = $path;
            if ($this->repositoryChangePullNumber && $this->repositoryChanges[$index]['status'] !== 'proposed') {
                return;
            }

            $proposed = $run->patch_files[$path] ?? null;
            abort_unless(is_string($proposed), 404);
            $this->repositoryChanges[$index]['content'] = $proposed;
            if ($path === ($run->report['patch_added_test'] ?? null)) {
                $patch = $diff->between('', $proposed);
                if ($patch !== null) {
                    $this->repositoryChanges[$index] = [...$this->repositoryChanges[$index], ...$patch, 'status' => 'added', 'content' => null];
                }

                return;
            }
            if (! $run->base_sha) {
                return;
            }

            try {
                $base = $this->github->readWorkspaceFile($url, $run->base_sha, $path);
                $patch = $diff->between($base['content'], $proposed);
                if ($patch !== null) {
                    $this->repositoryChanges[$index] = [...$this->repositoryChanges[$index], ...$patch, 'content' => null];
                }
            } catch (RuntimeException|InvalidArgumentException) {
                // The stored proposed file is available even when its baseline cannot be opened.
            }
        });
    }

    private function loadBranches(string $url, int $page): void
    {
        $result = $this->github->listBranches($url, $page);
        $this->repositoryBranches = [...($page === 1 ? [] : $this->repositoryBranches), ...$result['items']];
        $this->branchPage = $page;
        $this->repositoryHasMoreBranches = $page < 10 && $result['has_more'];
    }

    private function loadCommits(string $url, int $page): void
    {
        $result = $this->github->listWorkspaceCommits($url, $this->repositoryBranch, $page);
        $this->repositoryCommits = [...($page === 1 ? [] : $this->repositoryCommits), ...$result['items']];
        $this->commitPage = $page;
        $this->repositoryHasMoreCommits = $page < 10 && $result['has_more'];
    }

    private function loadDirectory(string $url, string $path): void
    {
        $result = $this->github->workspaceTree($url, $this->repositoryBranch, $path);
        $this->repositoryDirectory = $path;
        $this->repositoryTree = $result['entries'];
        $this->repositoryTreeTruncated = $result['truncated'];
    }

    private function loadPullRequestList(string $url, int $page): void
    {
        $result = $this->github->listPullRequests($url, $this->repositoryPullRequestState, $page);
        $this->repositoryPullRequests = [...($page === 1 ? [] : $this->repositoryPullRequests), ...$result['items']];
        $this->pullPage = $page;
        $this->repositoryHasMorePullRequests = $page < 10 && $result['has_more'];
    }

    private function loadPullRequest(string $url, int $number): void
    {
        $pull = $this->github->getPullRequest($url, $number);
        $files = $this->github->listPullRequestFiles($url, $number);
        $updated = $this->github->getPullRequest($url, $number);
        if ($pull['head_sha'] !== $updated['head_sha'] || $pull['base_ref'] !== $updated['base_ref']
            || ($pull['base_sha'] ?? null) !== ($updated['base_sha'] ?? null)) {
            throw new RuntimeException(__('repository-actions.pull_changed'));
        }

        if ($number !== ($this->repositoryPullRequest['number'] ?? null)
            || $updated['head_sha'] !== ($this->repositoryPullRequest['head_sha'] ?? null)
            || $updated['base_ref'] !== ($this->repositoryPullRequest['base_ref'] ?? null)
            || ($updated['base_sha'] ?? null) !== ($this->repositoryPullRequest['base_sha'] ?? null)) {
            $this->resetReview();
        }
        $this->repositoryChangesTruncated = $files['truncated'] || count($files['files']) !== $updated['changed_files'];
        $this->repositoryPullRequest = [...$updated, 'files' => $files['files'], 'files_truncated' => $this->repositoryChangesTruncated];
    }

    private function resetReview(): void
    {
        $this->reset(['repositoryQuality', 'repositoryComments', 'repositoryCommentsTruncated', 'repositoryCommentsAvailable']);
        $this->cancelRepositoryComment();
    }

    private function canCommentOnPullRequest(): bool
    {
        return ($this->repositoryPullRequest['state'] ?? null) === 'open'
            && ($this->repositoryPermissions['can_write_pull_requests'] ?? false);
    }

    private function hasKnownMergeBlockers(): bool
    {
        return ($this->repositoryQuality['blockers'] ?? []) !== []
            || collect($this->repositoryQuality['checks'] ?? [])->contains(
                fn (array $check): bool => ($check['required'] ?? false) === true
                    && in_array($check['state'], ['failed', 'pending', 'missing'], true),
            );
    }

    private function auditWrite(string $action, array $details, callable $operation): mixed
    {
        $project = $this->project();
        abort_unless($project?->repository_url === $this->mountedRepositoryUrl, 409, __('repository-actions.project_changed'));

        return app(RepositoryAudit::class)->run($project, auth()->user(), $action, $operation, $details);
    }

    private function loadRun(string $url, ?int $id, bool $onlyChanged = false): void
    {
        $runs = $this->project()->runs();
        $run = $id === null
            ? $runs->where(fn ($query) => $query->whereNotNull('patch_files')->orWhereNotNull('pr_url'))->latest('id')->first()
            : $runs->findOrFail($id);
        $version = $run ? hash('sha256', serialize([$run->id, $run->base_sha, $run->pr_url, $run->patch_files])) : null;
        if ($onlyChanged && $this->repositoryRunVersion === $version) {
            return;
        }

        $this->reset(['repositoryChanges', 'repositoryChangePath', 'repositoryChangePullNumber', 'repositoryChangesTruncated']);
        $this->repositoryChangeRunId = $run?->id;
        $this->repositoryRunVersion = $version;
        if ($run === null) {
            return;
        }

        $repo = RepositoryUrl::parse($url)['full_name'];
        $origin = $run->report['patch_repository'] ?? null;
        if (is_string($origin) && strcasecmp(RepositoryUrl::parse($origin)['full_name'], $repo) !== 0) {
            throw new RuntimeException(__('repository-actions.run_repository_changed'));
        }
        if (preg_match('~\Ahttps://github\.com/'.preg_quote($repo, '~').'/pull/([1-9][0-9]*)\z~iD', $run->pr_url ?? '', $match)) {
            $this->repositoryChangePullNumber = (int) $match[1];
        } elseif (filled($run->pr_url)) {
            throw new RuntimeException(__('repository-actions.run_repository_changed'));
        }

        foreach ($run->patch_files ?? [] as $path => $content) {
            if (is_string($path) && is_string($content)) {
                $this->repositoryChanges[] = ['path' => $path, 'status' => 'proposed', 'additions' => null, 'deletions' => null, 'patch' => null];
            }
        }

        if ($this->repositoryChangePullNumber !== null) {
            $result = $this->github->listPullRequestFiles($url, $this->repositoryChangePullNumber);
            $this->repositoryChanges = $result['files'];
            $this->repositoryChangesTruncated = $result['truncated'];
        }
    }

    private function operation(callable $operation, bool $write = false): void
    {
        $this->repositoryError = null;
        $this->repositoryNotice = null;
        $this->repositoryAccessDenied = false;

        try {
            $operation($this->repositoryUrl($write));
        } catch (ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->repositoryError = $exception->getMessage();
        } catch (RuntimeException $exception) {
            // The GitHub client produces fixed errors without exposing remote response bodies.
            $this->repositoryError = $exception->getMessage();
        }
    }

    private function repositoryUrl(bool $write = false): string
    {
        $project = $this->project();
        abort_unless($project?->repository_url && auth()->user(), 404);
        abort_unless($project->repository_url === $this->mountedRepositoryUrl, 409, __('repository-actions.project_changed'));
        try {
            $this->access->assertAllowed(auth()->user(), $project->repository_url);
        } catch (AuthorizationException $exception) {
            $this->repositoryAccessDenied = true;
            throw $exception;
        }
        if ($write) {
            $this->access->assertCanWrite(auth()->user(), $project->repository_url);
        }

        return $project->repository_url;
    }

    private function project(): ?Project
    {
        return $this->projectId === null ? null : Project::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->projectId);
    }

    private function canEditFile(): bool
    {
        $branches = array_column($this->repositoryBranches, 'protected', 'name');

        return ($this->repositoryFile['editable'] ?? false)
            && ($this->repositoryPermissions['can_write_contents'] ?? false)
            && $this->repositoryBranch !== ($this->repositoryPermissions['default_branch'] ?? null)
            && ($branches[$this->repositoryBranch] ?? true) === false;
    }

    public function render(): View
    {
        $project = $this->project();
        $user = auth()->user();
        $hasReadGrant = $user && $project?->repository_url && $this->access->canView($user, $project->repository_url);
        $hasWriteGrant = $hasReadGrant && $this->access->canWrite($user, $project->repository_url);
        $canWriteContents = $hasWriteGrant && ($this->repositoryPermissions['can_write_contents'] ?? false);
        $canWritePullRequests = $hasWriteGrant && ($this->repositoryPermissions['can_write_pull_requests'] ?? false);
        $runs = $this->section === 'changes' && $hasReadGrant
            ? $project->runs()->where(fn ($query) => $query->whereNotNull('patch_files')->orWhereNotNull('pr_url'))
                ->latest('id')->limit(30)->get(['id', 'status', 'created_at', 'pr_url'])
            : collect();

        return view('livewire.repository-workspace', [
            'project' => $project,
            'repositoryRepairRuns' => $runs,
            'repositoryPollActive' => $this->section === 'changes' && $hasReadGrant && $this->repositoryFollowsLatestRun
                && $project->runs()->whereNotIn('status', Dashboard::TERMINAL_STATUSES)->exists(),
            'repositoryCanEdit' => $hasWriteGrant && $this->canEditFile(),
            'repositoryCanCreateBranch' => $canWriteContents,
            'repositoryCanOpenPull' => $canWritePullRequests && $this->repositoryBranch !== ($this->repositoryPermissions['default_branch'] ?? null),
            'repositoryDefaultBranch' => $this->repositoryPermissions['default_branch'] ?? null,
            'repositoryCanMarkReady' => $canWritePullRequests,
            'repositoryCanComment' => $hasWriteGrant && $this->canCommentOnPullRequest(),
            'repositoryMergeBlockedByQuality' => $this->hasKnownMergeBlockers(),
            'repositoryAuditEvents' => $hasReadGrant && $this->section === 'git'
                ? app(RepositoryAudit::class)->recent($project) : collect(),
            'repositoryCanMerge' => $canWriteContents && ($this->repositoryPullRequest['can_merge'] ?? false)
                && ! $this->repositoryChangesTruncated,
        ]);
    }
}
