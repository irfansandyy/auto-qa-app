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

    /** @return array{findings: list<array>, coverage: array} */
    public function review(Project $project, ?string $sourceSha = null, ?string $guidance = null, array $sectors = ['security', 'backend']): array
    {
        $backend = in_array('backend', $sectors, true);
        $security = in_array('security', $sectors, true);
        $database = in_array('database', $sectors, true);
        $coverage = [
            'source_sha' => null,
            'source_matches_deployment' => false,
            'source_status' => $backend || $security ? 'not_connected' : 'disabled',
            'configuration_status' => $security ? 'not_connected' : 'disabled',
            'files_reviewed' => 0,
            'advisory_status' => $security ? 'not_checked' : 'disabled',
            'packages_reviewed' => 0,
            'packages_skipped' => 0,
            'database_status' => $database ? 'not_connected' : 'disabled',
            'migrations_reviewed' => 0,
            'query_status' => $database ? 'not_connected' : 'disabled',
            'query_files_reviewed' => 0,
        ];
        $findings = [];
        $repository = $project->repository_url;
        if ((! $backend && ! $security && ! $database) || ! is_string($repository) || $repository === '') {
            return ['findings' => $findings, 'coverage' => $coverage];
        }

        try {
            $this->repositoryAccess->assertAllowed($project->user, $repository);
        } catch (AuthorizationException) {
            if ($backend || $security) {
                $coverage['source_status'] = 'unauthorized';
            }
            if ($security) {
                $coverage['configuration_status'] = 'unauthorized';
            }
            if ($database) {
                $coverage['database_status'] = 'unauthorized';
                $coverage['query_status'] = 'unauthorized';
            }

            return ['findings' => $findings, 'coverage' => $coverage];
        }

        try {
            $knownDeployment = is_string($sourceSha) && preg_match('/\A[a-fA-F0-9]{40}\z/D', $sourceSha) === 1;
            $sha = $knownDeployment ? $sourceSha : ($this->github->inspectRepository($repository)['head_sha'] ?? null);
            if (! is_string($sha) || preg_match('/\A[a-fA-F0-9]{40}\z/D', $sha) !== 1) {
                throw new RuntimeException('An exact source commit could not be determined.');
            }
        } catch (InvalidArgumentException|RuntimeException) {
            if ($backend || $security) {
                $coverage['source_status'] = 'unavailable';
            }
            if ($security) {
                $coverage['configuration_status'] = 'unavailable';
            }
            if ($database) {
                $coverage['database_status'] = 'unavailable';
                $coverage['query_status'] = 'unavailable';
            }

            return ['findings' => $findings, 'coverage' => $coverage];
        }
        $coverage['source_sha'] = strtolower($sha);
        $coverage['source_matches_deployment'] = $knownDeployment;

        try {
            $tree = $database ? $this->github->repositoryTree($repository, $sha, true) : $this->github->repositoryTree($repository, $sha);
            $candidates = array_column($tree, 'path');
            $securityPaths = $security ? array_values(array_intersect(['config/app.php', 'config/services.php', 'config/session.php'], $candidates)) : [];
            $ordered = $this->prioritize($candidates, $guidance);
            $queryPaths = $database ? array_values(array_filter($ordered, static fn (string $path): bool => str_starts_with($path, 'app/'))) : [];
            $paths = $backend ? $ordered : array_values(array_unique([...$securityPaths, ...$queryPaths]));
            $migrations = $database ? array_values(array_filter($candidates, static fn (string $path): bool => preg_match('~\Adatabase/migrations/[a-zA-Z0-9_.-]+\.php\z~D', $path) === 1)) : [];
            rsort($migrations, SORT_STRING);
            $selectedMigrations = array_slice($migrations, 0, $paths === [] ? self::FILE_LIMIT : 4);
            $selected = [...$selectedMigrations, ...array_slice($paths, 0, self::FILE_LIMIT - count($selectedMigrations))];
            $files = $selected === [] ? [] : $this->github->readFiles($repository, $sha, $selected);
            foreach ($selected as $path) {
                if (! isset($files[$path])) {
                    continue;
                }
                if (in_array($path, $selectedMigrations, true)) {
                    $coverage['migrations_reviewed']++;
                    array_push($findings, ...$this->sourceFindings($path, $files[$path], ['database']));
                } else {
                    $coverage['files_reviewed']++;
                    if ($database && str_starts_with($path, 'app/')) {
                        $coverage['query_files_reviewed']++;
                    }
                    array_push($findings, ...$this->sourceFindings($path, $files[$path], $sectors));
                }
            }
            if ($database) {
                $coverage['database_status'] = $this->staticCoverage($coverage['migrations_reviewed'], count($migrations), count($findings));
                $coverage['query_status'] = $this->staticCoverage($coverage['query_files_reviewed'], count($queryPaths), count($findings));
            }
            if ($backend || $security) {
                $coverage['source_status'] = $this->staticCoverage($coverage['files_reviewed'], count($paths), count($findings));
            }
            if ($security) {
                $coverage['configuration_status'] = $this->staticCoverage(count(array_intersect($securityPaths, array_keys($files))), count($securityPaths), count($findings));
            }
        } catch (InvalidArgumentException|RuntimeException) {
            if ($database) {
                $coverage['database_status'] = 'unavailable';
                $coverage['query_status'] = 'unavailable';
            }
            if ($backend || $security) {
                $coverage['source_status'] = 'unavailable';
            }
            if ($security) {
                $coverage['configuration_status'] = 'unavailable';
            }
        }

        if ($security) {
            try {
                $advisories = $this->advisories->review($this->github->readReviewLockfiles($repository, $sha));
                $coverage = array_replace($coverage, array_intersect_key($advisories, $coverage));
            } catch (InvalidArgumentException|RuntimeException) {
                $coverage['advisory_status'] = 'unavailable';
            }
        }

        $dependencyFindings = array_map(static fn (array $finding): array => [
            ...$finding, 'evidence' => [...$finding['evidence'], 'sector' => 'security'],
        ], $advisories['findings'] ?? []);

        return [
            'findings' => [...array_slice($findings, 0, self::FINDING_LIMIT), ...$dependencyFindings],
            'coverage' => $coverage,
        ];
    }

    private function staticCoverage(int $checked, int $eligible, int $findings): string
    {
        return match (true) {
            $eligible === 0 => 'empty',
            $checked === 0 => 'unavailable',
            $checked < $eligible || $findings > self::FINDING_LIMIT => 'partial',
            default => 'reviewed',
        };
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function prioritize(array $paths, ?string $guidance): array
    {
        $paths = array_values(array_filter($paths, static fn (string $path): bool => preg_match('~\A(?:app|config|routes)/[a-zA-Z0-9_./-]+\.php\z~D', $path) === 1));
        $words = array_unique(array_filter(preg_split('/[^a-zA-Z0-9]+/', strtolower(substr($guidance ?? '', 0, 1200))),
            static fn (string $word): bool => strlen($word) >= 4));
        $priority = [];
        foreach ($paths as $path) {
            $score = match (true) {
                in_array($path, ['config/app.php', 'config/services.php', 'config/session.php'], true) => 6,
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
    private function sourceFindings(string $path, string $source, array $sectors): array
    {
        $backend = in_array('backend', $sectors, true);
        $security = in_array('security', $sectors, true);
        $database = in_array('database', $sectors, true);
        $migration = $database && str_starts_with($path, 'database/migrations/');
        try {
            $tokens = array_values(array_filter(PhpToken::tokenize($source, TOKEN_PARSE), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        } catch (ParseError $error) {
            return $backend || $database ? [$this->finding($path, max(1, $error->getLine()), $migration || ! $backend ? 'database' : 'backend', 'php_syntax', 'PHP source failed to parse', 'high',
                'Application source parses without syntax errors.',
                'The PHP parser could not read this source file.',
                'Correct the PHP syntax at the reported line and rerun the project tests.')] : [];
        }
        if ($migration) {
            return $this->migrationFindings($path, $tokens);
        }

        $findings = [];
        $recorded = [];
        if ($database && str_starts_with($path, 'app/') && ($line = $this->queryInsideForeach($tokens)) !== null) {
            $findings[] = $this->finding($path, $line, 'database', 'query_inside_loop', 'Database query may repeat for every item', 'medium',
                'Batch processing should keep query counts bounded.', 'A database query builder read appears inside a foreach loop.',
                'Measure the query count in staging. If it grows with the input, consider fetching the required rows in one bounded batch.');
        }

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            $value = $tokens[$index + 2] ?? null;
            $after = $tokens[$index + 3] ?? null;
            if ($security && $token->is(T_CONSTANT_ENCAPSED_STRING) && $next?->is(T_DOUBLE_ARROW)
                && $value !== null && $after?->is([',', ']'])) {
                $finding = $this->configurationFinding($path, $token, $value);
                if ($finding !== null && ! isset($recorded[$finding['evidence']['rule']])) {
                    $recorded[$finding['evidence']['rule']] = true;
                    $findings[] = $finding;
                }
            }

            if ($backend && ! isset($recorded['debug_output']) && $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next?->is('(')
                && in_array(strtolower(ltrim($token->text, '\\')), ['dd', 'var_dump', 'debug_print_backtrace'], true)
                && ! ($tokens[$index - 1] ?? null)?->is([T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $recorded['debug_output'] = true;
                $findings[] = $this->finding($path, $token->line, 'backend', 'debug_output', 'Debug output in application source', 'medium',
                    'Requests use structured error handling and logging.',
                    'A debug call may interrupt output or expose internal state when its code path runs.',
                    'Remove the debugging call or replace it with structured logging that omits sensitive data.');
            }

            if ($backend && $token->is(T_CATCH) && ! isset($recorded['empty_catch']) && $this->emptyCatch($tokens, $index)) {
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
    private function migrationFindings(string $path, array $tokens): array
    {
        $upMethod = false;
        $braceDepth = 0;
        $findings = [];
        foreach ($tokens as $index => $token) {
            $following = $tokens[$index + 1] ?? null;
            if ($braceDepth === 0 && $token->is(T_FUNCTION) && $following?->is(T_STRING) && strtolower($following->text) === 'up') {
                $upMethod = true;
            }
            if (! $upMethod) {
                continue;
            }
            if ($braceDepth === 0 && $token->is(';')) {
                break;
            }
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braceDepth++;
            } elseif ($token->is('}') && --$braceDepth === 0) {
                break;
            }
            $method = $token->is(T_STRING) && $following?->is('(') ? strtolower($token->text) : '';
            $object = $tokens[$index - 1] ?? null;
            $qualifier = $tokens[$index - 2] ?? null;
            if (in_array($method, ['drop', 'dropifexists', 'dropcolumn'], true)
                && ($object?->is(T_OBJECT_OPERATOR) || ($object?->is(T_DOUBLE_COLON) && str_ends_with(strtolower($qualifier?->text ?? ''), 'schema')))) {
                $findings[] = $this->finding($path, $token->line, 'database', 'destructive_forward_migration', 'Forward migration drops stored data', 'medium',
                    'Dropping stored data has a reviewed migration and recovery plan.', 'The migration up method contains a schema drop operation.',
                    'Confirm that the data is obsolete and recoverable, and rehearse the migration against a disposable database before release.');
            }
            if (in_array($method, ['foreignid', 'foreignuuid', 'foreignulid'], true) && $object?->is(T_OBJECT_OPERATOR)) {
                $tail = collect(array_slice($tokens, $index, 60))->takeUntil(static fn (PhpToken $next): bool => $next->is(';'));
                if (! $tail->contains(static fn (PhpToken $next): bool => $next->is(T_STRING) && in_array(strtolower($next->text), ['constrained', 'references', 'foreign'], true))) {
                    $findings[] = $this->finding($path, $token->line, 'database', 'review_foreign_key_constraint', 'Foreign key constraint is not visible in the column chain', 'low',
                        'Relationship constraints are intentional and defined where required.', 'The foreign ID declaration has no inline constraint. It may be defined separately.',
                        'Check adjacent and later migrations before adding a constraint. Verify existing records satisfy the relationship first.');
                }
            }
            if (count($findings) === 5) {
                break;
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

    /** @param list<PhpToken> $tokens */
    private function queryInsideForeach(array $tokens): ?int
    {
        $depth = 0;
        $loopStarts = $loops = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(T_FOREACH) && ($tokens[$index + 1] ?? null)?->is('(')) {
                $parens = 1;
                for ($cursor = $index + 2; isset($tokens[$cursor]) && $cursor <= $index + 120; $cursor++) {
                    $parens += (int) $tokens[$cursor]->is('(') - (int) $tokens[$cursor]->is(')');
                    if ($parens === 0) {
                        if (($tokens[$cursor + 1] ?? null)?->is('{')) {
                            $loopStarts[$cursor + 1] = true;
                        }

                        break;
                    }
                }
            }
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if (isset($loopStarts[$index])) {
                    $loops[$depth] = true;
                }
            } elseif ($token->is('}')) {
                unset($loops[$depth]);
                $depth--;
            }
            if ($loops === [] || ! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                || ! in_array(strtolower(ltrim($token->text, '\\')), ['db', 'illuminate\\support\\facades\\db'], true)
                || ! ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON)
                || strtolower($tokens[$index + 2]->text ?? '') !== 'table') {
                continue;
            }

            foreach (array_slice($tokens, $index + 3, 100) as $offset => $next) {
                if ($next->is([';', '{', '}'])) {
                    break;
                }
                if ($next->is(T_OBJECT_OPERATOR) && in_array(strtolower($tokens[$index + 4 + $offset]->text ?? ''), ['get', 'first', 'find', 'exists', 'count', 'sum', 'pluck', 'value'], true)) {
                    return $token->line;
                }
            }
        }

        return null;
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

        if ($path === 'config/session.php' && $setting === 'http_only' && $this->isBoolean($value, 'false')) {
            return $this->finding($path, $key->line, 'security', 'session_httponly_disabled', 'Session cookie disables HttpOnly', 'medium',
                'Session cookies should be reserved for browser HTTP requests.',
                'Laravel session configuration explicitly disables HttpOnly.',
                'Keep HttpOnly enabled for the session cookie and use a separate non-sensitive cookie for client scripts if needed.');
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
                'category' => $category, 'sector' => $category, 'rule' => $rule, 'path' => $path, 'line' => $line,
                'remediation' => $remediation, ...($change !== null ? ['suggested_change' => $change] : []),
            ],
        ];
    }
}
