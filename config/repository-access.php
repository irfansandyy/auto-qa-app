<?php

return [
    'grants' => json_decode((string) env('AUTOQA_REPOSITORY_ACCESS', '{}'), true) ?: [],
];
