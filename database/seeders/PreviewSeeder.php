<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PreviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = (string) config('database.connections.sqlite.database');
        if (! app()->environment('testing') || ! str_starts_with($path, '/tmp/autoqa-ui-preview-')) {
            throw new \RuntimeException('Preview data requires an isolated /tmp/autoqa-ui-preview-* SQLite database and APP_ENV=testing.');
        }
        User::factory()->create(['name' => 'Preview Operator', 'email' => 'preview@example.test', 'password' => 'Disposable-preview-2026']);
    }
}
