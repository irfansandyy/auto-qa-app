<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatSessionPageController extends Controller
{
    public function __invoke(ChatSession $session): View
    {
        Gate::authorize('view', $session);

        $session->load(['messages.toolExecutionLogs']);

        return view('chat.show', ['session' => $session]);
    }
}
