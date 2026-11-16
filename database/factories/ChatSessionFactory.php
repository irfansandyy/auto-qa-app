<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\QaRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatSession>
 */
class ChatSessionFactory extends Factory
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
            'qa_run_id' => null,
            'title' => fake()->sentence(4),
        ];
    }

    /**
     * Create sequential user/assistant turns with tool logs attached to each reply.
     */
    public function withMessages(int $turns = 1, int $logsPerReply = 1): static
    {
        return $this->afterCreating(function (ChatSession $session) use ($turns, $logsPerReply): void {
            $run = $session->qaRun;

            if (! $run) {
                $run = QaRun::factory()->create();
                $session->qaRun()->associate($run)->save();
            }

            $sequence = 1;

            for ($turn = 0; $turn < $turns; $turn++) {
                ChatMessage::factory()->for($session)->create([
                    'sequence' => $sequence++,
                    'role' => 'user',
                    'content' => 'QA question '.$turn,
                ]);

                $assistant = ChatMessage::factory()->for($session)->create([
                    'sequence' => $sequence++,
                    'role' => 'assistant',
                    'content' => 'QA answer '.$turn,
                    'model_used' => 'qwen3:14b',
                    'prompt_tokens' => 10,
                    'completion_tokens' => 20,
                    'total_tokens' => 30,
                ]);

                for ($log = 0; $log < $logsPerReply; $log++) {
                    $assistant->toolExecutionLogs()->create([
                        'loggable_type' => QaRun::class,
                        'loggable_id' => $run->id,
                        'tool_name' => 'click',
                        'arguments' => ['tool' => 'click', 'id' => 'e1-1'],
                        'result' => ['url' => $session->qaRun?->project?->website_url],
                        'status' => 'succeeded',
                        'duration_ms' => 12,
                    ]);
                }
            }
        });
    }
}
