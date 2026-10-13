<?php

namespace App\Services;

use App\Models\QaRun;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RepairSandbox
{
    private const GATES = ['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'original_replay', 'control_replay'];

    public function __construct(private GitHubAppClient $github, private BrowserWorker $browser) {}

    public function ready(): bool
    {
        return preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._/:@-]{0,250}$~D', (string) config('autoqa.runner_image', '')) === 1;
    }

    /** @return array<string, mixed> */
    public function verify(QaRun $run, array $files, string $test, array $actions): array
    {
        $result = ['passed' => false, ...array_fill_keys(self::GATES, false),
            'source_sha' => $run->base_sha, 'patch_sha256' => hash('sha256', json_encode($files)), 'checks' => []];
        $directory = null;
        $containers = [];
        $lock = null;

        try {
            if (! $this->ready()) {
                throw new RuntimeException('Configure AUTOQA_RUNNER_IMAGE with a trusted prebuilt target-dependencies image.');
            }
            if (! preg_match('/^[a-f0-9]{40}$/iD', (string) $run->base_sha)) {
                throw new InvalidArgumentException('Verification requires the exact deployed source commit.');
            }
            $this->validatePatch($files, $test);
            $this->validatePlan($actions, 45, false);
            $control = $run->report['control_actions'] ?? [];
            $this->validatePlan($control, 25);
            $preview = $this->previewOrigin();
            $source = $run->project->website_url;
            foreach ([...$actions, ...$control] as $action) {
                if (isset($action['url'])) {
                    $this->previewUrl($action['url'], $source, $preview);
                }
            }

            $lock = Cache::lock('autoqa-repair-sandbox', 3600);
            if (! $lock->get()) {
                throw new RuntimeException('Another repair verification is using the single-server sandbox. Retry after it finishes.');
            }
            $image = Process::timeout(10)->run(['docker', 'image', 'inspect', '--format', '{{.Id}}', (string) config('autoqa.runner_image')]);
            $imageId = trim($image->output());
            if (! $image->successful() || ! preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId)) {
                throw new RuntimeException('Docker or the configured local runner image is unavailable. Prepare the image before retrying.');
            }

            $directory = storage_path('app/qa/run-'.(int) $run->id.'-'.Str::uuid());
            if (str_contains($directory, ',') || ! mkdir($directory, 0700, true)) {
                throw new RuntimeException('The private verification workspace could not be created.');
            }
            $archive = $directory.'/source.tar.gz';
            $payload = $directory.'/payload.json';
            $this->github->archiveSnapshot($run->project->repository_url, $run->base_sha, $archive);
            file_put_contents($payload, json_encode([
                'source_sha' => $run->base_sha, 'patch_sha256' => $result['patch_sha256'],
                'files' => $files, 'test' => $test, 'preview_origin' => $preview,
                'test_timeout' => min(180, max(15, (int) config('autoqa.runner_timeout', 240) - 20)),
            ], JSON_THROW_ON_ERROR));
            chmod($archive, 0644);
            chmod($payload, 0644);

            foreach (['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite'] as $gate) {
                $this->assertActive($run);
                $name = 'autoqa-'.Str::uuid();
                $containers[] = $name;
                $command = $this->containerCommand($name, $imageId, $archive, $payload, $gate);
                $process = Process::timeout(min(300, max(30, (int) config('autoqa.runner_timeout', 240))))->run($command);
                $check = $this->parseCheck($process->output(), $gate, $result);
                $result['checks'][$gate] = $check;
                $expected = $gate === 'baseline_regression_assertion' ? 'assertion_failed' : 'passed';
                $counts = is_int($check['tests'] ?? null) && $check['tests'] > 0
                    && ($check['errors'] ?? null) === 0
                    && (($check['skipped'] ?? 0) < $check['tests'])
                    && ($gate === 'baseline_regression_assertion' ? ($check['failures'] ?? 0) > 0 : ($check['failures'] ?? null) === 0);
                $result[$gate] = $process->successful() && ($check['status'] ?? null) === $expected && $counts;
                if (! $result[$gate]) {
                    throw new RuntimeException('Verification stopped at '.$gate.'. '.($check['message'] ?? 'The isolated test did not establish the required result.'));
                }
                $this->removeContainer($name);
                $containers = array_values(array_diff($containers, [$name]));
            }

            foreach (['original_replay' => $actions, 'control_replay' => $control] as $gate => $plan) {
                $this->assertActive($run);
                $name = 'autoqa-'.Str::uuid();
                $containers[] = $name;
                $command = $this->containerCommand($name, $imageId, $archive, $payload, 'preview');
                array_splice($command, 2, 0, ['--detach', '--log-driver', 'none']);
                $started = Process::timeout(15)->run($command);
                if (! $started->successful()) {
                    throw new RuntimeException('The isolated patched preview could not be started.');
                }
                $proxy = Process::env([
                    'AUTOQA_PREVIEW_CONTAINER' => $name,
                    'AUTOQA_PREVIEW_HOST' => '127.0.0.1:'.parse_url($preview, PHP_URL_PORT),
                ])->forever()->start([PHP_BINARY, '-S', '127.0.0.1:'.parse_url($preview, PHP_URL_PORT), base_path('sandbox/preview-proxy.php')]);
                try {
                    $this->waitForPreview($preview, $result);
                    $result['checks'][$gate] = $this->replay($plan, $source, $preview);
                    $result[$gate] = $result['checks'][$gate]['passed'] === true;
                    if (! $result[$gate]) {
                        throw new RuntimeException('The patched preview did not satisfy '.$gate.'.');
                    }
                } finally {
                    $proxy->stop(1);
                    $this->removeContainer($name);
                    $containers = array_values(array_diff($containers, [$name]));
                }
            }

            $result['passed'] = collect(self::GATES)->every(fn ($gate) => $result[$gate] === true);
            $result['message'] = 'All six verification gates passed against the recorded source and patch.';
        } catch (Throwable $exception) {
            $result['message'] = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? mb_substr($exception->getMessage(), 0, 500)
                : 'The isolated verification could not finish. Check runner configuration and resource limits.';
        } finally {
            foreach ($containers as $name) {
                $this->removeContainer($name);
            }
            if ($directory !== null) {
                foreach (['source.tar.gz', 'payload.json'] as $file) {
                    if (is_file($directory.'/'.$file)) {
                        unlink($directory.'/'.$file);
                    }
                }
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            $lock?->release();
        }

        return $result;
    }

    private function containerCommand(string $name, string $image, string $archive, string $payload, string $phase): array
    {
        return ['docker', 'run', '--rm', '--pull', 'never', '--name', $name, '--read-only', '--network', 'none',
            '--memory', '2g', '--memory-swap', '2g', '--cpus', '1', '--pids-limit', '128', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--user', '1000:1000',
            '--tmpfs', '/workspace:rw,noexec,nosuid,nodev,size=512m,uid=1000,gid=1000',
            '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=64m,uid=1000,gid=1000',
            '--mount', 'type=bind,source='.$archive.',target=/input/source.tar.gz,readonly',
            '--mount', 'type=bind,source='.$payload.',target=/input/payload.json,readonly',
            '--entrypoint', 'php', $image, '/opt/autoqa/runner.php', $phase];
    }

    private function parseCheck(string $output, string $phase, array $expected): array
    {
        if (strlen($output) > 65536 || ! preg_match_all('/^AUTOQA_RESULT:(.+)$/m', $output, $matches) || ! $matches[1]) {
            throw new RuntimeException('The runner returned no bounded verification report.');
        }
        $check = json_decode(end($matches[1]), true);
        if (! is_array($check) || ($check['phase'] ?? null) !== $phase
            || ($check['source_sha'] ?? null) !== $expected['source_sha']
            || ($check['patch_sha256'] ?? null) !== $expected['patch_sha256']) {
            throw new RuntimeException('The runner report did not match the current source, patch, and verification stage.');
        }

        return array_intersect_key($check, array_flip(['phase', 'status', 'tests', 'failures', 'errors', 'skipped', 'message', 'source_sha', 'patch_sha256']));
    }

    private function waitForPreview(string $origin, array $expected): void
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                $response = Http::withoutRedirecting()->connectTimeout(1)->timeout(2)->get($origin.'/__autoqa_health');
                if ($response->successful() && $response->json('source_sha') === $expected['source_sha']
                    && $response->json('patch_sha256') === $expected['patch_sha256']) {
                    return;
                }
            } catch (Throwable) {
            }
            Sleep::for(250)->milliseconds();
        }

        throw new RuntimeException('The patched preview did not become ready with the expected source and patch identity.');
    }

    private function replay(array $actions, string $source, string $preview): array
    {
        $session = null;
        $assertions = 0;
        $password = Str::random(24).'A1!';
        $deadline = microtime(true) + min(180, max(30, (int) config('autoqa.runner_timeout', 240)));
        try {
            $started = $this->browser->start($this->previewUrl($source, $source, $preview), 'local');
            $session = $started['session_id'];
            $snapshot = $started['snapshot'];
            $this->assertNoServerErrors($started);
            foreach ($actions as $recorded) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('The deterministic browser replay exceeded its time budget.');
                }
                $action = array_intersect_key($recorded, array_flip(['tool', 'value', 'key', 'url', 'text']));
                if (isset($recorded['target']) && in_array($action['tool'], ['click', 'fill', 'select'], true)) {
                    $matches = array_values(array_filter($snapshot['elements'] ?? [], function ($element) use ($recorded) {
                        foreach (array_intersect_key($recorded['target'], array_flip(['tag', 'role', 'label', 'text', 'type'])) as $key => $value) {
                            if (($element[$key] ?? '') !== $value) {
                                return false;
                            }
                        }

                        return ! ($element['disabled'] ?? false);
                    }));
                    if (count($matches) !== 1) {
                        throw new RuntimeException('Browser replay requires exactly one matching enabled control from the fresh snapshot.');
                    }
                    $action['id'] = $matches[0]['id'];
                }
                if (isset($action['url'])) {
                    $action['url'] = $this->previewUrl($action['url'], $source, $preview);
                }
                if (($action['value'] ?? null) === '[QA password hidden]') {
                    $action['value'] = $password;
                }
                $observation = $this->browser->act($session, $action);
                $snapshot = $observation['snapshot'];
                $this->assertNoServerErrors($observation);
                if (in_array($action['tool'], ['assert_text', 'assert_url'], true)) {
                    if (($observation['assertion']['passed'] ?? null) !== true) {
                        throw new RuntimeException('The patched browser scenario failed its deterministic assertion.');
                    }
                    $assertions++;
                }
            }

            return ['passed' => $assertions > 0, 'assertions' => $assertions, 'steps' => count($actions)];
        } finally {
            if ($session !== null) {
                try {
                    $this->browser->close($session);
                } catch (Throwable) {
                }
            }
        }
    }

    private function validatePlan(mixed $actions, int $limit, bool $requireInteraction = true): void
    {
        if (! is_array($actions) || $actions === [] || count($actions) > $limit) {
            throw new InvalidArgumentException('Both replay and independent control need a bounded deterministic browser scenario.');
        }
        $interaction = false;
        foreach ($actions as $action) {
            if (! is_array($action) || ! in_array($action['tool'] ?? '', ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url'], true)) {
                throw new InvalidArgumentException('The browser verification plan contains an unsupported action.');
            }
            $tool = $action['tool'];
            if (in_array($tool, ['click', 'fill', 'select'], true)) {
                $target = $action['target'] ?? null;
                if (! is_array($target) || (empty($target['label']) && empty($target['text']))) {
                    throw new InvalidArgumentException('Replay controls require a stable observed label or text; saved element IDs cannot be reused.');
                }
                foreach (array_intersect_key($target, array_flip(['tag', 'role', 'label', 'text', 'type'])) as $value) {
                    if (! is_string($value) || strlen($value) > 2000) {
                        throw new InvalidArgumentException('A replay control selector is invalid.');
                    }
                }
            }
            $key = match ($tool) {
                'fill', 'select' => 'value', 'press' => 'key', 'navigate', 'assert_url' => 'url', 'assert_text' => 'text', default => null,
            };
            if ($key !== null && (! is_string($action[$key] ?? null) || strlen($action[$key]) > 2000
                || ($tool === 'assert_text' && trim($action[$key]) === ''))) {
                throw new InvalidArgumentException('A browser verification action has invalid arguments.');
            }
            if ($tool === 'press' && ! in_array($action['key'], ['Enter', 'Tab', 'Escape', 'Space', 'ArrowDown', 'ArrowUp'], true)) {
                throw new InvalidArgumentException('The browser verification plan uses an unsupported key.');
            }
            $interaction = $interaction || in_array($tool, ['click', 'fill', 'select', 'press'], true);
        }
        if (($requireInteraction && ! $interaction) || ! in_array(end($actions)['tool'], ['assert_text', 'assert_url'], true)) {
            throw new InvalidArgumentException('Each browser scenario must interact with the journey and end with an explicit success assertion.');
        }
    }

    private function validatePatch(array $files, string $test): void
    {
        if (! $files || count($files) > 5 || ! isset($files[$test]) || ! preg_match('~^tests/Feature/[A-Za-z0-9_/]*Test\.php$~D', $test)) {
            throw new InvalidArgumentException('The isolated repair needs one new tests/Feature/*Test.php regression file.');
        }
        $total = 0;
        $tests = 0;
        foreach ($files as $path => $content) {
            if (! is_string($path) || ! preg_match('~^(app|resources/views|routes|tests/Feature)/[A-Za-z0-9_./-]+\.php$~D', $path)
                || str_contains($path, '..') || str_contains($path, '//') || ! is_string($content)
                || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                throw new InvalidArgumentException('The isolated patch is outside the permitted text-file repair scope.');
            }
            $total += strlen($content);
            $tests += str_starts_with($path, 'tests/') ? 1 : 0;
        }
        if ($tests !== 1 || $total > 65536) {
            throw new InvalidArgumentException('A repair needs exactly one new regression file and at most 64 KB of combined content.');
        }
    }

    private function previewOrigin(): string
    {
        $origin = rtrim((string) config('autoqa.preview_origin', 'http://127.0.0.1:8081'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1'
            || ($parts['port'] ?? 0) < 1024 || ($parts['port'] ?? 0) > 65535
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            throw new InvalidArgumentException('AUTOQA_PREVIEW_ORIGIN must be a dedicated http://127.0.0.1:port origin with a port above 1023.');
        }

        return $origin;
    }

    private function previewUrl(string $url, string $source, string $preview): string
    {
        $parts = parse_url($url);
        $base = parse_url($source);
        if (! is_array($parts) || isset($parts['user']) || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('Browser replay URLs must stay within the configured source website.');
        }
        if (isset($parts['host'])) {
            $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
            $basePort = $base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80);
            if (($parts['scheme'] ?? '') !== ($base['scheme'] ?? '') || strtolower($parts['host']) !== strtolower($base['host'] ?? '') || $port !== $basePort) {
                throw new InvalidArgumentException('Browser replay cannot navigate or assert a different website origin.');
            }
        } elseif (! str_starts_with($url, '/') || isset($parts['scheme'])) {
            throw new InvalidArgumentException('Relative replay URLs must begin with a single slash.');
        }

        return $preview.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function assertNoServerErrors(array $result): void
    {
        foreach ($result['network_errors'] ?? [] as $error) {
            if (($error['status'] ?? 0) >= 500 || ($error['blocked'] ?? false) || isset($error['error'])) {
                throw new RuntimeException('The patched browser replay encountered a server failure or blocked network request.');
            }
        }
    }

    private function assertActive(QaRun $run): void
    {
        if ($run->exists && $run->fresh()?->status === 'cancelled') {
            throw new RuntimeException('The verification run was stopped.');
        }
    }

    private function removeContainer(string $name): void
    {
        try {
            Process::timeout(10)->run(['docker', 'rm', '--force', $name]);
        } catch (Throwable) {
        }
    }
}
