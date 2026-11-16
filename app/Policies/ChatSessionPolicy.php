<?php

namespace App\Policies;

use App\Models\ChatSession;
use App\Models\User;

class ChatSessionPolicy
{
    public function view(User $user, ChatSession $chatSession): bool
    {
        return $user->id === $chatSession->user_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
