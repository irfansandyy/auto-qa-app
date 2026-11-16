<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolExecutionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool_name' => $this->tool_name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'status' => $this->status,
            'duration_ms' => $this->duration_ms,
            'loggable_type' => $this->loggable_type,
            'loggable_id' => $this->loggable_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
