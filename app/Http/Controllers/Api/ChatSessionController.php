<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatSessionResource;
use App\Models\ChatSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ChatSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = $request->user()->chatSessions()
            ->withCount('messages')
            ->latest()
            ->paginate(20);

        return ChatSessionResource::collection($sessions);
    }

    public function show(ChatSession $session): ChatSessionResource
    {
        Gate::authorize('view', $session);

        $session->load(['messages.toolExecutionLogs']);

        return new ChatSessionResource($session);
    }
}
