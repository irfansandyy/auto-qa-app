<?php

namespace App\Services;

use App\Models\QaRun;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class QaAgent
{
    private ?string $qaPassword = null;

    public function __construct(
        private BrowserWorker $browser,
        private ModelClient $model,
        private GitHubAppClient $github,
        private TargetPolicy $targets,
        private PatchPolicy $patches,
        private RepairSandbox $sandbox,
    ) {}

    public function run(int $id): void
    {
        $run = QaRun::with('project')->findOrFail($id);
        if ($run->status !== 'queued') {
            return;
        }
        $session = null;
        try {
            $identity = $this->makeQaIdentity($run);
            $project = $run->project;
            $this->targets->assertAllowed($project->website_url, $project->target_mode);
            $this->updateActive($run, ['status' => 'testing', 'started_at' => now(), 'error' => null]);
            $this->model = $this->model->forUser($project->user);
            $this->step($run, 'start', 'Starting an isolated browser session.');
            $state = $this->browser->start($project->website_url, $project->target_mode);
            $session = $state['session_id'];
            $this->assertActive($run);
            $snapshot = $state['snapshot'];
            $formSnapshot = $snapshot;
            $sourceSha = $snapshot['source_sha'] ?? null;
            $actions = [];
            $failure = $this->observeFailure($run, $session, $state);
            $passedAssertions = 0;
            $budget = min(40, max(1, config('autoqa.max_steps', 25)));
            $staleCorrectionUsed = false;
            $instruction = 'You are a web QA agent. Return exactly one JSON object with a required tool key. Allowed schemas: {"tool":"click","id":"current-element-id"}, {"tool":"fill","id":"current-element-id","value":string}, {"tool":"select","id":"current-element-id","value":"option-value"}, {"tool":"press","key":"Enter"}, {"tool":"navigate","url":"full same-origin URL"}, {"tool":"assert_text","text":"expected visible text"}, {"tool":"assert_url","url":"full expected URL"}, {"tool":"finish","outcome":"passed|blocked","summary":"observed coverage"}. Element IDs refresh after every browser action. Use IDs only from the current page snapshot; previous actions identify controls by label and text, never reusable IDs. The qa_identity object contains synthetic disposable test data, never operator credentials. Use its name for name fields, email for email fields, and the same password for password and confirmation fields; use field-appropriate data for other requested inputs. Never enter schema placeholder text as a field value. Leave optional inputs empty unless the goal explicitly requests a value. Leave deliberately blank or omitted controls empty and preserve requested invalid-input cases. Test the user goal and expected behavior. Use the required and has_value booleans in the current snapshot to identify fields that still need input. For a valid-input journey, complete applicable required controls before submitting; filling a confirmation control does not fill its matching control. After submitting, inspect the observed page text and field state for validation feedback before asserting success. Resolve unintended validation feedback within the remaining step budget, and assert successful behavior only once it is observed. Finish passed only after a successful browser assertion checks the expected behavior. Never delete records, send messages, buy, pay, or leave the target origin. A HTTP 422 during invalid-input testing can be expected. Stop when the requested behavior is checked or cannot be checked. Do not claim any behavior you did not observe.';
            for ($i = 0; $i < $budget; $i++) {
                if ($failure) {
                    break;
                }
                $this->assertActive($run);
                $decision = $this->model->decide(
                    $instruction,
                    ['goal' => $run->goal, 'expected_behavior' => $run->expected_behavior, 'qa_identity' => $identity,
                        'page' => $snapshot, 'previous_actions' => $this->modelHistory(array_slice($actions, -8)), 'steps_remaining' => $budget - $i],
                );
                $this->assertActive($run);
                if (! $staleCorrectionUsed && $this->isStaleElementDecision($decision, $snapshot)) {
                    if ($i + 1 >= $budget) {
                        throw new RuntimeException('The action limit was reached before the expired element reference could be corrected.');
                    }
                    $staleCorrectionUsed = true;
                    $i++;
                    $this->assertActive($run);
                    $decision = $this->model->decide($instruction.' Correct the rejected reference using the current page snapshot. No browser action was executed for the rejected decision.',
                        ['goal' => $run->goal, 'expected_behavior' => $run->expected_behavior, 'qa_identity' => $identity,
                            'page' => $snapshot, 'previous_actions' => $this->modelHistory(array_slice($actions, -8)),
                            'validation_error' => 'The previous decision referenced an element outside the current page snapshot. Choose an ID from the current snapshot only.',
                            'steps_remaining' => $budget - $i]);
                    $this->assertActive($run);
                }
                $action = $this->validateAction($decision, $snapshot);
                if ($action['tool'] === 'finish') {
                    $completed = $action['outcome'] === 'passed' && $passedAssertions > 0;
                    $this->finish($run, $completed ? 'completed' : 'blocked',
                        ['summary' => $completed || $action['outcome'] === 'blocked' ? $action['summary'] : 'The agent did not produce a passing browser assertion. The requested behavior is unverified.', 'actions' => $this->redactActions($actions), 'passed_assertions' => $passedAssertions, 'coverage' => 'Only recorded actions and assertions were evaluated.']);
                    break;
                }
                $before = $snapshot;
                if (collect($before['elements'] ?? [])->contains(fn ($element) => in_array($element['tag'] ?? '', ['input', 'select', 'textarea'], true))) {
                    $formSnapshot = $before;
                }
                $result = $this->browser->act($session, $action);
                $this->assertActive($run);
                $snapshot = $result['snapshot'];
                $observedSha = $snapshot['source_sha'] ?? null;
                if ($sourceSha && $observedSha && $sourceSha !== $observedSha) {
                    throw new RuntimeException('The target source commit changed during the journey. Start a new run on a stable deployment.');
                }
                $sourceSha ??= $observedSha;
                $replay = $action;
                if (isset($action['id'])) {
                    $replay['target'] = collect($before['elements'] ?? [])->firstWhere('id', $action['id']);
                }
                $actions[] = $replay;
                $safeAction = $this->redactActions([$replay])[0];
                $this->step($run, 'action', $this->actionSummary($action, $replay['target'] ?? null),
                    ['action' => $safeAction, 'url' => $snapshot['url'] ?? '', 'assertion' => $result['assertion'] ?? null]);
                if (($result['assertion']['passed'] ?? null) === true) {
                    $passedAssertions++;
                }
                $failure = $this->observeFailure($run, $session, $result);
            }
            $closed = $this->browser->close($session);
            $artifacts = $closed['artifacts'] ?? [];
            $session = null;
            $this->assertActive($run);
            if (! $failure) {
                if ($run->fresh()->status === 'testing') {
                    $this->finish($run, 'blocked', ['summary' => 'The action limit was reached. The remaining goal was not tested.', 'actions' => $this->redactActions($actions)]);
                }
                $run->refresh();
                $this->updateActive($run, ['report' => array_merge($run->report ?? [], ['artifacts' => $artifacts])]);

                return;
            }
            $this->updateActive($run, ['report' => ['failure' => $failure, 'actions' => $this->redactActions($actions), 'artifacts' => $artifacts]]);
            if (blank($project->repository_url)) {
                $summary = $failure['network_errors']
                    ? 'The website returned HTTP '.$failure['network_errors'][0]['status'].' during the recorded journey.'
                    : 'Expected behavior was not observed in a recorded browser assertion.';
                $this->finish($run, 'report_ready', array_merge($run->report, [
                    'summary' => $summary.' The report includes the finding, recorded actions and browser evidence.',
                    'coverage' => 'Only recorded actions and assertions were evaluated.',
                ]));

                return;
            }
            $snapshot['source_sha'] = $sourceSha;
            $this->repair($run, $actions, $snapshot, $formSnapshot, $identity);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($run, $e) {
                $current = QaRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'cancelled') {
                    $current->update(['status' => 'failed', 'error' => $this->redactIdentityData($e->getMessage()), 'finished_at' => now()]);
                    $this->step($run, 'error', $e->getMessage());
                }
            });
        } finally {
            if ($session) {
                try {
                    $this->browser->close($session);
                } catch (\Throwable) {
                }
            }
            $this->qaPassword = null;
        }
    }

    private function repair(QaRun $run, array $actions, array $snapshot, array $formSnapshot, array $identity): void
    {
        $project = $run->project;
        $sha = $project->deployed_sha ?: ($snapshot['source_sha'] ?? null);
        if (! $sha) {
            $this->finish($run, 'needs_configuration', array_merge($run->report, [
                'summary' => 'A finding was saved. Supply the website deployed commit under Advanced setup, or expose X-AutoQA-Commit, to diagnose matching source safely.',
            ]));

            return;
        }
        if (! preg_match('/\A[0-9a-f]{40}\z/i', $sha) || ($project->deployed_sha && ! empty($snapshot['source_sha']) && strcasecmp($sha, $snapshot['source_sha']) !== 0)) {
            $this->finish($run, 'blocked', array_merge($run->report, ['summary' => 'The configured deployment commit does not match the website source header, or is not a full Git commit SHA. Correct the source binding before repairing.']));

            return;
        }
        try {
            app(RepositoryAccess::class)->assertAllowed($project->user, $project->repository_url);
        } catch (AuthorizationException $exception) {
            $this->finish($run, 'blocked', array_merge($run->report, ['summary' => $exception->getMessage()]));

            return;
        }
        $this->updateActive($run, ['status' => 'diagnosing', 'base_sha' => $sha]);
        $repo = $this->github->inspectRepository($project->repository_url);
        $tree = $this->github->repositoryTree($project->repository_url, $sha);
        $candidates = array_values(array_filter($tree, fn ($file) => ($file['size'] ?? PHP_INT_MAX) <= 6000));
        $selection = $this->model->decide('Choose up to four existing source files relevant to the failed journey from this repository tree. Return {files:[path,...]}. Do not invent file names.',
            ['goal' => $run->goal, 'failure' => $run->report['failure'], 'tree' => array_slice($candidates, 0, 150)]);
        if (! is_array($selection['files'] ?? null)) {
            throw new RuntimeException('The model did not select valid source files.');
        }
        $paths = array_slice(array_values(array_intersect(array_filter($selection['files'], 'is_string'), array_column($candidates, 'path'))), 0, 4);
        if (! $paths) {
            throw new RuntimeException('The agent could not identify relevant source files. The finding is preserved for review.');
        }
        $source = $this->github->readFiles($project->repository_url, $sha, $paths);
        if (! $source || array_filter($source, fn ($text) => strlen($text) > 6000)) {
            throw new RuntimeException('The selected source exceeds this CPU MVP repair context. The finding remains available for manual review.');
        }
        $context = $source;
        $patch = $this->model->decide('Diagnose the observed failure using the expected behavior and source. Return {diagnosis:string,patch_files:{path:complete_new_file_text},regression_test:string}. patch_files must include the complete contents of the changed application file or files AND the complete contents of one new tests/Feature/*Test.php regression test. regression_test is the relative path to that new test, never its source text. Change at most four small app/resources/views/routes files and add only that new test. Write a PHPUnit class extending Tests\\TestCase with test_ methods, following the target source and existing validation. Keep default Laravel exception handling so the baseline failure is an assertion about expected behavior, not an uncaught setup or runtime error. Use qa_identity as valid synthetic test data with matching password and confirmation when those fields are required; obey the existing validation rules and requested omissions. Keep optional inputs unspecified unless the goal requires them. Keep expected behavior and existing tests unchanged. If uncertain return {diagnosis:string,patch_files:{},regression_test:""}.',
            ['expected_behavior' => $run->expected_behavior, 'failure' => $run->report['failure'], 'source' => $context, 'qa_identity' => $identity], (int) config('autoqa.model_repair_timeout', 600));
        $files = $patch['patch_files'] ?? [];
        if (! is_array($files) || ! is_string($patch['diagnosis'] ?? null) || strlen($patch['diagnosis']) > 3000) {
            throw new RuntimeException('The agent could not produce a bounded repair proposal. The finding is saved for review.');
        }
        $original = array_fill_keys(array_column($tree, 'path'), 'exists');
        $test = $this->patches->validate($files, $original);
        foreach ($files as $path => $content) {
            if (! str_starts_with($path, 'tests/') && ! array_key_exists($path, $source)) {
                throw new RuntimeException('A proposed repair changed source that was not read in full. Select that file before repairing it.');
            }
        }
        $this->updateActive($run, ['patch_files' => $files, 'report' => array_merge($run->report, ['diagnosis' => $patch['diagnosis'] ?? 'Proposed repair'])]);
        $this->step($run, 'diagnosis', $patch['diagnosis'] ?? 'A proposed patch and regression test are ready.', ['files' => array_keys($files)]);
        if ($project->repair_policy === 'suggest_only') {
            $this->finish($run, 'suggestion_ready', array_merge($run->report, ['summary' => 'The proposed repair is ready to review. No repository changes were published.']));

            return;
        }
        $this->assertActive($run);
        if (! $this->sandbox->ready()) {
            $this->finish($run, 'needs_configuration', array_merge($run->report, ['summary' => 'The patch is saved. Configure an isolated Docker runner image before automated testing and publication.']));

            return;
        }
        $control = $this->model->decide('Plan a normal valid-input browser journey for the same feature using qa_identity as synthetic disposable test data, never operator credentials. Use its field-appropriate name, email, and matching password and confirmation. Leave optional fields unspecified unless the normal control requires a value. Never fill schema placeholder text. Return {actions:[{tool, target:{tag,role,label,text,type},value|key|text|url}...],original_assertion:{tool:assert_text,text}|{tool:assert_url,url}}. Use click/fill/select/press/navigate/assert_text/assert_url only. Locate elements by their exact accessible label or visible text from the observed page; omit IDs. Include at least one deterministic positive assertion for successful normal behavior. The original_assertion must check the expected successful behavior after repairing the original failed journey, and will be appended to its replay. Do not delete, send messages, buy, pay, or invent guaranteed outcomes. Return empty actions and omit original_assertion if the expected result is unknown.',
            ['goal' => $run->goal, 'expected_behavior' => $run->expected_behavior, 'page' => $formSnapshot, 'original_actions' => $this->modelHistory($actions), 'qa_identity' => $identity]);
        $controlActions = $this->validateControlPlan($control['actions'] ?? []);
        $this->assertActive($run);
        if (is_array($control['original_assertion'] ?? null) && in_array($control['original_assertion']['tool'] ?? '', ['assert_text', 'assert_url'], true)) {
            $actions[] = $this->validateAction($control['original_assertion'], []);
        }
        $this->updateActive($run, ['report' => array_merge($run->report, ['control_actions' => $this->redactActions($controlActions), 'replay_actions' => $this->redactActions($actions)])]);
        $this->updateActive($run, ['status' => 'repairing']);
        $verification = $this->sandbox->verify($run, $files, $test, $actions);
        $this->updateActive($run, ['verification' => $verification]);
        $gates = ['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'original_replay', 'control_replay'];
        $complete = ($verification['passed'] ?? false) === true
            && ($verification['source_sha'] ?? null) === $sha
            && ($verification['patch_sha256'] ?? null) === hash('sha256', json_encode($files))
            && collect($gates)->every(fn ($gate) => ($verification[$gate] ?? null) === true);
        if (! $complete) {
            $this->finish($run, 'verification_failed', array_merge($run->report, ['summary' => 'The proposed repair did not pass the required verification. No PR was published.']));

            return;
        }
        $this->assertActive($run);
        $baseBranch = $project->base_branch ?: $repo['default_branch'];
        try {
            app(RepositoryAccess::class)->assertAllowed($project->user->fresh(), $project->repository_url);
            $this->github->assertPublicationAllowed($project->repository_url, $baseBranch, $sha);
        } catch (RuntimeException|\InvalidArgumentException|AuthorizationException $exception) {
            $this->finish($run, 'blocked', array_merge($run->report, ['summary' => $exception->getMessage()]));

            return;
        }
        $this->assertActive($run);
        $branch = 'agent/fix-'.$run->id.'-'.substr(hash('sha256', json_encode($files)), 0, 8);
        $commit = $this->github->commitRepair($project->repository_url, $sha, $branch, $files, 'Repair QA finding #'.$run->id);
        DB::transaction(function () use ($run, $branch, $commit, $baseBranch): void {
            $current = QaRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $current->update(['report' => array_merge($current->report ?? [], ['branch' => $branch, 'commit_sha' => $commit['sha'], 'base_branch' => $baseBranch])]);
        });
        $run->refresh();
        $this->assertActive($run);
        $body = 'Observed problem: '.$run->findings()->first()->actual."\n\nExpected: ".$run->expected_behavior."\n\nDiagnosis: ".($run->report['diagnosis'] ?? '')."\n\nVerification: regression failed on base, passed with patch; existing tests, original browser replay and normal control journey passed.\n\nBase commit: ".$sha."\nRepair commit: ".$commit['sha']."\n\nCoverage is limited to the recorded tests and journeys. Human review is required.";
        $pr = $this->github->createDraftPullRequest($project->repository_url, $branch, $baseBranch, 'Repair QA finding #'.$run->id, $body);
        $this->updateActive($run, ['pr_url' => $pr['html_url']]);
        $this->finish($run, 'ready_for_review', array_merge($run->report, ['summary' => 'The verified repair is available as a draft pull request.', 'commit_sha' => $commit['sha'], 'branch' => $branch]));
    }

    public function validateAction(array $action, array $snapshot): array
    {
        $tool = $action['tool'] ?? '';
        $allowed = ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url', 'finish'];
        if (! in_array($tool, $allowed, true)) {
            throw new RuntimeException('The model requested an unsupported action.');
        }
        $keys = ['tool'];
        if (in_array($tool, ['click', 'fill', 'select'], true)) {
            if (! collect($snapshot['elements'] ?? [])->contains('id', $action['id'] ?? null)) {
                throw new RuntimeException('The model selected an element outside the current page snapshot.');
            }
            $keys[] = 'id';
        }
        $valueKey = match ($tool) {
            'fill', 'select' => 'value', 'press' => 'key', 'navigate', 'assert_url' => 'url', 'assert_text' => 'text', default => null
        };
        if ($valueKey) {
            if (! is_string($action[$valueKey] ?? null) || strlen($action[$valueKey]) > 2000) {
                throw new RuntimeException('Invalid browser action arguments.');
            }
            $keys[] = $valueKey;
        }
        if ($tool === 'assert_text' && (! trim($action['text']) || strlen($action['text']) > 1000)) {
            throw new RuntimeException('A browser assertion needs 1–1000 characters of expected text.');
        }
        if ($tool === 'press' && ! in_array($action['key'], ['Enter', 'Tab', 'Escape', 'Space', 'ArrowDown', 'ArrowUp'], true)) {
            throw new RuntimeException('The requested keyboard action is not permitted.');
        }
        if ($tool === 'finish') {
            if (! in_array($action['outcome'] ?? '', ['passed', 'blocked'], true) || ! is_string($action['summary'] ?? null) || strlen($action['summary']) > 3000) {
                throw new RuntimeException('Invalid completion result.');
            }
            $keys = array_merge($keys, ['outcome', 'summary']);
        }

        return array_intersect_key($action, array_flip($keys));
    }

    private function observeFailure(QaRun $run, string $session, array $result): ?array
    {
        $errors = array_values(array_filter($result['network_errors'] ?? [], fn ($error) => ($error['status'] ?? 0) >= 500));
        $assertionFailed = ($result['assertion']['passed'] ?? null) === false;
        if (! $errors && ! $assertionFailed) {
            return null;
        }
        $evidence = $this->browser->act($session, ['tool' => 'screenshot']);
        $failure = ['network_errors' => $errors, 'assertion' => $result['assertion'] ?? null,
            'url' => $result['snapshot']['url'] ?? $run->project->website_url, 'artifact' => $evidence['artifact'] ?? null];
        $run->findings()->create($this->redactIdentityData(['title' => $errors ? 'A server error interrupted the tested journey' : 'Expected behavior was not observed',
            'severity' => $errors ? 'high' : 'medium', 'status' => 'observed', 'expected' => $run->expected_behavior,
            'actual' => $errors ? 'The target returned HTTP '.($errors[0]['status'] ?? 500).'.' : 'A browser assertion failed.', 'evidence' => $failure]));

        return $failure;
    }

    private function redactActions(array $actions): array
    {
        return $this->redactIdentityData(array_map(function ($action) {
            $target = $action['target'] ?? [];
            if (($action['tool'] ?? '') === 'fill' && (($target['type'] ?? '') === 'password' || preg_match('/password/i', ($target['label'] ?? '').' '.($target['name'] ?? '')))) {
                $action['value'] = '[QA password hidden]';
            }

            return $action;
        }, $actions));
    }

    /** @return array{name: string, email: string, password: string, password_confirmation: string} */
    private function makeQaIdentity(QaRun $run): array
    {
        $this->qaPassword = Str::random(24).'aA1!';

        return ['name' => 'QA Tester', 'email' => 'qa-'.$run->id.'-'.Str::lower(Str::random(16)).'@example.test',
            'password' => $this->qaPassword, 'password_confirmation' => $this->qaPassword];
    }

    private function redactIdentityData(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->redactIdentityData($item), $value);
        }
        if (is_string($value) && $this->qaPassword !== null) {
            return str_replace($this->qaPassword, '[QA password hidden]', $value);
        }

        return $value;
    }

    private function modelHistory(array $actions): array
    {
        return array_map(function (array $action): array {
            unset($action['id']);
            if (is_array($action['target'] ?? null)) {
                unset($action['target']['id']);
            }

            return $action;
        }, $this->redactActions($actions));
    }

    private function isStaleElementDecision(array $decision, array $snapshot): bool
    {
        if (! in_array($decision['tool'] ?? '', ['click', 'fill', 'select'], true)
            || ! is_string($decision['id'] ?? null) || $decision['id'] === '' || strlen($decision['id']) > 2000
            || collect($snapshot['elements'] ?? [])->contains('id', $decision['id'])) {
            return false;
        }
        try {
            $this->validateAction($decision, ['elements' => [['id' => $decision['id']]]]);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function validateControlPlan(mixed $actions): array
    {
        if (! is_array($actions) || count($actions) > 25 || ! $actions) {
            return [];
        }
        $result = [];
        foreach ($actions as $action) {
            if (! is_array($action) || ! in_array($action['tool'] ?? '', ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url'], true)) {
                return [];
            }
            $target = $action['target'] ?? null;
            if (in_array($action['tool'], ['click', 'fill', 'select'], true)) {
                if (! is_array($target) || empty($target['label']) && empty($target['text'])) {
                    return [];
                }
                $target = array_intersect_key($target, array_flip(['tag', 'role', 'label', 'text', 'type']));
                foreach ($target as $value) {
                    if (! is_string($value) || strlen($value) > 2000) {
                        return [];
                    }
                }
                $action['id'] = 'control';
            }
            $validated = $this->validateAction($action, ['elements' => [['id' => 'control']]]);
            unset($validated['id']);
            if ($target) {
                $validated['target'] = $target;
            }
            $result[] = $validated;
        }

        return $result;
    }

    private function updateActive(QaRun $run, array $attributes): void
    {
        foreach (['report', 'verification', 'error'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->redactIdentityData($attributes[$key]);
            }
        }
        DB::transaction(function () use ($run, $attributes) {
            $current = QaRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'cancelled') {
                throw new RuntimeException('The run was stopped.');
            }
            $current->update($attributes);
        });
        $run->refresh();
    }

    private function step(QaRun $run, string $kind, string $summary, array $data = []): void
    {
        $run->steps()->create(['sequence' => $run->steps()->count() + 1, 'kind' => $kind, 'summary' => mb_substr($this->redactIdentityData($summary), 0, 3000), 'data' => $this->redactIdentityData($data)]);
    }

    private function finish(QaRun $run, string $status, array $report): void
    {
        $this->assertActive($run);
        $this->updateActive($run, ['status' => $status, 'report' => $report, 'finished_at' => now()]);
        $this->step($run, 'result', $report['summary'] ?? 'Run finished.');
    }

    private function assertActive(QaRun $run): void
    {
        if ($run->fresh()->status === 'cancelled') {
            throw new RuntimeException('The run was stopped.');
        }
    }

    private function actionSummary(array $action, ?array $element): string
    {
        $name = $element['label'] ?? $element['text'] ?? $element['tag'] ?? '';

        return ucfirst(str_replace('_', ' ', $action['tool'])).($name ? ' · '.mb_substr($name, 0, 100) : '');
    }
}
