<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrowserWorker
{
    public function ready(): bool
    {
        $token = (string) config('autoqa.browser_worker_token', config('autoqa.browser_token', ''));
        $url = (string) config('autoqa.browser_worker_url', config('autoqa.browser_url', ''));

        return strlen($token) >= 24 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function health(): array
    {
        try {
            return $this->request('GET', '/health');
        } catch (RuntimeException $exception) {
            return ['status' => 'unavailable', 'ready' => false, 'message' => $exception->getMessage()];
        }
    }

    public function start(string $url, string $mode = 'public'): array
    {
        return $this->request('POST', '/sessions', ['url' => $url, 'mode' => $mode]);
    }

    public function snapshot(string $sessionId): array
    {
        $result = $this->request('GET', $this->sessionPath($sessionId));

        return $result['snapshot'] ?? [];
    }

    public function act(string $sessionId, array $action): array
    {
        return $this->request('POST', $this->sessionPath($sessionId).'/action', $action);
    }

    public function screenshot(string $sessionId): array
    {
        return $this->act($sessionId, ['tool' => 'screenshot']);
    }

    public function close(string $sessionId): array
    {
        return $this->request('DELETE', $this->sessionPath($sessionId));
    }

    private function sessionPath(string $sessionId): string
    {
        if (! preg_match('/\A[a-zA-Z0-9-]{1,80}\z/', $sessionId)) {
            throw new RuntimeException('Invalid browser session reference.');
        }

        return '/sessions/'.$sessionId;
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $token = (string) config('autoqa.browser_worker_token', config('autoqa.browser_token', ''));
        if (strlen($token) < 24) {
            throw new RuntimeException('Configure BROWSER_WORKER_TOKEN with at least 24 characters before running browser QA.');
        }

        $baseUrl = rtrim((string) config('autoqa.browser_worker_url', config('autoqa.browser_url', 'http://127.0.0.1:3001')), '/');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->connectTimeout(3)
                ->timeout((int) config('autoqa.browser_worker_timeout', 45))
                ->send($method, $baseUrl.$path, $data ? ['json' => $data] : []);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The private browser worker is unavailable. Start it and check BROWSER_WORKER_URL.', previous: $exception);
        }

        $result = $response->json();
        if (! $response->successful()) {
            $message = is_array($result) && is_string($result['error'] ?? null)
                ? $result['error']
                : 'The browser worker request failed (HTTP '.$response->status().').';

            throw new RuntimeException($message);
        }

        if (! is_array($result)) {
            throw new RuntimeException('The browser worker returned an invalid response.');
        }

        $filenames = array_column($result['artifacts'] ?? [], 'filename');
        if (isset($result['artifact'])) {
            $filenames[] = $result['artifact'];
        }
        foreach ($filenames as $filename) {
            if (! is_string($filename) || ! preg_match('/\Abrowser-[a-zA-Z0-9-]+\.(png|zip)\z/', $filename)) {
                throw new RuntimeException('The browser worker returned an unsafe evidence filename.');
            }
        }

        return $result;
    }
}
