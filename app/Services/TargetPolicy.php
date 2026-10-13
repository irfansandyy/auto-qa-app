<?php

namespace App\Services;

use InvalidArgumentException;

class TargetPolicy
{
    public function assertAllowed(string $url, string $mode): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($mode, ['public', 'local'], true)) {
            throw new InvalidArgumentException('Enter an HTTP or HTTPS website URL without embedded credentials.');
        }
        if ($mode === 'local') {
            $allowed = [];
            foreach (config('autoqa.local_targets', []) as $entry) {
                $configured = parse_url($entry);
                if (! is_array($configured) || ! in_array($configured['scheme'] ?? '', ['http', 'https'], true)
                    || empty($configured['host']) || isset($configured['user']) || isset($configured['pass'])
                    || isset($configured['query']) || isset($configured['fragment']) || ! in_array($configured['path'] ?? '', ['', '/'], true)) {
                    throw new InvalidArgumentException('AUTOQA_LOCAL_TARGETS must contain exact HTTP origins without credentials, paths, queries, or fragments.');
                }
                $allowed[] = $this->origin($entry);
            }
            if (! in_array($this->origin($url), $allowed, true)) {
                throw new InvalidArgumentException('This local address is not configured on the browser server. Add its exact origin to AUTOQA_LOCAL_TARGETS, or use a public tunnel URL.');
            }

            return;
        }
        $host = trim(strtolower($parts['host']), '[]');
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Choose Local website for localhost on the browser server, or provide a tunnel URL for another computer.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new InvalidArgumentException('Public website mode cannot access private or reserved network addresses.');
            }
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if (! $records) {
                throw new InvalidArgumentException('The website hostname could not be resolved. Check the address.');
            }
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip && ! $this->isPublicIp($ip)) {
                    throw new InvalidArgumentException('This hostname resolves to a private or reserved address. Use a configured local target instead.');
                }
            }
        }
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid website origin.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port);
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
