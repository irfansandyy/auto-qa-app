<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:1000'],
            'session_id' => [
                'nullable',
                'integer',
                Rule::exists('chat_sessions', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
