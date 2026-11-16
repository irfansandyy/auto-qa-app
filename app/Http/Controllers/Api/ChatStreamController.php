<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ModelUnavailableException;
use App\Exceptions\StreamUnsupportedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Services\ChatService;
use App\Services\ModelClient;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function __invoke(ChatRequest $request, ChatService $chat, ModelClient $model): StreamedResponse|JsonResponse
    {
        $data = $request->validated();
        $session = $chat->sessionFor($request->user(), $data['session_id'] ?? null, $data['prompt']);
        $messages = $chat->prepareUserTurn($session, $data['prompt']);

        try {
            $events = $model->streamEvents($messages);
            $first = $events->valid() ? $events->current() : null;
        } catch (StreamUnsupportedException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Streaming is not supported by the model provider.',
            ], 501);
        } catch (ModelUnavailableException) {
            return response()->json([
                'status' => 'error',
                'message' => 'The language model is unavailable.',
            ], 503);
        }

        return response()->stream(function () use ($events, $first, $chat, $session): void {
            ignore_user_abort(true);
            @set_time_limit(0);

            $content = '';
            $modelUsed = null;
            $usage = null;
            $failed = false;

            $emit = function (array $event) use (&$content, &$modelUsed, &$usage): void {
                if (($event['type'] ?? null) === 'token') {
                    $delta = (string) ($event['delta'] ?? '');
                    $content .= $delta;
                    echo "event: token\n";
                    echo 'data: '.json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                }

                if (($event['type'] ?? null) === 'meta') {
                    $modelUsed = $event['model_used'] ?? null;
                    $usage = is_array($event['usage'] ?? null) ? $event['usage'] : null;
                }
            };

            try {
                if (is_array($first)) {
                    $emit($first);
                    @ob_flush();
                    @flush();
                }

                while ($events->valid()) {
                    $events->next();

                    if ($events->valid()) {
                        $emit($events->current());
                        @ob_flush();
                        @flush();
                    }
                }
            } catch (\Throwable $exception) {
                $failed = true;
                report($exception);
                echo "event: error\n";
                echo 'data: '.json_encode(['message' => 'The response stream was interrupted.'], JSON_UNESCAPED_SLASHES)."\n\n";
            } finally {
                try {
                    $chat->append(
                        $session,
                        'assistant',
                        $content,
                        $modelUsed,
                        $usage ?? [],
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }

                echo "event: done\n";
                echo 'data: '.json_encode([
                    'session_id' => $session->id,
                    'model_used' => $modelUsed ?? config('autoqa.model_name'),
                    'total_tokens' => $usage['total_tokens'] ?? null,
                    'failed' => $failed,
                ], JSON_UNESCAPED_SLASHES)."\n\n";
                @ob_flush();
                @flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
