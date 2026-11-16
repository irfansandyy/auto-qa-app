<?php

$worker = env('BROWSER_WORKER_URL', 'http://127.0.0.1:3001');

return [
    'model_url' => env('MODEL_URL', 'https://senopati.its.ac.id/llm-gateway/v1'),
    'model_name' => env('MODEL_NAME', 'qwen3:14b'),
    'model_timeout' => (int) env('MODEL_TIMEOUT', 90),
    'model_key' => env('MODEL_API_KEY', ''),
    'json_mode' => (bool) env('MODEL_JSON_MODE', true),
    'fallback_enabled' => (bool) env('FALLBACK_ENABLED', false),
    'fallback_model_url' => env('FALLBACK_MODEL_URL'),
    'fallback_model_name' => env('FALLBACK_MODEL_NAME'),
    'fallback_model_key' => env('FALLBACK_MODEL_API_KEY', ''),
    'chat_system_prompt' => env('CHAT_SYSTEM_PROMPT', 'You are Auto QA, a concise QA engineer assistant. Help the operator understand testing, browser automation, and repair findings. Never claim you executed a test unless the provided context proves it.'),
    'chat_history_messages' => (int) env('CHAT_HISTORY_MESSAGES', 10),
    'chat_temperature' => env('CHAT_MODEL_TEMPERATURE'),
    'chat_max_tokens' => env('CHAT_MAX_TOKENS'),
    'chat_stream_usage' => (bool) env('CHAT_STREAM_USAGE', false),
    'chat_api_throttle' => (int) env('CHAT_API_THROTTLE', 20),
    'browser_url' => $worker,
    'browser_worker_url' => $worker,
    'browser_worker_token' => env('BROWSER_WORKER_TOKEN'),
    'browser_worker_timeout' => (int) env('BROWSER_WORKER_TIMEOUT', 45),
    'max_steps' => (int) env('AUTOQA_MAX_STEPS', 25),
    'local_targets' => array_values(array_filter(array_map('trim', explode(',', env('AUTOQA_LOCAL_TARGETS', ''))))),
    'runner_image' => env('AUTOQA_RUNNER_IMAGE'),
    'preview_origin' => env('AUTOQA_PREVIEW_ORIGIN', 'http://127.0.0.1:8081'),
    'runner_timeout' => (int) env('AUTOQA_RUNNER_TIMEOUT', 240),
];
