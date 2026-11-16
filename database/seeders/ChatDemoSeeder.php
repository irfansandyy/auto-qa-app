<?php

namespace Database\Seeders;

use App\Models\ChatSession;
use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChatDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first() ?? User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $run = QaRun::factory()->for($project)->create();

        ChatSession::factory()
            ->for($user)
            ->for($run)
            ->withMessages(2, 1)
            ->create(['title' => 'Demo QA transcript']);
    }
}
