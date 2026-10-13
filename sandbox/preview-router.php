<?php

declare(strict_types=1);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__autoqa_health') {
    header('Content-Type: application/json');
    echo json_encode(['source_sha' => getenv('AUTOQA_SOURCE_SHA'), 'patch_sha256' => getenv('AUTOQA_PATCH_SHA256')]);

    return;
}

header('X-AutoQA-Commit: '.getenv('AUTOQA_SOURCE_SHA'));
header('X-AutoQA-Patch: '.getenv('AUTOQA_PATCH_SHA256'));
$path = realpath('/workspace/public/'.ltrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'), '/'));
if ($path !== false && str_starts_with($path, '/workspace/public/') && is_file($path) && ! str_ends_with($path, '.php')) {
    return false;
}
require '/workspace/public/index.php';
