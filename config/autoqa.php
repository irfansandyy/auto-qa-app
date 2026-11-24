<?php

$worker = env('BROWSER_WORKER_URL', 'http://127.0.0.1:3001');
$googleModels = [
    'gemini-4-hydrogen',
    'gemini-3.8-flash',
    'gemini-3.6-flash',
    'gemini-3.5-flash-lite',
    'gemini-3.1-flash-lite',
    'gemini-3-flash-preview',
    'gemini-2.5-flash-lite',
    'gemma-4-31b-it',
    'gemma-4-26b-a4b-it',
];

return [
    'model_url' => env('MODEL_URL', 'http://127.0.0.1:11434/v1'),
    'model_name' => env('MODEL_NAME', 'qwen3:1.7b'),
    'model_timeout' => (int) env('MODEL_TIMEOUT', 90),
    'model_repair_timeout' => (int) env('MODEL_REPAIR_TIMEOUT', 600),
    'model_key' => env('MODEL_API_KEY', ''),
    'model_reasoning_effort' => env('MODEL_REASONING_EFFORT'),
    'gemini_api_keys' => [env('GEMINI_API_KEY_1'), env('GEMINI_API_KEY_2'), env('GEMINI_API_KEY_3')],
    'gemini_models' => env('GEMINI_MODELS') ? explode(',', env('GEMINI_MODELS')) : $googleModels,
    'senopati_api_key' => env('SENOPATI_API_KEY'),
    'senopati_model' => env('SENOPATI_MODEL', 'qwen3:14b'),
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
