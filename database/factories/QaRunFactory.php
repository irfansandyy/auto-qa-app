<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\QaRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaRun>
 */
class QaRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => 'queued',
            'goal' => 'Test the registration form with a blank phone number.',
            'expected_behavior' => 'Show validation feedback without a server error.',
        ];
    }
}
