<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'role' => $this->role,
            'content' => $this->content,
            'model_used' => $this->model_used,
            'prompt_tokens' => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
            'total_tokens' => $this->total_tokens,
            'created_at' => $this->created_at?->toISOString(),
            'tool_execution_logs' => ToolExecutionLogResource::collection($this->whenLoaded('toolExecutionLogs')),
        ];
    }
}
