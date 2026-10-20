<?php

namespace Tests\Feature;

use App\Services\ModelEndpointPolicy;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelEndpointPolicyTest extends TestCase
{
    #[DataProvider('unsafeEndpoints')]
    public function test_rejects_private_non_https_and_ambiguous_endpoints_before_network_access(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('model-settings.public_endpoint'));

        (new ModelEndpointPolicy)->requestOptions($url);
    }

    public static function unsafeEndpoints(): array
    {
        return array_map(fn (string $url): array => [$url], [
            'http://example.com/v1', 'https://localhost/v1', 'https://service.local/v1', 'https://service.internal/v1',
            'https://user:pass@example.com/v1', 'https://example.com/v1?key=secret', 'https://example.com/v1#fragment',
            'https://example.com:8443/v1', 'https://127.0.0.1/v1', 'https://10.0.0.2/v1', 'https://169.254.169.254/v1',
            'https://192.168.1.1/v1', 'https://100.64.0.1/v1', 'https://198.18.0.1/v1', 'https://192.0.2.1/v1',
            'https://[::1]/v1', 'https://[::ffff:127.0.0.1]/v1', 'https://[2001:db8::1]/v1', 'https://[2002:7f00:1::]/v1',
            'https://2130706433/v1', 'https://example.com\\@127.0.0.1/v1',
        ]);
    }

    public function test_resolves_and_pins_public_dns_answers_without_redirects_or_environment_proxy(): void
    {
        $policy = Mockery::mock(ModelEndpointPolicy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $policy->shouldReceive('resolveHost')->with('api.example.com')->once()->andReturn(['93.184.216.34', '2606:4700:4700::1111']);

        $options = $policy->requestOptions('https://api.example.com/llm-gateway/v1');

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['api.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    #[DataProvider('unsafeDnsAnswers')]
    public function test_rejects_dns_hosts_with_any_private_reserved_or_missing_answer(array $addresses, string $message): void
    {
        $policy = Mockery::mock(ModelEndpointPolicy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $policy->shouldReceive('resolveHost')->with('api.example.com')->once()->andReturn($addresses);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__($message));

        $policy->requestOptions('https://api.example.com/v1');
    }

    public static function unsafeDnsAnswers(): array
    {
        return [
            'mixed public and private IPv4' => [['93.184.216.34', '10.0.0.1'], 'model-settings.public_endpoint'],
            'mixed public and private IPv6' => [['93.184.216.34', 'fd00::1'], 'model-settings.public_endpoint'],
            'IPv4 mapped' => [['::ffff:10.0.0.1'], 'model-settings.public_endpoint'],
            'no answers' => [[], 'model-settings.unresolved_endpoint'],
        ];
    }

    public function test_dns_is_checked_again_for_each_request_to_prevent_rebinding(): void
    {
        $policy = Mockery::mock(ModelEndpointPolicy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $policy->shouldReceive('resolveHost')->with('api.example.com')->twice()->andReturn(['93.184.216.34'], ['127.0.0.1']);
        $policy->requestOptions('https://api.example.com/v1');
        $this->expectException(InvalidArgumentException::class);

        $policy->requestOptions('https://api.example.com/v1');
    }
}
