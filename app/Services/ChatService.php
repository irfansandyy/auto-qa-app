<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    public function __construct(private ModelClient $model)
    {
    }

    public function start(User $user, string $prompt): ChatSession
    {
        return ChatSession::query()->create([
            'user_id' => $user->id,
            'title' => Str::limit(trim($prompt), 250),
        ]);
    }

    public function sessionFor(User $user, ?int $sessionId, string $prompt): ChatSession
    {
        return $sessionId
            ? $user->chatSessions()->findOrFail($sessionId)
            : $this->start($user, $prompt);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function prepareUserTurn(ChatSession $session, string $prompt): array
    {
        $this->append($session, 'user', $prompt);

        return $this->messagesFor($session);
    }

    /**
     * @return array{message: ChatMessage, model_used: string|null, total_tokens: int|null}
     */
    public function send(ChatSession $session, string $prompt): array
    {
        $this->append($session, 'user', $prompt);

        $result = $this->model->chat($this->messagesFor($session));

        $assistant = $this->append(
            $session,
            'assistant',
            $result['content'],
            $result['model_used'],
            $result,
        );

        return [
            'message' => $assistant,
            'model_used' => $result['model_used'],
            'total_tokens' => $result['total_tokens'],
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function messagesFor(ChatSession $session): array
    {
        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->reorder('sequence', 'desc')
            ->limit(max(1, (int) config('autoqa.chat_history_messages', 10)))
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        return array_merge(
            [['role' => 'system', 'content' => (string) config('autoqa.chat_system_prompt')]],
            $messages,
        );
    }

    public function append(ChatSession $session, string $role, string $content, ?string $model = null, array $usage = []): ChatMessage
    {
        $content = $this->truncate($content);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($session, $role, $content, $model, $usage): ChatMessage {
                    $sequence = ((int) $session->messages()->max('sequence')) + 1;

                    return $session->messages()->create([
                        'sequence' => $sequence,
                        'role' => $role,
                        'content' => $content,
                        'model_used' => $model,
                        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                        'completion_tokens' => $usage['completion_tokens'] ?? null,
                        'total_tokens' => $usage['total_tokens'] ?? null,
                    ]);
                });
            } catch (QueryException $exception) {
                if ($attempt === 0) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('The chat message could not be saved.');
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= 32768) {
            return $content;
        }

        return mb_strcut($content, 0, 32768)."\n[truncated]";
    }
}
