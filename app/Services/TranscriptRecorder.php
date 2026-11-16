<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\QaRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class TranscriptRecorder
{
    private ?ChatSession $session = null;

    private ?ChatMessage $currentMessage = null;

    private ?ChatMessage $systemMessage = null;

    public function begin(QaRun $run): void
    {
        try {
            $session = ChatSession::query()->firstOrCreate(
                ['qa_run_id' => $run->id],
                [
                    'user_id' => $run->project->user_id,
                    'title' => mb_substr($run->goal, 0, 255),
                ],
            );

            $this->session = $session;
            $this->currentMessage = null;
            $this->systemMessage = null;
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function recordExchange(string $instruction, array $context, string $assistantText, ?string $model, ?array $usage): void
    {
        if (! $this->session) {
            return;
        }

        try {
            $this->append([
                'role' => 'system',
                'content' => $this->truncate($instruction),
            ]);
            $this->append([
                'role' => 'user',
                'content' => $this->truncate(json_encode($context, JSON_THROW_ON_ERROR)),
            ]);
            $this->currentMessage = $this->append([
                'role' => 'assistant',
                'content' => $this->truncate($assistantText),
                'model_used' => $model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function currentMessage(): ?ChatMessage
    {
        return $this->currentMessage;
    }

    public function logTool(array $action, array $result, string $status, int $durationMs, bool $system = false): void
    {
        if (! $this->session || ! $this->session->qa_run_id) {
            return;
        }

        try {
            $message = $system ? $this->systemMessage() : ($this->currentMessage ?? $this->systemMessage());

            if (! $system) {
                $this->currentMessage = $message;
            }

            $message->toolExecutionLogs()->create([
                'loggable_type' => QaRun::class,
                'loggable_id' => $this->session->qa_run_id,
                'tool_name' => (string) ($action['tool'] ?? 'unknown'),
                'arguments' => $action,
                'result' => $result,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function session(): ?ChatSession
    {
        return $this->session;
    }

    public function reset(): void
    {
        $this->session = null;
        $this->currentMessage = null;
        $this->systemMessage = null;
    }

    private function append(array $attributes): ChatMessage
    {
        if (! $this->session) {
            throw new \RuntimeException('No active transcript session.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes): ChatMessage {
                    $sequence = ((int) $this->session->messages()->max('sequence')) + 1;

                    return $this->session->messages()->create([
                        'sequence' => $sequence,
                        ...$attributes,
                    ]);
                });
            } catch (QueryException $exception) {
                if ($attempt === 0) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('The transcript message could not be recorded.');
    }

    private function systemMessage(): ChatMessage
    {
        return $this->systemMessage ??= $this->append([
            'role' => 'system',
            'content' => 'Automated QA run actions.',
        ]);
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= 32768) {
            return $content;
        }

        return mb_strcut($content, 0, 32768)."\n[truncated]";
    }
}
