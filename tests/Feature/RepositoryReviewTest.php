<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\GitHub\GitHubAppClient;
use App\Services\RepositoryReview;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY = 'https://github.com/example/review';

    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('repository-access.grants', []);
        Http::preventStrayRequests();
    }

    public function test_unauthorized_repository_is_not_read_or_sent_to_an_advisory_service(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY]);
        $this->mock(GitHubAppClient::class)->shouldNotReceive('inspectRepository', 'repositoryTree', 'readFiles', 'readReviewLockfiles');

        $result = app(RepositoryReview::class)->review($project, self::SHA);

        $this->assertSame('unauthorized', $result['coverage']['source_status']);
        $this->assertSame('not_checked', $result['coverage']['advisory_status']);
        $this->assertSame([], $result['findings']);
        Http::assertNothingSent();
    }

    public function test_reports_static_backend_and_security_rules_without_executing_source_or_returning_literal_credentials(): void
    {
        $project = $this->approvedProject();
        $literal = 'inert-fixture-value-1205';
        $affectedFiles = [
            'config/app.php' => '<?php return ["debug" => true];',
            'config/services.php' => '<?php return ["example" => ["api_key" => "'.$literal.'"]];',
            'app/Services/Orders.php' => '<?php function validate() { ini_set("max_execution_time", 1); try {} catch (Throwable $failure) {} var_dump(true); }',
            'routes/invalid.php' => '<?php function invalid(',
        ];
        $available = [
            ...array_keys($affectedFiles),
            ...array_map(static fn (int $number): string => 'app/Component'.$number.'.php', range(1, 12)),
        ];
        $github = $this->mock(GitHubAppClient::class);
        $github->shouldNotReceive('inspectRepository', 'archiveSnapshot', 'commitRepair', 'createDraftPullRequest');
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::SHA)
            ->andReturn(array_map(static fn (string $path): array => ['path' => $path, 'size' => 150], $available));
        $github->shouldReceive('readFiles')->once()->withArgs(fn ($repository, $sha, $paths) => $repository === self::REPOSITORY && $sha === self::SHA && $paths[0] === 'app/Services/Orders.php'
            && in_array('config/app.php', $paths, true) && count($paths) === 12)->andReturn($affectedFiles);
        $github->shouldReceive('readReviewLockfiles')->once()->with(self::REPOSITORY, self::SHA)->andReturn([]);

        $result = app(RepositoryReview::class)->review($project, self::SHA, 'Check orders');

        $this->assertSame('partial', $result['coverage']['source_status']);
        $this->assertTrue($result['coverage']['source_matches_deployment']);
        $this->assertSame(4, $result['coverage']['files_reviewed']);
        $this->assertSame('no_supported_lockfiles', $result['coverage']['advisory_status']);
        $byRule = collect($result['findings'])->keyBy('evidence.rule');
        $this->assertSame('config/app.php', $byRule['debug_default']['evidence']['path']);
        $this->assertSame("'debug' => env('APP_DEBUG', false),", $byRule['debug_default']['evidence']['suggested_change']);
        $this->assertSame('high', $byRule['literal_credential']['severity']);
        $this->assertSame('backend', $byRule['empty_catch']['evidence']['category']);
        $this->assertSame('backend', $byRule['php_syntax']['evidence']['category']);
        $this->assertSame('backend', $byRule['debug_output']['evidence']['category']);
        $this->assertStringNotContainsString($literal, json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_reports_cves_with_published_fix_candidates_and_keeps_private_lock_metadata_local(): void
    {
        $project = $this->approvedProject();
        $packagist = 'https://packagist.org/api/security-advisories/?updatedSince=1';
        $osvQuery = 'https://api.osv.dev/v1/querybatch';
        $osvRecord = 'https://api.osv.dev/v1/vulns/GHSA-acde-1234-aaaa';
        $github = $this->mock(GitHubAppClient::class);
        $github->shouldReceive('inspectRepository')->once()->with(self::REPOSITORY)->andReturn(['head_sha' => self::SHA]);
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::SHA)->andReturn([]);
        $github->shouldReceive('readReviewLockfiles')->once()->with(self::REPOSITORY, self::SHA)->andReturn([
            'composer.lock' => json_encode(['packages' => [
                ['name' => 'vendor/test-library', 'version' => 'v1.2.0'],
                ['name' => 'private/team-package', 'version' => '1.0.0'],
            ]], JSON_THROW_ON_ERROR),
            'package-lock.json' => json_encode(['packages' => [
                '' => ['name' => 'private-application', 'version' => '1.0.0'],
                'node_modules/public-library' => [
                    'version' => '2.1.0',
                    'resolved' => 'https://registry.npmjs.org/public-library/-/public-library-2.1.0.tgz',
                ],
                'node_modules/private-js' => [
                    'version' => '2.1.0',
                    'resolved' => 'https://packages.internal.example/private-js/-/private-js-2.1.0.tgz',
                ],
                'node_modules/local-link' => ['version' => '2.1.0', 'resolved' => './internal', 'link' => true],
                'node_modules/alias-name' => [
                    'name' => 'different-name',
                    'version' => '2.1.0',
                    'resolved' => 'https://registry.npmjs.org/different-name/-/different-name-2.1.0.tgz',
                ],
            ]], JSON_THROW_ON_ERROR),
        ]);
        Http::fake([
            $packagist => Http::response(['advisories' => ['vendor/test-library' => [
                ['advisoryId' => 'PKSA-abcd-1234-5678', 'affectedVersions' => '<1.4.0', 'severity' => 'high', 'cve' => 'CVE-2026-11111'],
                ['advisoryId' => 'PKSA-defg-1234-5678', 'affectedVersions' => '<1.0.0', 'cve' => 'CVE-2026-33333'],
            ]]]),
            $osvQuery => Http::response(['results' => [['vulns' => [['id' => 'GHSA-acde-1234-aaaa']]]]]),
            $osvRecord => Http::response([
                'id' => 'GHSA-acde-1234-aaaa', 'aliases' => ['CVE-2026-22222'],
                'database_specific' => ['severity' => 'MODERATE'],
                'affected' => [[
                    'package' => ['ecosystem' => 'npm', 'name' => 'public-library'],
                    'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '2.0.0'], ['fixed' => '2.2.0']]]],
                ]],
            ]),
        ]);

        $result = app(RepositoryReview::class)->review($project);

        $this->assertFalse($result['coverage']['source_matches_deployment']);
        $this->assertSame('reviewed', $result['coverage']['advisory_status']);
        $this->assertSame(3, $result['coverage']['packages_reviewed']);
        $this->assertSame(3, $result['coverage']['packages_skipped']);
        $this->assertSame(['CVE-2026-11111 affects vendor/test-library', 'CVE-2026-22222 affects public-library'], array_column($result['findings'], 'title'));
        $this->assertSame(['1.4.0'], $result['findings'][0]['evidence']['fixed_versions']);
        $this->assertSame(['2.2.0'], $result['findings'][1]['evidence']['fixed_versions']);
        Http::assertSent(fn (Request $request): bool => $request->url() === $osvQuery && $request['queries'] === [
            ['version' => '2.1.0', 'package' => ['ecosystem' => 'npm', 'name' => 'public-library']],
        ]);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url().$request->body(), 'private-'));
    }

    public function test_reports_an_unavailable_advisory_lookup_without_a_clean_result(): void
    {
        $project = $this->approvedProject();
        $github = $this->mock(GitHubAppClient::class);
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::SHA)->andReturn([]);
        $github->shouldReceive('readReviewLockfiles')->once()->with(self::REPOSITORY, self::SHA)->andReturn([
            'composer.lock' => '{"packages": [{"name": "vendor/library", "version": "1.0.0"}]}',
        ]);
        Http::fake(['https://packagist.org/*' => Http::response([], 503)]);

        $result = app(RepositoryReview::class)->review($project, self::SHA);

        $this->assertSame('empty', $result['coverage']['source_status']);
        $this->assertSame('unavailable', $result['coverage']['advisory_status']);
        $this->assertSame(0, $result['coverage']['packages_reviewed']);
        $this->assertSame([], $result['findings']);
    }

    public function test_database_sector_reviews_migrations_and_query_call_sites_without_reading_lockfiles(): void
    {
        $project = $this->approvedProject();
        $migration = 'database/migrations/2026_11_27_000000_update_orders.php';
        $source = 'app/Services/Orders.php';
        $github = $this->mock(GitHubAppClient::class);
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::SHA, true)
            ->andReturn([['path' => $migration], ['path' => $source]]);
        $github->shouldReceive('readFiles')->once()->with(self::REPOSITORY, self::SHA, [$migration, $source])->andReturn([
            $migration => '<?php function up() { Schema::dropIfExists("orders_archive"); }',
            $source => '<?php function collectOrders(array $ids) { foreach ($ids as $id) { DB::table("orders")->where("id", $id)->first(); } }',
        ]);
        $github->shouldNotReceive('readReviewLockfiles', 'archiveSnapshot');

        $result = app(RepositoryReview::class)->review($project, self::SHA, sectors: ['database']);

        $this->assertSame('reviewed', $result['coverage']['database_status']);
        $this->assertSame('reviewed', $result['coverage']['query_status']);
        $this->assertSame(1, $result['coverage']['query_files_reviewed']);
        $this->assertSame(['destructive_forward_migration', 'query_inside_loop'], array_column(array_column($result['findings'], 'evidence'), 'rule'));
        Http::assertNothingSent();
    }

    private function approvedProject(): Project
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY]);
        config()->set('repository-access.grants', [$project->user_id => ['example/review']]);

        return $project;
    }
}
