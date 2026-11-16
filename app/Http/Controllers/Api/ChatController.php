<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ModelUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\ChatResource;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, ChatService $chat): JsonResource|JsonResponse
    {
        $data = $request->validated();

        $session = $chat->sessionFor($request->user(), $data['session_id'] ?? null, $data['prompt']);

        try {
            $result = $chat->send($session, $data['prompt']);
        } catch (ModelUnavailableException) {
            return response()->json([
                'status' => 'error',
                'message' => 'The language model is unavailable.',
            ], 503);
        }

        return (new ChatResource([
            'session_id' => $session->id,
            'model_used' => $result['model_used'],
            'prompt' => $data['prompt'],
            'ai_response' => $result['message']->content,
            'total_tokens' => $result['total_tokens'],
            'timestamp' => $result['message']->created_at->toDateTimeString(),
        ]))->additional(['status' => 'success']);
    }
}
