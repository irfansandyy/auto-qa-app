<?php

declare(strict_types=1);

/** Trusted host proxy. It forwards HTTP through docker exec and never loads target PHP. */
try {
    $container = getenv('AUTOQA_PREVIEW_CONTAINER') ?: '';
    $host = getenv('AUTOQA_PREVIEW_HOST') ?: '';
    if (! preg_match('/^autoqa-[a-f0-9-]{36}$/D', $container) || ! preg_match('/^127\.0\.0\.1:[0-9]{4,5}$/D', $host)) {
        throw new RuntimeException('Preview configuration is invalid.');
    }
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if ($body === false || strlen($body) > 65536) {
        http_response_code(413);
        exit('Preview requests are limited to 64 KB.');
    }
    $allowed = ['accept', 'accept-language', 'content-type', 'cookie', 'origin', 'referer', 'x-csrf-token', 'x-xsrf-token', 'x-livewire', 'x-requested-with', 'user-agent'];
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        if (in_array(strtolower($name), $allowed, true)) {
            $headers[$name] = $value;
        }
    }
    $request = json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $_SERVER['REQUEST_URI'],
        'host' => $host, 'headers' => $headers, 'body_base64' => base64_encode($body)], JSON_THROW_ON_ERROR);
    if (strlen($request) > 131072) {
        throw new RuntimeException('Preview request exceeds limit.');
    }
    $process = proc_open(['docker', 'exec', '-i', $container, 'php', '/opt/autoqa/http-client.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Preview transport unavailable.');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $offset = 0;
    $output = '';
    $errors = 0;
    $deadline = microtime(true) + 15;
    try {
        do {
            if (is_resource($pipes[0])) {
                $written = fwrite($pipes[0], substr($request, $offset, 16384));
                if ($written === false) {
                    throw new RuntimeException('Preview transport input failed.');
                }
                $offset += $written;
                if ($offset === strlen($request)) {
                    fclose($pipes[0]);
                }
            }
            $output .= fread($pipes[1], 65536);
            $errors += strlen(fread($pipes[2], 16385));
            if (strlen($output) > 3 * 1024 * 1024 || $errors > 16384 || microtime(true) > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('Preview transport exceeded its limits.');
            }
            $state = proc_get_status($process);
            if (! $state['running']) {
                while (! feof($pipes[1])) {
                    $chunk = fread($pipes[1], 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $output .= $chunk;
                    if (strlen($output) > 3 * 1024 * 1024) {
                        throw new RuntimeException('Preview transport output exceeded its limit.');
                    }
                }
                break;
            }
            usleep(10000);
        } while (true);
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    $response = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    $content = base64_decode($response['body_base64'] ?? '', true);
    if (! is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
        || $content === false || strlen($content) > 2 * 1024 * 1024) {
        throw new RuntimeException('Preview transport returned an invalid response.');
    }
    http_response_code($response['status']);
    $allowedResponse = ['content-type', 'content-encoding', 'set-cookie', 'location', 'cache-control', 'vary', 'etag', 'last-modified',
        'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'x-autoqa-commit', 'x-autoqa-patch'];
    foreach ($response['headers'] ?? [] as $header) {
        if (in_array(strtolower($header['name'] ?? ''), $allowedResponse, true)
            && is_string($header['value'] ?? null) && ! preg_match('/[\r\n\x00]/', $header['value'])) {
            header($header['name'].': '.$header['value'], false);
        }
    }
    header('Content-Length: '.strlen($content));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo $content;
    }
} catch (Throwable) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The private patched preview is not ready or exceeded its limits.']);
}
