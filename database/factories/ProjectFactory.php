<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'repository_url' => 'https://github.com/example/laravel-demo',
            'website_url' => 'https://demo.example.com',
            'target_mode' => 'public',
            'goal' => 'Test the registration form with a blank phone number.',
            'expected_behavior' => 'Show validation feedback without a server error.',
            'repair_policy' => 'suggest_only',
        ];
    }

    public function local(): static
    {
        return $this->state(fn () => ['website_url' => 'http://127.0.0.1:8080', 'target_mode' => 'local']);
    }

    public function publishing(): static
    {
        return $this->state(fn () => ['repair_policy' => 'verified_pr', 'deployed_sha' => str_repeat('a', 40)]);
    }
}
