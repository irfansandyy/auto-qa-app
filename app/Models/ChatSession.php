<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ChatSession extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function qaRun(): BelongsTo
    {
        return $this->belongsTo(QaRun::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('sequence');
    }

    public function toolExecutionLogs(): HasManyThrough
    {
        return $this->hasManyThrough(
            ToolExecutionLog::class,
            ChatMessage::class,
            'chat_session_id',
            'chat_message_id',
            'id',
            'id',
        );
    }
}
