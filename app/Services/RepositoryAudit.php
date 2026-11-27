<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RepositoryAuditEvent;
use App\Models\User;
use App\Services\GitHub\RepositoryUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RepositoryAudit
{
    private const ACTIONS = ['commit', 'branch_created', 'draft_opened', 'draft_ready', 'merge', 'comment'];

    public function __construct(private readonly RepositoryAccess $access) {}

    /** @return Collection<int, RepositoryAuditEvent> */
    public function recent(Project $project): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User || $project->user_id !== $actor->id || ! $project->repository_url) {
            throw new AuthorizationException;
        }
        $this->access->assertAllowed($actor, $project->repository_url);

        return RepositoryAuditEvent::query()
            ->where('project_id', $project->id)
            ->where('repository', $this->repository($project))
            ->latest('id')
            ->limit(30)
            ->get();
    }

    /**
     * @template Result
     *
     * @param  callable(): Result  $write
     * @param  array{branch?: string, base?: string, path?: string, pull_number?: int, line?: int, side?: string}  $details
     * @return Result
     */
    public function run(Project $project, User $actor, string $action, callable $write, array $details = []): mixed
    {
        if ($project->user_id !== $actor->id || ! $project->repository_url) {
            throw new AuthorizationException;
        }
        $this->access->assertCanWrite($actor, $project->repository_url);
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(__('repository-audit.unknown_action'));
        }

        $details = $this->metadata($details);
        try {
            $event = RepositoryAuditEvent::query()->create([
                'project_id' => $project->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'repository' => $this->repository($project), 'action' => $action, 'status' => 'requested', 'details' => $details,
            ]);
        } catch (QueryException $exception) {
            report($exception);
            throw new RuntimeException(__('repository-audit.unavailable'));
        }

        try {
            $result = $write();
        } catch (Throwable $exception) {
            $this->finish($event, $exception instanceof InvalidArgumentException ? 'failed' : 'unconfirmed', $details);
            throw $exception;
        }

        if (is_array($result)) {
            $details = $this->includeResult($details, $action, $result);
        }
        $this->finish($event, 'succeeded', $details);

        return $result;
    }

    private function repository(Project $project): string
    {
        return strtolower(RepositoryUrl::parse($project->repository_url)['full_name']);
    }

    private function metadata(array $details): array
    {
        $metadata = [];
        foreach (['branch', 'base', 'path', 'side'] as $key) {
            if (is_string($details[$key] ?? null)) {
                $metadata[$key] = mb_strcut($details[$key], 0, 512, 'UTF-8');
            }
        }
        foreach (['pull_number', 'line'] as $key) {
            if (is_int($details[$key] ?? null) && $details[$key] > 0) {
                $metadata[$key] = $details[$key];
            }
        }

        return $metadata;
    }

    private function includeResult(array $details, string $action, array $result): array
    {
        if (in_array($action, ['commit', 'merge'], true) && is_string($result['sha'] ?? null) && preg_match('/\A[0-9a-f]{40}\z/i', $result['sha'])) {
            $details['commit_sha'] = $result['sha'];
        }
        if ($action === 'draft_opened' && is_int($result['number'] ?? null)) {
            $details['pull_number'] = $result['number'];
        }
        if ($action === 'comment' && is_int($result['id'] ?? null)) {
            $details['comment_id'] = $result['id'];
        }

        return $details;
    }

    private function finish(RepositoryAuditEvent $event, string $status, array $details): void
    {
        try {
            $event->update(['status' => $status, 'details' => $details]);
        } catch (QueryException $exception) {
            report($exception);
        }
    }
}
