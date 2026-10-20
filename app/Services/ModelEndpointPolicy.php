<?php

namespace App\Services;

use InvalidArgumentException;

class ModelEndpointPolicy
{
    /** @return array{allow_redirects: false, proxy: string, curl: array<int, mixed>} */
    public function requestOptions(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
            || preg_match('/[\\\\\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException(__('model-settings.public_endpoint'));
        }

        $host = trim(strtolower($parts['host']), '[]');
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal') || str_ends_with($host, '.test') || str_ends_with($host, '.invalid')) {
            throw new InvalidArgumentException(__('model-settings.public_endpoint'));
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $isIp && (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || ! str_contains($host, '.'))) {
            throw new InvalidArgumentException(__('model-settings.public_endpoint'));
        }

        $addresses = $isIp ? [$host] : $this->resolveHost($host);
        if ($addresses === []) {
            throw new InvalidArgumentException(__('model-settings.unresolved_endpoint'));
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new InvalidArgumentException(__('model-settings.public_endpoint'));
            }
        }
        if (! extension_loaded('curl')) {
            throw new InvalidArgumentException(__('model-settings.endpoint_unavailable'));
        }

        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => $isIp ? [] : [CURLOPT_RESOLVE => [$host.':443:'.$address]],
        ];
    }

    /** @return list<string> */
    protected function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    private function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b, $c] = array_map('intval', explode('.', $ip));

            return ! ($a === 0 || $a >= 224
                || ($a === 100 && $b >= 64 && $b <= 127)
                || ($a === 192 && $b === 0 && in_array($c, [0, 2], true))
                || ($a === 192 && $b === 88 && $c === 99)
                || ($a === 198 && in_array($b, [18, 19], true))
                || ($a === 198 && $b === 51 && $c === 100)
                || ($a === 203 && $b === 0 && $c === 113));
        }
        $prefix = unpack('nfirst/nsecond', inet_pton($ip));

        return ($prefix['first'] & 0xE000) === 0x2000
            && $prefix['first'] !== 0x2002
            && ! ($prefix['first'] === 0x2001 && ($prefix['second'] < 0x0200 || $prefix['second'] === 0x0DB8));
    }
}
