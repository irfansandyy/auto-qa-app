<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\RepositoryAuditEvent;
use App\Services\GitHub\RepositoryUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RepositoryAuditEvent> */
class RepositoryAuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'actor_id' => fn (array $attributes): int => Project::query()->findOrFail($attributes['project_id'])->user_id,
            'actor_name' => fn (array $attributes): string => Project::query()->findOrFail($attributes['project_id'])->user->name,
            'repository' => fn (array $attributes): string => strtolower(RepositoryUrl::parse(Project::query()->findOrFail($attributes['project_id'])->repository_url)['full_name']),
            'action' => 'commit',
            'status' => 'succeeded',
            'details' => ['branch' => 'fix/form-label', 'path' => 'resources/views/form.blade.php', 'commit_sha' => str_repeat('a', 40)],
        ];
    }
}
