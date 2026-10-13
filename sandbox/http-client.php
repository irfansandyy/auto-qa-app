<?php

declare(strict_types=1);

/** Fixed HTTP transport executed only inside the network-isolated preview container. */
try {
    $input = stream_get_contents(STDIN, 131073);
    if (strlen($input) > 131072) {
        throw new RuntimeException('Request exceeds limit.');
    }
    $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    $method = $request['method'] ?? '';
    $path = $request['path'] ?? '';
    $host = $request['host'] ?? '';
    $body = base64_decode($request['body_base64'] ?? '', true);
    if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
        || ! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')
        || strlen($path) > 2048 || preg_match('/[\x00-\x20\x7f]/', $path)
        || ! preg_match('/^127\.0\.0\.1:[0-9]{4,5}$/D', $host)
        || $body === false || strlen($body) > 65536) {
        throw new RuntimeException('Request is invalid.');
    }
    $headers = '';
    $allowed = ['accept', 'accept-language', 'content-type', 'cookie', 'origin', 'referer', 'x-csrf-token', 'x-xsrf-token', 'x-livewire', 'x-requested-with', 'user-agent'];
    foreach ($request['headers'] ?? [] as $name => $value) {
        if (! is_string($name) || ! in_array(strtolower($name), $allowed, true) || ! is_string($value)
            || strlen($value) > 4096 || preg_match('/[\r\n\x00]/', $value)) {
            throw new RuntimeException('Request headers are invalid.');
        }
        $headers .= $name.': '.$value."\r\n";
    }
    if (strlen($headers) > 16384) {
        throw new RuntimeException('Request headers exceed limit.');
    }
    $socket = stream_socket_client('tcp://127.0.0.1:8080', $errorNumber, $errorMessage, 2);
    if ($socket === false) {
        throw new RuntimeException('Preview unavailable.');
    }
    stream_set_timeout($socket, 5);
    $wire = $method.' '.$path." HTTP/1.1\r\nHost: ".$host."\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n".$headers."\r\n".$body;
    while ($wire !== '') {
        $written = fwrite($socket, $wire);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Preview request could not be sent.');
        }
        $wire = substr($wire, $written);
    }
    $response = '';
    while (($boundary = strpos($response, "\r\n\r\n")) === false) {
        $chunk = fread($socket, 8192);
        if ($chunk === false || $chunk === '' || strlen($response) > 16384) {
            throw new RuntimeException('Preview response headers are invalid.');
        }
        $response .= $chunk;
    }
    if ($boundary > 16384) {
        throw new RuntimeException('Preview response headers exceed limit.');
    }
    $lines = explode("\r\n", substr($response, 0, $boundary));
    if (! preg_match('/^HTTP\/1\.[01] ([1-5][0-9]{2})(?: |$)/D', array_shift($lines), $status)) {
        throw new RuntimeException('Preview response status is invalid.');
    }
    $responseHeaders = [];
    foreach ($lines as $line) {
        if (! preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/D', $line, $match) || preg_match('/[\r\n\x00]/', $match[2])) {
            throw new RuntimeException('Preview response headers are invalid.');
        }
        if (strtolower($match[1]) === 'transfer-encoding' && strtolower($match[2]) !== 'identity') {
            throw new RuntimeException('Streaming transfer encodings are unsupported.');
        }
        $responseHeaders[] = ['name' => $match[1], 'value' => $match[2]];
    }
    $responseBody = substr($response, $boundary + 4);
    while (! feof($socket)) {
        $chunk = fread($socket, 65536);
        if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
            throw new RuntimeException('Preview response timed out.');
        }
        $responseBody .= $chunk;
        if (strlen($responseBody) > 2 * 1024 * 1024) {
            throw new RuntimeException('Preview response body exceeds limit.');
        }
    }
    fclose($socket);
    echo json_encode(['status' => (int) $status[1], 'headers' => $responseHeaders, 'body_base64' => base64_encode($responseBody)], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'The isolated preview transport failed.']);
    exit(1);
}
