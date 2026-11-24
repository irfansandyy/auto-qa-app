<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

class GeminiModelPool
{
    public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const MAX_OVERLOAD_RETRY_DELAY = 3;

    /** @var array<int, array<string, int>> */
    private array $retryAt = [];

    /** @var array<string, int> */
    private array $modelRetryAt = [];

    /** @var array<int, true> */
    private array $invalidKeys = [];

    public function ready(): bool
    {
        return $this->keys() !== [] && $this->models() !== [];
    }

    public function healthy(): bool
    {
        foreach ($this->keys() as $key) {
            try {
                if ($this->request($key, 3)->get(self::BASE_URL.'/models', ['pageSize' => 1])->successful()) {
                    return true;
                }
            } catch (ConnectionException) {
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    public function generate(array $payload, int $timeout): ?Response
    {
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $keys = $this->keys();
        foreach ($this->models() as $model) {
            if (($this->modelRetryAt[$model] ?? 0) > now()->timestamp) {
                continue;
            }
            foreach ($keys as $index => $key) {
                if (isset($this->invalidKeys[$index]) || ($this->retryAt[$index][$model] ?? 0) > now()->timestamp) {
                    continue;
                }
                try {
                    $response = $this->generateWithShortRetry($key, $model, $payload, $deadline);
                } catch (ConnectionException) {
                    return null;
                }

                if ($response->status() === 429 || $response->json('error.status') === 'RESOURCE_EXHAUSTED') {
                    $this->retryAt[$index][$model] = now()->timestamp + $this->retryDelay($response);

                    continue;
                }
                if ($response->status() === 503) {
                    $this->modelRetryAt[$model] = now()->timestamp + $this->retryDelay($response);

                    break;
                }
                if ($response->status() === 401 || $this->hasInvalidKeyReason($response)) {
                    $this->invalidKeys[$index] = true;

                    continue;
                }
                if (in_array($response->status(), [403, 404], true)) {
                    $this->retryAt[$index][$model] = PHP_INT_MAX;

                    continue;
                }

                return $response;
            }
        }

        return null;
    }

    public function containsCredential(string $content): bool
    {
        return array_any($this->keys(), fn (string $key): bool => str_contains($content, $key));
    }

    /** @param array<string, mixed> $payload */
    private function generateWithShortRetry(#[\SensitiveParameter] string $key, string $model, array $payload, int $deadline): Response
    {
        $url = self::BASE_URL.'/models/'.$model.':generateContent';
        $response = $this->request($key, $this->remainingTimeout($deadline))->post($url, $payload);
        if ($response->status() !== 503) {
            return $response;
        }

        $delay = $this->retryDelay($response, 1);
        if ($delay > self::MAX_OVERLOAD_RETRY_DELAY || $this->remainingTimeout($deadline) <= $delay) {
            return $response;
        }
        Sleep::for($delay)->seconds();

        return $this->request($key, $this->remainingTimeout($deadline))->post($url, $payload);
    }

    private function remainingTimeout(int $deadline): int
    {
        $remaining = (int) ceil(($deadline - hrtime(true)) / 1_000_000_000);
        if ($remaining <= 0) {
            throw new RuntimeException(__('model-settings.connection_failed'));
        }

        return $remaining;
    }

    private function request(#[\SensitiveParameter] string $key, int $timeout): PendingRequest
    {
        return Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(min(10, $timeout))->timeout($timeout)
            ->withHeaders(['x-goog-api-key' => $key]);
    }

    private function hasInvalidKeyReason(Response $response): bool
    {
        if (! in_array($response->status(), [400, 403], true)) {
            return false;
        }
        foreach ((array) $response->json('error.details') as $detail) {
            if (in_array($detail['reason'] ?? null, ['API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED', 'API_KEY_REVOKED'], true)) {
                return true;
            }
        }

        return false;
    }

    private function retryDelay(Response $response, int $default = 60): int
    {
        $header = $response->header('Retry-After');
        $date = $header && ! ctype_digit($header) ? strtotime($header) : false;
        $delay = $date !== false ? $date - now()->timestamp : (int) $header;
        foreach ((array) $response->json('error.details') as $detail) {
            $retry = $detail['retryDelay'] ?? null;
            if (is_string($retry) && preg_match('/\A(\d+(?:\.\d+)?)s\z/', $retry, $matches) === 1) {
                $delay = max($delay, (float) $matches[1]);
            }
        }

        return $delay > 0 ? (int) ceil(min(86400, $delay)) : $default;
    }

    /** @return list<string> */
    private function keys(): array
    {
        return $this->configuredStrings('autoqa.gemini_api_keys', '/\A[^\x00-\x20\x7f]+\z/');
    }

    /** @return list<string> */
    private function models(): array
    {
        return $this->configuredStrings('autoqa.gemini_models', '/\A(?:gemini|gemma)-[a-z0-9][a-z0-9.-]{0,100}\z/');
    }

    /** @return list<string> */
    private function configuredStrings(string $config, string $pattern): array
    {
        $values = array_map(fn ($value): string => is_string($value) ? trim($value) : '', (array) config($config, []));

        return array_values(array_unique(array_filter($values, fn (string $value): bool => preg_match($pattern, $value) === 1)));
    }
}
