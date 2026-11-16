<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'messages_count' => $this->whenCounted('messages'),
            'created_at' => $this->created_at?->toISOString(),
            'messages' => ChatMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
