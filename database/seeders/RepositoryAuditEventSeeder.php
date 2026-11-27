<?php

namespace Database\Seeders;

use App\Models\RepositoryAuditEvent;
use Illuminate\Database\Seeder;

class RepositoryAuditEventSeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.sqlite.database');
        if (app()->environment('testing') && ($database === ':memory:' || str_starts_with($database, '/tmp/autoqa-ui-preview-'))) {
            RepositoryAuditEvent::factory()->create();
        }
    }
}
