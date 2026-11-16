<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\QaRun;
use App\Models\ToolExecutionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolExecutionLog>
 */
class ToolExecutionLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_message_id' => ChatMessage::factory(),
            'loggable_type' => QaRun::class,
            'loggable_id' => QaRun::factory(),
            'tool_name' => 'click',
            'arguments' => ['tool' => 'click', 'id' => 'e1-1'],
            'result' => ['url' => 'https://example.test'],
            'status' => 'succeeded',
            'duration_ms' => 10,
        ];
    }
}
