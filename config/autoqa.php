<?php

$worker = env('BROWSER_WORKER_URL', 'http://127.0.0.1:3001');

return [
    'model_url' => env('MODEL_URL', 'http://127.0.0.1:11434/v1'),
    'model_name' => env('MODEL_NAME', 'qwen3:1.7b'),
    'model_timeout' => (int) env('MODEL_TIMEOUT', 90),
    'model_key' => env('MODEL_API_KEY', ''),
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
