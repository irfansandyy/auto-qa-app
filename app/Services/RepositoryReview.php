<?php

namespace App\Services;

use App\Models\Project;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use ParseError;
use PhpToken;
use RuntimeException;

class RepositoryReview
{
    private const FILE_LIMIT = 12;

    private const FINDING_LIMIT = 20;

    public function __construct(
        private GitHubAppClient $github,
        private RepositoryAccess $repositoryAccess,
        private DependencyAdvisories $advisories,
    ) {}

    /**
     * @return array{findings: list<array>, coverage: array{source_sha: ?string, source_matches_deployment: bool, source_status: string, files_reviewed: int, advisory_status: string, packages_reviewed: int, packages_skipped: int}}
     */
    public function review(Project $project, ?string $sourceSha = null, ?string $guidance = null): array
    {
        $coverage = [
            'source_sha' => null,
            'source_matches_deployment' => false,
            'source_status' => 'not_connected',
            'files_reviewed' => 0,
            'advisory_status' => 'not_checked',
            'packages_reviewed' => 0,
            'packages_skipped' => 0,
        ];
        $findings = [];
        $repository = $project->repository_url;
        if (! is_string($repository) || $repository === '') {
            return ['findings' => $findings, 'coverage' => $coverage];
        }

        try {
            $this->repositoryAccess->assertAllowed($project->user, $repository);
        } catch (AuthorizationException) {
            $coverage['source_status'] = 'unauthorized';

            return ['findings' => $findings, 'coverage' => $coverage];
        }

        try {
            $knownDeployment = is_string($sourceSha) && preg_match('/\A[a-fA-F0-9]{40}\z/D', $sourceSha) === 1;
            $sha = $knownDeployment ? $sourceSha : ($this->github->inspectRepository($repository)['head_sha'] ?? null);
            if (! is_string($sha) || preg_match('/\A[a-fA-F0-9]{40}\z/D', $sha) !== 1) {
                throw new RuntimeException('An exact source commit could not be determined.');
            }
        } catch (InvalidArgumentException|RuntimeException) {
            $coverage['source_status'] = 'unavailable';

            return ['findings' => $findings, 'coverage' => $coverage];
        }
        $coverage['source_sha'] = strtolower($sha);
        $coverage['source_matches_deployment'] = $knownDeployment;

        try {
            $paths = $this->prioritize(array_column($this->github->repositoryTree($repository, $sha), 'path'), $guidance);
            $selected = array_slice($paths, 0, self::FILE_LIMIT);
            $files = $selected === [] ? [] : $this->github->readFiles($repository, $sha, $selected);
            foreach ($selected as $path) {
                if (isset($files[$path])) {
                    $coverage['files_reviewed']++;
                    array_push($findings, ...$this->sourceFindings($path, $files[$path]));
                }
            }
            $coverage['source_status'] = match (true) {
                $paths === [] => 'empty',
                $coverage['files_reviewed'] === 0 => 'unavailable',
                $coverage['files_reviewed'] !== count($paths) || count($findings) > self::FINDING_LIMIT => 'partial',
                default => 'reviewed',
            };
        } catch (InvalidArgumentException|RuntimeException) {
            $coverage['source_status'] = 'unavailable';
        }

        try {
            $advisories = $this->advisories->review($this->github->readReviewLockfiles($repository, $sha));
            $coverage = array_replace($coverage, array_intersect_key($advisories, $coverage));
        } catch (InvalidArgumentException|RuntimeException) {
            $coverage['advisory_status'] = 'unavailable';
        }

        return [
            'findings' => [...array_slice($findings, 0, self::FINDING_LIMIT), ...($advisories['findings'] ?? [])],
            'coverage' => $coverage,
        ];
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function prioritize(array $paths, ?string $guidance): array
    {
        $paths = array_values(array_filter($paths, static fn (string $path): bool =>
            preg_match('~\A(?:app|config|routes)/[a-zA-Z0-9_./-]+\.php\z~D', $path) === 1));
        $words = array_unique(array_filter(preg_split('/[^a-zA-Z0-9]+/', strtolower(substr($guidance ?? '', 0, 1200))),
            static fn (string $word): bool => strlen($word) >= 4));
        $priority = [];
        foreach ($paths as $path) {
            $score = match (true) {
                in_array($path, ['config/app.php', 'config/services.php'], true) => 6,
                str_starts_with($path, 'routes/') => 5,
                str_starts_with($path, 'app/Http/Controllers/') => 4,
                str_starts_with($path, 'app/Http/Middleware/') => 3,
                default => 1,
            };
            $lowerPath = strtolower($path);
            foreach ($words as $word) {
                if (str_contains($lowerPath, $word)) {
                    $score += 10;
                }
            }
            $priority[$path] = $score;
        }

        usort($paths, static fn (string $left, string $right): int => ($priority[$right] <=> $priority[$left]) ?: strcmp($left, $right));

        return $paths;
    }

    /** @return list<array> */
    private function sourceFindings(string $path, string $source): array
    {
        try {
            $tokens = array_values(array_filter(PhpToken::tokenize($source, TOKEN_PARSE), static fn (PhpToken $token): bool =>
                ! $token->isIgnorable()));
        } catch (ParseError $error) {
            return [$this->finding($path, max(1, $error->getLine()), 'backend', 'php_syntax', 'PHP source failed to parse', 'high',
                'Application source parses without syntax errors.',
                'The PHP parser could not read this source file.',
                'Correct the PHP syntax at the reported line and rerun the project tests.')];
        }

        $findings = [];
        $recorded = [];

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            $value = $tokens[$index + 2] ?? null;
            $after = $tokens[$index + 3] ?? null;
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && $next?->is(T_DOUBLE_ARROW)
                && $value !== null && $after?->is([',', ']'])) {
                $finding = $this->configurationFinding($path, $token, $value);
                if ($finding !== null && ! isset($recorded[$finding['evidence']['rule']])) {
                    $recorded[$finding['evidence']['rule']] = true;
                    $findings[] = $finding;
                }
            }

            if (! isset($recorded['debug_output']) && $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next?->is('(')
                && in_array(strtolower(ltrim($token->text, '\\')), ['dd', 'var_dump', 'debug_print_backtrace'], true)
                && ! ($tokens[$index - 1] ?? null)?->is([T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $recorded['debug_output'] = true;
                $findings[] = $this->finding($path, $token->line, 'backend', 'debug_output', 'Debug output in application source', 'medium',
                    'Requests use structured error handling and logging.',
                    'A debug call may interrupt output or expose internal state when its code path runs.',
                    'Remove the debugging call or replace it with structured logging that omits sensitive data.');
            }

            if ($token->is(T_CATCH) && ! isset($recorded['empty_catch']) && $this->emptyCatch($tokens, $index)) {
                $recorded['empty_catch'] = true;
                $findings[] = $this->finding($path, $token->line, 'backend', 'empty_catch', 'Exception handler discards failures', 'medium',
                    'Exceptions have an explicit recovery path or are reported.',
                    'An empty catch block can hide a backend failure.',
                    'Add explicit recovery, rethrow the exception, or log a sanitized error when ignoring it is intentional.');
            }
        }

        return $findings;
    }

    /** @param list<PhpToken> $tokens */
    private function emptyCatch(array $tokens, int $index): bool
    {
        for ($position = $index + 1; isset($tokens[$position]) && $position <= $index + 20; $position++) {
            if ($tokens[$position]->is('{')) {
                return ($tokens[$position + 1] ?? null)?->is('}') === true;
            }
        }

        return false;
    }

    private function configurationFinding(string $path, PhpToken $key, PhpToken $value): ?array
    {
        $setting = strtolower(substr($key->text, 1, -1));
        if ($path === 'config/app.php' && $setting === 'debug' && $this->isBoolean($value, 'true')) {
            return $this->finding($path, $key->line, 'security', 'debug_default', 'Laravel debug mode is hard-coded on', 'high',
                'Debug mode defaults to disabled.',
                'The application configuration enables debug output regardless of its environment.',
                'Default debug mode to false and explicitly disable it in production.',
                "'debug' => env('APP_DEBUG', false),");
        }

        if ($path !== 'config/services.php' || ! in_array($setting, ['api_key', 'password', 'client_secret', 'secret', 'token', 'access_token'], true)
            || ! $value->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $literal = substr($value->text, 1, -1);
        if (strlen($literal) < 16 || preg_match('/\A[a-z0-9_\/+.-]+\z/iD', $literal) !== 1
            || preg_match('/[a-z]/i', $literal) !== 1 || preg_match('/[0-9]/', $literal) !== 1
            || preg_match('/(?:placeholder|example|dummy|changeme)/i', $literal) === 1) {
            return null;
        }

        return $this->finding($path, $key->line, 'security', 'literal_credential', 'Possible hard-coded credential', 'high',
            'Service credentials are loaded from protected environment configuration.',
            'A service credential setting uses a literal value.',
            'Replace the literal with an environment reference, then revoke and rotate the value if it was active.');
    }

    private function isBoolean(PhpToken $token, string $value): bool
    {
        return $token->is(T_STRING) && strtolower($token->text) === $value;
    }

    private function finding(string $path, int $line, string $category, string $rule, string $title, string $severity,
        string $expected, string $actual, string $remediation, ?string $change = null): array
    {
        return [
            'title' => $title,
            'severity' => $severity,
            'status' => 'needs_review',
            'expected' => $expected,
            'actual' => $actual,
            'evidence' => [
                'category' => $category, 'rule' => $rule, 'path' => $path, 'line' => $line,
                'remediation' => $remediation, ...($change !== null ? ['suggested_change' => $change] : []),
            ],
        ];
    }
}
