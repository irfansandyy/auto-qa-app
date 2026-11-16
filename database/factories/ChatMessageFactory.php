<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_session_id' => ChatSession::factory(),
            'sequence' => 1,
            'role' => 'user',
            'content' => fake()->paragraph(),
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'model_used' => 'qwen3:14b',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
        ]);
    }
}
