<?php

namespace Tests\Unit;

use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GitHubAppClientTest extends TestCase
{
    private const REPOSITORY = 'https://github.com/student/demo';

    private const BASE = '1111111111111111111111111111111111111111';

    private const TREE = '2222222222222222222222222222222222222222';

    private const BLOB = '3333333333333333333333333333333333333333';

    private const NEW_TREE = '4444444444444444444444444444444444444444';

    private const COMMIT = '5555555555555555555555555555555555555555';

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyPath = tempnam(sys_get_temp_dir(), 'autoqa-github-key-');
        file_put_contents($this->keyPath, $pem);
        config()->set('github.app_id', '12345');
        config()->set('github.private_key_path', $this->keyPath);
        config()->set('github.installation_id', null);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPath) && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        parent::tearDown();
    }

    public function test_inspects_the_selected_repository_with_a_scoped_token_and_signed_jwt(): void
    {
        $this->fakeApi();

        $result = (new GitHubAppClient)->inspectRepository(self::REPOSITORY);

        $this->assertSame(self::BASE, $result['head_sha']);
        $this->assertSame('main', $result['default_branch']);
        $this->assertSame(42, $result['installation_id']);
        $this->assertTrue($result['can_push']);
        $this->assertArrayNotHasKey('token', $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/app/installations/42/access_tokens'
            && $request['repositories'] === ['demo']
            && ! isset($request['permissions']['actions']));
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/installation')) {
                return false;
            }
            $jwt = substr($request->header('Authorization')[0], 7);
            [$header, $payload, $signature] = explode('.', $jwt);
            $decode = fn (string $value) => base64_decode(strtr($value, '-_', '+/'));
            $claims = json_decode($decode($payload), true);
            $public = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents($this->keyPath)))['key'];

            return json_decode($decode($header), true)['alg'] === 'RS256'
                && $claims['iss'] === '12345' && $claims['exp'] > time()
                && openssl_verify($header.'.'.$payload, $decode($signature), $public, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_read_only_installation_is_reported_without_push_access(): void
    {
        $this->fakeApi(['POST /app/installations/42/access_tokens' => [
            'token' => 'installation-token', 'expires_at' => gmdate('c', time() + 3600),
            'permissions' => ['contents' => 'read', 'pull_requests' => 'read'],
        ]]);

        $this->assertFalse((new GitHubAppClient)->inspectRepository(self::REPOSITORY)['can_push']);
    }

    public function test_missing_credentials_fail_before_any_http_request(): void
    {
        config()->set('github.app_id', '');
        Http::fake();
        try {
            (new GitHubAppClient)->inspectRepository(self::REPOSITORY);
            $this->fail('Expected a missing-configuration error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GITHUB_APP_ID', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_reads_only_safe_bounded_text_and_skips_missing_or_binary_files(): void
    {
        $this->fakeApi([
            'GET /repos/student/demo/contents/app/Good.php' => ['type' => 'file', 'encoding' => 'base64', 'size' => 5, 'content' => base64_encode('hello')],
            'GET /repos/student/demo/contents/app/Binary.php' => ['type' => 'file', 'encoding' => 'base64', 'size' => 3, 'content' => base64_encode("a\0b")],
            'GET /repos/student/demo/contents/app/Large.php' => ['type' => 'file', 'encoding' => 'base64', 'size' => 1000000, 'content' => ''],
        ]);

        $result = (new GitHubAppClient)->readFiles(self::REPOSITORY, self::BASE, [
            'app/Good.php', 'app/Binary.php', 'app/Large.php', 'app/Missing.php',
            '.env', '../secret', 'app/credentials.json', 'app/private.pem', ['invalid'],
        ]);

        $this->assertSame(['app/Good.php' => 'hello'], $result);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '.env') || str_contains($request->url(), 'credentials') || str_contains($request->url(), '.pem'));
    }

    public function test_commits_a_bounded_repair_to_one_new_branch_as_the_app_bot(): void
    {
        $this->fakeApi();

        $result = (new GitHubAppClient)->commitRepair(self::REPOSITORY, self::BASE, 'agent/fix-registration', ['app/Registration.php' => '<?php // fixed'], 'Fix optional phone registration');

        $this->assertSame(['branch' => 'agent/fix-registration', 'sha' => self::COMMIT], $result);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/commits')
            && $request['parents'] === [self::BASE]
            && $request['author']['name'] === 'student-autoqa[bot]'
            && $request['author']['email'] === '987+student-autoqa[bot]@users.noreply.github.com');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/git/refs')
            && $request['ref'] === 'refs/heads/agent/fix-registration' && $request['sha'] === self::COMMIT);
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PATCH', 'PUT', 'DELETE'], true));
    }

    #[DataProvider('unsafeRepairs')]
    public function test_rejects_unsafe_repair_requests_before_github_access(string $branch, array $files): void
    {
        Http::fake();
        try {
            (new GitHubAppClient)->commitRepair(self::REPOSITORY, self::BASE, $branch, $files, 'Fix');
            $this->fail('Expected repair policy rejection.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public static function unsafeRepairs(): array
    {
        return [
            ['main', ['app/Foo.php' => 'text']],
            ['agent/fix-../main', ['app/Foo.php' => 'text']],
            ['agent/fix-demo', ['app/../../.env' => 'text']],
            ['agent/fix-demo', ['.github/workflows/build.yml' => 'text']],
            ['agent/fix-demo', ['config/app.php' => 'text']],
            ['agent/fix-demo', ['app/secrets/key.txt' => 'text']],
            ['agent/fix-demo', ['resources/key.pem' => 'text']],
            ['agent/fix-demo', ['app/Foo.php' => str_repeat('x', 65537)]],
            ['agent/fix-demo', ['app/Foo.php' => "a\0b"]],
            ['agent/fix-demo', array_fill_keys(['app/a', 'app/b', 'app/c', 'app/d', 'app/e', 'app/f'], 'text')],
        ];
    }

    public function test_existing_repair_branch_is_never_overwritten(): void
    {
        $this->fakeApi(['GET /repos/student/demo/git/ref/heads/agent/fix-demo' => ['object' => ['sha' => self::COMMIT]]]);
        try {
            (new GitHubAppClient)->commitRepair(self::REPOSITORY, self::BASE, 'agent/fix-demo', ['app/Foo.php' => 'fixed'], 'Fix');
            $this->fail('Expected the existing branch to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
            Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/git/'));
        }
    }

    public function test_repair_cannot_traverse_a_repository_symlink(): void
    {
        $this->fakeApi(['GET /repos/student/demo/git/trees/'.self::TREE => ['tree' => [['path' => 'app', 'type' => 'blob', 'mode' => '120000', 'sha' => self::BLOB]]]]);
        try {
            (new GitHubAppClient)->commitRepair(self::REPOSITORY, self::BASE, 'agent/fix-demo', ['app/Foo.php' => 'fixed'], 'Fix');
            $this->fail('Expected symbolic-link traversal to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('symbolic links', $exception->getMessage());
            Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/git/blobs'));
        }
    }

    public function test_source_reads_cannot_follow_symlinks_into_secret_files(): void
    {
        $this->fakeApi([
            'GET /repos/student/demo/git/trees/'.self::BASE => ['tree' => [['path' => 'app', 'type' => 'tree', 'mode' => '040000', 'sha' => self::TREE]]],
            'GET /repos/student/demo/git/trees/'.self::TREE => ['tree' => [['path' => 'Alias.php', 'type' => 'blob', 'mode' => '120000', 'sha' => self::BLOB]]],
        ]);

        $result = (new GitHubAppClient)->readFiles(self::REPOSITORY, self::BASE, ['app/Alias.php']);

        $this->assertSame([], $result);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/contents/'));
    }

    public function test_draft_pr_is_created_once_and_reused_on_retry(): void
    {
        $lookups = 0;
        $pull = ['number' => 7, 'html_url' => 'https://github.com/student/demo/pull/7', 'draft' => true,
            'head' => ['ref' => 'agent/fix-demo'], 'base' => ['ref' => 'main']];
        $this->fakeApi([
            'GET /repos/student/demo/pulls' => function () use (&$lookups, $pull) {
                return $lookups++ === 0 ? [] : [$pull];
            },
            'POST /repos/student/demo/pulls' => $pull,
        ]);
        $client = new GitHubAppClient;

        $first = $client->createDraftPullRequest(self::REPOSITORY, 'agent/fix-demo', 'main', 'Fix registration', 'Verified evidence');
        $second = $client->createDraftPullRequest(self::REPOSITORY, 'agent/fix-demo', 'main', 'Fix registration', 'Verified evidence');

        $this->assertSame($first, $second);
        $this->assertSame(7, $first['number']);
        $creates = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
        $this->assertCount(1, $creates);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls') && $request['draft'] === true);
    }

    public function test_repository_tree_excludes_secrets_and_symlinks(): void
    {
        $this->fakeApi(['GET /repos/student/demo/git/trees/'.self::BASE => ['truncated' => false, 'tree' => [
            ['path' => 'app/Foo.php', 'type' => 'blob', 'mode' => '100644', 'size' => 20],
            ['path' => '.env', 'type' => 'blob', 'mode' => '100644', 'size' => 20],
            ['path' => 'app/key.pem', 'type' => 'blob', 'mode' => '100644', 'size' => 20],
            ['path' => 'app/link', 'type' => 'blob', 'mode' => '120000', 'size' => 20],
            ['path' => 'vendor/library/Foo.php', 'type' => 'blob', 'mode' => '100644', 'size' => 20],
        ]]]);

        $this->assertSame([['path' => 'app/Foo.php', 'size' => 20]], (new GitHubAppClient)->repositoryTree(self::REPOSITORY, self::BASE));
    }

    public function test_a_truncated_repository_tree_is_explicitly_rejected(): void
    {
        $this->fakeApi(['GET /repos/student/demo/git/trees/'.self::BASE => ['truncated' => true, 'tree' => []]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        (new GitHubAppClient)->repositoryTree(self::REPOSITORY, self::BASE);
    }

    public function test_snapshot_download_does_not_forward_authentication_to_codeload(): void
    {
        $directory = storage_path('app/qa/github-test-'.bin2hex(random_bytes(4)));
        mkdir($directory, 0700, true);
        $destination = $directory.'/source.tar.gz';
        $archive = gzencode('isolated archive fixture');
        $this->fakeApi(['GET /repos/student/demo/tarball/'.self::BASE => Http::response('', 302, ['Location' => 'https://codeload.github.com/student/demo/legacy.tar.gz/'.self::BASE.'?token=signed-download'])]);
        Http::fake(['https://codeload.github.com/*' => Http::response($archive, 200)]);
        try {
            (new GitHubAppClient)->archiveSnapshot(self::REPOSITORY, self::BASE, $destination);
            $this->assertSame($archive, file_get_contents($destination));
            Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://codeload.github.com/') && ! $request->hasHeader('Authorization'));
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
            rmdir($directory);
        }
    }

    public function test_snapshot_rejects_a_redirect_to_an_untrusted_host(): void
    {
        $directory = storage_path('app/qa/github-test-'.bin2hex(random_bytes(4)));
        mkdir($directory, 0700, true);
        $this->fakeApi(['GET /repos/student/demo/tarball/'.self::BASE => Http::response('', 302, ['Location' => 'https://evil.example/archive'])]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsupported snapshot');
            (new GitHubAppClient)->archiveSnapshot(self::REPOSITORY, self::BASE, $directory.'/source.tar.gz');
        } finally {
            rmdir($directory);
        }
    }

    private function fakeApi(array $overrides = []): void
    {
        $fixtures = [
            'GET /repos/student/demo/installation' => ['id' => 42, 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read', 'actions' => 'write']],
            'POST /app/installations/42/access_tokens' => ['token' => 'installation-token', 'expires_at' => gmdate('c', time() + 3600), 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']],
            'GET /repos/student/demo' => ['default_branch' => 'main', 'permissions' => ['push' => true]],
            'GET /repos/student/demo/git/ref/heads/main' => ['object' => ['sha' => self::BASE]],
            'GET /repos/student/demo/git/commits/'.self::BASE => ['tree' => ['sha' => self::TREE]],
            'GET /repos/student/demo/git/trees/'.self::BASE => ['tree' => []],
            'GET /repos/student/demo/git/trees/'.self::TREE => ['tree' => []],
            'POST /repos/student/demo/git/blobs' => ['sha' => self::BLOB],
            'POST /repos/student/demo/git/trees' => ['sha' => self::NEW_TREE],
            'POST /repos/student/demo/git/commits' => ['sha' => self::COMMIT],
            'POST /repos/student/demo/git/refs' => ['object' => ['sha' => self::COMMIT]],
            'GET /app' => ['slug' => 'student-autoqa'],
            'GET /users/student-autoqa[bot]' => ['login' => 'student-autoqa[bot]', 'id' => 987],
        ];
        $fixtures = array_replace($fixtures, $overrides);
        Http::fake(function (Request $request) use ($fixtures) {
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.github.com') {
                return null;
            }
            $key = $request->method().' '.rawurldecode(parse_url($request->url(), PHP_URL_PATH));
            if (! array_key_exists($key, $fixtures)) {
                return Http::response([], 404);
            }
            $fixture = $fixtures[$key];
            if (is_callable($fixture)) {
                $fixture = $fixture();
            }
            if (is_object($fixture)) {
                return $fixture;
            }

            return Http::response($fixture, $request->method() === 'POST' ? 201 : 200);
        });
    }
}
