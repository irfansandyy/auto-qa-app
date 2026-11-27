<?php

return [
    'grants' => json_decode((string) env('AUTOQA_REPOSITORY_ACCESS', '{}'), true) ?: [],
    'write_grants' => json_decode((string) env('AUTOQA_REPOSITORY_WRITE_ACCESS', '{}'), true) ?: [],
];
