<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EvidenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $fixtureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureStorage = sys_get_temp_dir().'/autoqa-evidence-'.Str::uuid();
        $this->app->useStoragePath($this->fixtureStorage);
        File::ensureDirectoryExists(storage_path('app/private/evidence'));
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureStorage);
        parent::tearDown();
    }

    public function test_unauthenticated_evidence_request_redirects_to_sign_in(): void
    {
        $run = QaRun::factory()->create();

        $response = $this->get(route('runs.evidence', [$run, 'browser-test.png']));

        $response->assertRedirect(route('login'));
    }

    public function test_another_operator_cannot_read_recorded_evidence(): void
    {
        $run = QaRun::factory()->create(['report' => ['artifacts' => [['filename' => 'browser-test.png']]]]);
        $this->fixture('browser-test.png');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('runs.evidence', [$run, 'browser-test.png']));

        $response->assertNotFound();
    }

    public function test_file_recorded_only_on_another_run_cannot_be_read_by_current_run_owner(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))->create();
        QaRun::factory()->for($run->project)->create([
            'report' => ['artifacts' => [['filename' => 'browser-unrecorded.png']]],
        ]);
        $this->fixture('browser-unrecorded.png');

        $response = $this->actingAs($operator)
            ->get(route('runs.evidence', [$run, 'browser-unrecorded.png']));

        $response->assertNotFound();
    }

    #[TestWith(['../browser-outside.png'])]
    #[TestWith(['browser-test.svg'])]
    #[TestWith(['browser-test.png/extra'])]
    #[TestWith(['browser-test..png'])]
    public function test_unsafe_evidence_name_is_rejected(string $filename): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))
            ->create(['report' => ['artifacts' => [['filename' => $filename]]]]);

        $response = $this->actingAs($operator)
            ->get('/runs/'.$run->id.'/evidence/'.rawurlencode($filename));

        $response->assertNotFound();
    }

    public function test_missing_recorded_file_returns_404(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))
            ->create(['report' => ['artifacts' => [['filename' => 'browser-missing.png']]]]);

        $response = $this->actingAs($operator)
            ->get(route('runs.evidence', [$run, 'browser-missing.png']));

        $response->assertNotFound();
    }

    public function test_symbolic_link_cannot_expose_a_file_outside_evidence_directory(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))
            ->create(['report' => ['artifacts' => [['filename' => 'browser-linked.png']]]]);
        file_put_contents($this->fixtureStorage.'/outside.png', 'private outside evidence');
        symlink($this->fixtureStorage.'/outside.png', storage_path('app/private/evidence/browser-linked.png'));

        $response = $this->actingAs($operator)
            ->get(route('runs.evidence', [$run, 'browser-linked.png']));

        $response->assertNotFound();
    }

    public function test_owner_can_view_screenshot_recorded_on_finding_as_private_inline_png(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))->create();
        $run->findings()->create([
            'title' => 'Registration failed', 'expected' => 'Registration succeeds.',
            'actual' => 'Registration returned an error.', 'evidence' => ['artifact' => 'browser-finding.png'],
        ]);
        $contents = $this->fixture('browser-finding.png');

        $response = $this->actingAs($operator)
            ->get(route('runs.evidence', [$run, 'browser-finding.png']));

        $response->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=browser-finding.png');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($contents, file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_owner_can_download_trace_recorded_in_run_report(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))
            ->create(['report' => ['artifacts' => [['filename' => 'browser-trace.zip']]]]);
        $contents = $this->fixture('browser-trace.zip');

        $response = $this->actingAs($operator)
            ->get(route('runs.evidence', [$run, 'browser-trace.zip']));

        $response->assertOk()->assertDownload('browser-trace.zip')->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($contents, file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_dashboard_links_safe_evidence_and_omits_unsafe_report_names(): void
    {
        $operator = User::factory()->create();
        $run = QaRun::factory()->for(Project::factory()->for($operator))->create([
            'report' => ['artifacts' => [
                ['filename' => 'browser-screen.png'], ['filename' => 'browser-trace.zip'],
                ['filename' => '../private.png'],
            ]],
        ]);
        $run->findings()->create([
            'title' => 'Registration failed', 'expected' => 'Registration succeeds.',
            'actual' => 'Registration returned an error.', 'evidence' => ['artifact' => 'browser-finding.png'],
        ]);

        $response = $this->actingAs($operator)->get(route('dashboard'));

        $response->assertOk()->assertSee('View screenshot')->assertSee('Download trace')
            ->assertSee(route('runs.evidence', [$run, 'browser-screen.png']))
            ->assertSee(route('runs.evidence', [$run, 'browser-trace.zip']))
            ->assertSee(route('runs.evidence', [$run, 'browser-finding.png']))
            ->assertDontSee('/evidence/../private.png');
    }

    private function fixture(string $filename): string
    {
        $contents = str_ends_with($filename, '.png')
            ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=', true)
            : "PK\x05\x06".str_repeat("\x00", 18);
        file_put_contents(storage_path('app/private/evidence/'.$filename), $contents);

        return $contents;
    }
}
