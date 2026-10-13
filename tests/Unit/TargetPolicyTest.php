<?php

namespace Tests\Unit;

use App\Services\TargetPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TargetPolicyTest extends TestCase
{
    public function test_allows_only_an_exact_configured_local_origin_and_its_paths(): void
    {
        config()->set('autoqa.local_targets', ['http://localhost:8080']);

        (new TargetPolicy)->assertAllowed('http://localhost:8080/register?step=2', 'local');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('otherLocalOrigins')]
    public function test_rejects_a_local_origin_that_differs_in_scheme_host_or_port(string $url): void
    {
        config()->set('autoqa.local_targets', ['http://localhost:8080']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact origin');

        (new TargetPolicy)->assertAllowed($url, 'local');
    }

    public static function otherLocalOrigins(): array
    {
        return [
            'different port' => ['http://localhost:8081'],
            'different hostname spelling' => ['http://127.0.0.1:8080'],
            'different scheme' => ['https://localhost:8080'],
            'default port' => ['http://localhost'],
        ];
    }

    #[DataProvider('invalidLocalConfiguration')]
    public function test_rejects_non_origin_entries_in_the_administrator_local_allowlist(string $entry): void
    {
        config()->set('autoqa.local_targets', [$entry]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AUTOQA_LOCAL_TARGETS');

        (new TargetPolicy)->assertAllowed('http://localhost:8080/register', 'local');
    }

    public static function invalidLocalConfiguration(): array
    {
        return [
            'missing scheme' => ['localhost:8080'],
            'embedded credentials' => ['http://user:password@localhost:8080'],
            'path scoped entry' => ['http://localhost:8080/admin'],
            'query string' => ['http://localhost:8080?secret=true'],
            'fragment' => ['http://localhost:8080#admin'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://localhost:8080'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_rejects_non_http_credential_bearing_and_unknown_mode_targets(string $url, string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS');

        (new TargetPolicy)->assertAllowed($url, $mode);
    }

    public static function invalidTargets(): array
    {
        return [
            'file URL' => ['file:///etc/passwd', 'public'],
            'script URL' => ['javascript:alert(1)', 'public'],
            'embedded password' => ['http://user:password@8.8.8.8', 'public'],
            'embedded username' => ['http://user@8.8.8.8', 'public'],
            'unknown mode' => ['http://8.8.8.8', 'unrestricted'],
        ];
    }

    #[DataProvider('privatePublicTargets')]
    public function test_public_mode_rejects_private_loopback_metadata_and_reserved_literals(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TargetPolicy)->assertAllowed($url, 'public');
    }

    public static function privatePublicTargets(): array
    {
        return [
            'loopback' => ['http://127.0.0.1'],
            'private class A' => ['http://10.20.30.40'],
            'private class B' => ['http://172.16.1.1'],
            'private class C' => ['http://192.168.1.1'],
            'metadata' => ['http://169.254.169.254'],
            'shared address space' => ['http://100.64.0.1'],
            'benchmark range' => ['http://198.18.0.1'],
            'documentation IPv4' => ['http://192.0.2.1'],
            'IPv6 loopback' => ['http://[::1]'],
            'IPv6 link local' => ['http://[fe80::1]'],
            'IPv6 private' => ['http://[fd00::1]'],
            'IPv6 mapped private' => ['http://[::ffff:127.0.0.1]'],
            'IPv6 documentation' => ['http://[2001:db8::1]'],
            'localhost host' => ['http://localhost:8080'],
            'localhost suffix' => ['http://app.localhost:8080'],
            'local suffix' => ['http://app.local:8080'],
        ];
    }

    public function test_accepts_a_public_literal_without_requiring_dns(): void
    {
        (new TargetPolicy)->assertAllowed('https://8.8.8.8/example', 'public');

        $this->addToAssertionCount(1);
    }

    public function test_normalizes_default_ports_when_comparing_origins(): void
    {
        config()->set('autoqa.local_targets', ['http://localhost:80/']);

        (new TargetPolicy)->assertAllowed('http://localhost/register', 'local');

        $this->assertSame('http://localhost', (new TargetPolicy)->origin('http://localhost:80/register'));
    }
}
