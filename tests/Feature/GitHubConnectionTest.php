<?php

namespace Tests\Feature;

use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

#[Group('github-connection')]
class GitHubConnectionTest extends TestCase
{
    private const REPOSITORY = 'https://github.com/irfansandyy/autoqa-demo-target';

    private const SOURCE_SHA = '963ee2ed09195f664ee90c6ac1797799b7ba5132';

    private const CONTROLLER = 'app/Http/Controllers/RegistrationController.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AUTOQA_GITHUB_TEST') !== '1') {
            $this->markTestSkipped('Set AUTOQA_GITHUB_TEST=1 to check the configured App against the selected demo repository without publishing changes.');
        }
    }

    public function test_configured_app_reads_the_selected_deployed_source_and_downloads_a_bounded_snapshot(): void
    {
        $this->assertSame('5295691', (string) config('github.app_id'));
        $this->assertSame('pbkk-agentic-qa-bot', config('github.app_slug'));
        $keyPath = config('github.private_key_path');
        $this->assertTrue(is_string($keyPath) && is_file($keyPath) && is_readable($keyPath), 'Configure a readable private GitHub App key file before opting in.');
        $this->assertSame(0600, fileperms($keyPath) & 0777, 'The configured App key must be readable only by its owner.');

        Http::preventStrayRequests()->allowStrayRequests([
            'https://api.github.com/repos/irfansandyy/autoqa-demo-target*',
            'https://api.github.com/app/installations/*/access_tokens',
            'https://codeload.github.com/irfansandyy/autoqa-demo-target/*',
        ]);
        Http::record();
        $requests = [];
        Http::globalRequestMiddleware(function (RequestInterface $request) use (&$requests): RequestInterface {
            $uri = $request->getUri();
            $host = $uri->getHost();
            $path = $uri->getPath();
            $method = $request->getMethod();
            $allowed = $uri->getScheme() === 'https' && in_array($uri->getPort(), [null, 443], true) && $uri->getUserInfo() === '';
            $repositoryPath = '/repos/irfansandyy/autoqa-demo-target';
            if ($host === 'api.github.com') {
                $readPath = in_array($path, [$repositoryPath, $repositoryPath.'/installation', $repositoryPath.'/git/ref/heads/main',
                    $repositoryPath.'/contents/'.self::CONTROLLER, $repositoryPath.'/tarball/'.self::SOURCE_SHA], true)
                    || preg_match('~\A'.preg_quote($repositoryPath, '~').'/git/trees/[a-f0-9]{40}\z~', $path) === 1;
                $tokenPath = preg_match('~\A/app/installations/[1-9][0-9]*/access_tokens\z~', $path) === 1;
                $allowed = $allowed && (($method === 'GET' && $readPath) || ($method === 'POST' && $tokenPath));
                if ($method === 'POST' && $tokenPath) {
                    $scope = json_decode((string) $request->getBody(), true);
                    $allowed = $allowed && ($scope['repositories'] ?? null) === ['autoqa-demo-target']
                        && array_diff(array_keys($scope['permissions'] ?? []), ['contents', 'pull_requests', 'metadata']) === [];
                }
            } elseif ($host === 'codeload.github.com') {
                $allowed = $allowed && $method === 'GET'
                    && $path === '/irfansandyy/autoqa-demo-target/legacy.tar.gz/'.self::SOURCE_SHA
                    && ! $request->hasHeader('Authorization');
            } else {
                $allowed = false;
            }
            if (! $allowed) {
                throw new RuntimeException('The connection test blocked an HTTP request outside its selected read-only repository scope.');
            }
            $requests[] = ['method' => $method, 'host' => $host, 'path' => $path];

            return $request;
        });

        $client = app(GitHubAppClient::class);
        $repository = $client->inspectRepository(self::REPOSITORY);

        $this->assertSame('irfansandyy', $repository['owner']);
        $this->assertSame('autoqa-demo-target', $repository['name']);
        $this->assertSame('irfansandyy/autoqa-demo-target', $repository['full_name']);
        $this->assertSame('main', $repository['default_branch']);
        $this->assertSame(self::SOURCE_SHA, $repository['head_sha']);
        $tokenRecord = Http::recorded(fn ($request) => $request->method() === 'POST')->last();
        $grantedPermissions = $tokenRecord[1]->json('permissions') ?? [];
        $permissionSummary = json_encode(array_intersect_key($grantedPermissions, array_flip(['contents', 'pull_requests', 'metadata'])));
        $repositoryRecord = Http::recorded(fn ($request) => $request->url() === 'https://api.github.com/repos/irfansandyy/autoqa-demo-target')->last();
        $repositoryState = [
            'push' => $repositoryRecord[1]->json('permissions.push'),
            'archived' => $repositoryRecord[1]->json('archived'),
            'disabled' => $repositoryRecord[1]->json('disabled'),
        ];
        $this->assertTrue($repository['can_push'], 'The selected installation must have Contents write access. Granted permissions: '.$permissionSummary.'. Repository state: '.json_encode($repositoryState).'. This test makes no repository writes.');
        $this->assertIsInt($repository['installation_id']);
        $this->assertGreaterThan(0, $repository['installation_id']);
        $this->assertFalse(array_key_exists('token', $repository), 'Repository metadata must not expose an installation token.');
        $client->assertPublicationAllowed(self::REPOSITORY, 'main', self::SOURCE_SHA);

        $tree = $client->repositoryTree(self::REPOSITORY, self::SOURCE_SHA);
        $paths = array_column($tree, 'path');
        $this->assertContains(self::CONTROLLER, $paths);
        $this->assertContains('routes/web.php', $paths);
        $this->assertContains('tests/Feature/RegistrationTest.php', $paths);
        $this->assertNotContains('.env', $paths);
        $files = $client->readFiles(self::REPOSITORY, self::SOURCE_SHA, [self::CONTROLLER]);
        $this->assertSame([self::CONTROLLER], array_keys($files));
        $this->assertTrue(str_contains($files[self::CONTROLLER], '$phone = $this->normalizePhone($validated[\'phone\'] ?? null);'), 'The deployed demonstration must retain its original optional-phone failure.');
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/repair-demo/'.self::CONTROLLER)), hash('sha256', $files[self::CONTROLLER]), 'The remote controller must match the prepared source fixture.');

        $directory = storage_path('app/qa/github-connection-'.bin2hex(random_bytes(8)));
        $this->assertTrue(mkdir($directory, 0700, true), 'The private snapshot test directory must be writable.');
        $archive = $directory.'/source.tar.gz';
        try {
            $client->archiveSnapshot(self::REPOSITORY, self::SOURCE_SHA, $archive);

            $this->assertFileExists($archive);
            $this->assertGreaterThan(0, filesize($archive));
            $this->assertLessThanOrEqual(config('github.max_archive_bytes'), filesize($archive));
            $this->assertSame("\x1f\x8b", file_get_contents($archive, false, null, 0, 2), 'The source download must be a gzip archive.');
            $stream = gzopen($archive, 'rb');
            $this->assertNotFalse($stream, 'The source archive must be readable without extracting or executing it.');
            try {
                $header = gzread($stream, 512);
                $this->assertTrue(is_string($header) && strlen($header) === 512, 'The downloaded source must contain a complete tar header.');
                $this->assertSame(octdec(trim(substr($header, 148, 8), " \0")), array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8))), 'The first tar header must have a valid checksum.');
            } finally {
                gzclose($stream);
            }
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
            rmdir($directory);
        }

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(1, count(array_filter($requests, fn ($request) => $request['method'] === 'POST')), 'Only one scoped installation-token request is permitted; repository mutation is blocked.');
        $this->assertSame(1, count(array_filter($requests, fn ($request) => $request['host'] === 'codeload.github.com')));

        fwrite(STDOUT, sprintf("\nGitHub connection verified: repository=%s installation_id=%d can_push=true source_sha=%s\n", $repository['full_name'], $repository['installation_id'], self::SOURCE_SHA));
    }
}
