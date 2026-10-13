<?php

return [
    'app_id' => env('GITHUB_APP_ID'),
    'app_slug' => env('GITHUB_APP_SLUG'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    // Optional: pin the expected installation. Otherwise discover it from the repository.
    'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
    'api_version' => env('GITHUB_API_VERSION', '2026-03-10'),
    'max_read_file_bytes' => 64 * 1024,
    'max_read_total_bytes' => 256 * 1024,
    'max_archive_bytes' => 20 * 1024 * 1024,
];
