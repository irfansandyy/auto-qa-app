<?php

declare(strict_types=1);

/** This file is trusted image code. Never execute it against target source on the host. */
const WORKSPACE = '/workspace';
const MAX_FILES = 4000;
const MAX_SOURCE_BYTES = 128 * 1024 * 1024;

function safeRelativePath(string $path): bool
{
    if ($path === '' || strlen($path) > 512 || ! preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path)) {
        return false;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function excludedSource(string $path): bool
{
    foreach (explode('/', $path) as $segment) {
        if (in_array(strtolower($segment), ['.git', '.aws', '.ssh', 'vendor', 'node_modules', 'storage', 'secrets', 'credentials', 'private_keys', 'private-keys'], true)
            || preg_match('/^(?:\.env(?:\..*)?|auth\.json|credentials(?:\..*)?|secrets?(?:\..*)?|__autoqa.*)$/iD', $segment)
            || preg_match('/\.(pem|key|p12|pfx)$/iD', $segment)) {
            return true;
        }
    }

    return str_starts_with($path, 'bootstrap/cache/') || $path === 'public/hot'
        || $path === 'public/build' || str_starts_with($path, 'public/build/');
}

function validatePreparedSource(string $sourceSha): void
{
    $marker = '/opt/target/source.sha';
    if (is_link($marker) || ! is_file($marker) || filesize($marker) > 128) {
        throw new RuntimeException('The trusted image must include a bounded source.sha commit marker.');
    }
    $preparedSha = trim(file_get_contents($marker, false, null, 0, 129));
    if (! preg_match('/^[a-f0-9]{40}$/D', $preparedSha) || ! hash_equals($sourceSha, $preparedSha)) {
        throw new RuntimeException('The trusted image assets do not match the deployed commit. Rebuild the image from that exact source.');
    }
}

function copyPreparedAssets(): void
{
    $source = '/opt/target/public/build';
    if (is_link($source) || ! is_dir($source)) {
        throw new RuntimeException('The trusted image must include public/build, even when empty.');
    }
    $destinationRoot = WORKSPACE.'/public/build';
    if (! is_dir($destinationRoot) && ! mkdir($destinationRoot, 0755, true)) {
        throw new RuntimeException('The prepared frontend asset directory could not be created.');
    }
    $total = 0;
    $files = 0;
    $entries = 0;
    $extensions = ['css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'wasm', 'txt'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        if (++$entries > 2000 || $entry->isLink() || ! safeRelativePath($relative) || excludedSource($relative)) {
            throw new RuntimeException('The prepared frontend bundle contains unsafe paths, links, or too many entries.');
        }
        $destination = $destinationRoot.'/'.$relative;
        if ($entry->isDir()) {
            if (! mkdir($destination, 0755)) {
                throw new RuntimeException('A prepared frontend asset directory could not be created.');
            }
        } elseif ($entry->isFile()) {
            $total += $entry->getSize();
            if (++$files > 1000 || $total > 32 * 1024 * 1024
                || ! in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $extensions, true)
                || ! copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('The prepared frontend bundle exceeds its bounds or contains an unsupported file.');
            }
            chmod($destination, 0644);
        } else {
            throw new RuntimeException('The prepared frontend bundle contains a special file.');
        }
    }
}

function readExact(mixed $stream, int $length): string
{
    $result = '';
    while (strlen($result) < $length && ! gzeof($stream)) {
        $chunk = gzread($stream, min(65536, $length - strlen($result)));
        if ($chunk === false) {
            throw new RuntimeException('The source archive cannot be read.');
        }
        $result .= $chunk;
    }
    if (strlen($result) !== $length) {
        throw new RuntimeException('The source archive is truncated.');
    }

    return $result;
}

function tarNumber(string $field): int
{
    $number = trim($field, " \0");
    if ($number === '' || ! preg_match('/^[0-7]{1,12}$/D', $number)) {
        throw new RuntimeException('The source archive has an unsupported numeric header.');
    }

    return octdec($number);
}

function paxValues(string $data): array
{
    $result = [];
    while ($data !== '') {
        $space = strpos($data, ' ');
        if ($space === false || ! ctype_digit(substr($data, 0, $space))) {
            throw new RuntimeException('The source archive has invalid extended headers.');
        }
        $length = (int) substr($data, 0, $space);
        if ($length <= $space + 2 || $length > strlen($data)) {
            throw new RuntimeException('The source archive has invalid extended headers.');
        }
        $record = substr($data, $space + 1, $length - $space - 2);
        $equals = strpos($record, '=');
        if ($equals === false) {
            throw new RuntimeException('The source archive has invalid extended headers.');
        }
        $result[substr($record, 0, $equals)] = substr($record, $equals + 1);
        $data = substr($data, $length);
    }

    return $result;
}

function extractSource(string $archive): void
{
    $stream = gzopen($archive, 'rb');
    if ($stream === false) {
        throw new RuntimeException('The source archive cannot be opened.');
    }
    $root = null;
    $total = 0;
    $count = 0;
    $next = [];
    $seen = [];
    try {
        while (true) {
            $header = readExact($stream, 512);
            if ($header === str_repeat("\0", 512)) {
                break;
            }
            if (++$count > MAX_FILES) {
                throw new RuntimeException('The source archive contains too many entries.');
            }
            $checksum = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)));
            if (tarNumber(substr($header, 148, 8)) !== $checksum) {
                throw new RuntimeException('The source archive has an invalid checksum.');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix.'/'.$name;
            }
            $size = tarNumber(substr($header, 124, 12));
            $type = $header[156];
            if (in_array($type, ['x', 'g', 'L'], true)) {
                if ($size > 8192) {
                    throw new RuntimeException('The source archive has oversized extended headers.');
                }
                $metadata = readExact($stream, (int) (ceil($size / 512) * 512));
                $metadata = substr($metadata, 0, $size);
                $values = $type === 'L' ? ['path' => rtrim($metadata, "\0\n")] : paxValues($metadata);
                if (isset($values['linkpath']) || isset($values['size'])
                    || ($type === 'g' && isset($values['path']))) {
                    throw new RuntimeException('The source archive has unsupported link or size metadata.');
                }
                if ($type !== 'g') {
                    $next = $values;
                }

                continue;
            }
            $name = rtrim($next['path'] ?? $name, '/');
            $next = [];
            if (! safeRelativePath($name) || ! in_array($type, ['0', "\0", '5'], true)) {
                throw new RuntimeException('The source archive contains an unsafe path, link, or special file.');
            }
            [$entryRoot, $relative] = array_pad(explode('/', $name, 2), 2, '');
            $root ??= $entryRoot;
            if ($entryRoot !== $root || isset($seen[$name])) {
                throw new RuntimeException('The source archive has mixed roots or duplicate paths.');
            }
            $seen[$name] = true;
            if ($size > 5 * 1024 * 1024 || ($total += $size) > MAX_SOURCE_BYTES || ($type === '5' && $size !== 0)) {
                throw new RuntimeException('The extracted source exceeds the permitted size.');
            }
            $bytes = readExact($stream, (int) (ceil($size / 512) * 512));
            if ($relative === '' || excludedSource($relative)) {
                continue;
            }
            $destination = WORKSPACE.'/'.$relative;
            if ($type === '5') {
                if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
                    throw new RuntimeException('An extracted source directory could not be created.');
                }

                continue;
            }
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }
            if (is_dir($destination) || file_put_contents($destination, substr($bytes, 0, $size)) === false) {
                throw new RuntimeException('An extracted source file could not be created.');
            }
            chmod($destination, 0644);
        }
    } finally {
        gzclose($stream);
    }
    if (! is_file(WORKSPACE.'/artisan') || ! is_file(WORKSPACE.'/composer.lock')) {
        throw new RuntimeException('The snapshot must contain a Laravel application and composer.lock.');
    }
}

function copyDependencies(): void
{
    if (! is_file('/opt/target/composer.lock') || hash_file('sha256', WORKSPACE.'/composer.lock') !== hash_file('sha256', '/opt/target/composer.lock')) {
        throw new RuntimeException('The runner dependencies do not match the snapshot composer.lock. Rebuild the trusted target image.');
    }
    $source = '/opt/target/vendor';
    if (! is_dir($source) || ! is_file($source.'/phpunit/phpunit/phpunit')) {
        throw new RuntimeException('The trusted image must contain target dependencies and PHPUnit.');
    }
    $total = 0;
    mkdir(WORKSPACE.'/vendor', 0755);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = WORKSPACE.'/vendor/'.$relative;
        if ($entry->isLink()) {
            throw new RuntimeException('The trusted dependency image must not contain dependency symlinks.');
        }
        if ($entry->isDir()) {
            mkdir($destination, 0755);
        } elseif ($entry->isFile()) {
            $total += $entry->getSize();
            if ($total > 256 * 1024 * 1024 || ! copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('The trusted dependencies exceed the workspace limit or cannot be copied.');
            }
        }
    }
}

function validateAndApply(array $payload, string $phase): void
{
    $files = $payload['files'] ?? [];
    $test = $payload['test'] ?? '';
    if (! is_array($files) || count($files) < 1 || count($files) > 5 || ! is_string($test)
        || ! preg_match('~^tests/Feature/[A-Za-z0-9_/]*Test\.php$~D', $test) || ! isset($files[$test])
        || file_exists(WORKSPACE.'/'.$test)) {
        throw new RuntimeException('The regression test must be a new feature-test file.');
    }
    $total = 0;
    $tests = 0;
    foreach ($files as $path => $content) {
        if (! is_string($path) || ! safeRelativePath($path) || excludedSource($path)
            || ! preg_match('~^(app|resources/views|routes|tests/Feature)/[A-Za-z0-9_./-]+\.php$~D', $path)
            || ! is_string($content) || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('The patch contains an unsupported file or content.');
        }
        $total += strlen($content);
        if (str_starts_with($path, 'tests/')) {
            $tests++;
        } elseif (! is_file(WORKSPACE.'/'.$path)) {
            throw new RuntimeException('Application repairs may only modify existing source files.');
        }
    }
    if ($total > 65536 || $tests !== 1 || hash('sha256', json_encode($files)) !== ($payload['patch_sha256'] ?? null)) {
        throw new RuntimeException('The patch exceeds its bounds or does not match the verification identity.');
    }
    if ($phase === 'baseline_suite') {
        return;
    }
    $selected = $phase === 'baseline_regression_assertion' ? [$test => $files[$test]] : $files;
    foreach ($selected as $path => $content) {
        if (! is_dir(dirname(WORKSPACE.'/'.$path))) {
            mkdir(dirname(WORKSPACE.'/'.$path), 0755, true);
        }
        if (file_put_contents(WORKSPACE.'/'.$path, $content) === false) {
            throw new RuntimeException('A proposed repair file could not be applied.');
        }
    }
}

function boundedProcess(array $command, int $timeout): array
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, WORKSPACE);
    if (! is_resource($process)) {
        throw new RuntimeException('The fixed test process could not be started.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + $timeout;
    $bytes = 0;
    $exit = -1;
    try {
        do {
            foreach ([1, 2] as $index) {
                $bytes += strlen(fread($pipes[$index], 65536));
            }
            if ($bytes > 65536 || microtime(true) > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('The test process exceeded its time or output limit.');
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            usleep(20000);
        } while (true);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
    }

    return ['exit' => $exit >= 0 ? $exit : $closed];
}

function prepareRuntime(array $payload): void
{
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'database'] as $directory) {
        if (! is_dir(WORKSPACE.'/'.$directory)) {
            mkdir(WORKSPACE.'/'.$directory, 0755, true);
        }
    }
    touch(WORKSPACE.'/database/autoqa.sqlite');
    $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => $payload['preview_origin'], 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => WORKSPACE.'/database/autoqa.sqlite',
        'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'stderr', 'AUTOQA_SOURCE_SHA' => $payload['source_sha'], 'AUTOQA_PATCH_SHA256' => $payload['patch_sha256']];
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $phpunit = '<?xml version="1.0"?><phpunit bootstrap="/workspace/vendor/autoload.php"><testsuites><testsuite name="Target"><directory>/workspace/tests</directory></testsuite></testsuites><php>';
    foreach ($environment as $key => $value) {
        $phpunit .= '<env name="'.$key.'" value="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'" force="true"/>';
    }
    file_put_contents(WORKSPACE.'/__autoqa_phpunit.xml', $phpunit.'</php></phpunit>');
    if (boundedProcess([PHP_BINARY, WORKSPACE.'/artisan', 'package:discover', '--no-interaction'], 30)['exit'] !== 0) {
        throw new RuntimeException('The isolated Laravel application could not discover its prebuilt packages.');
    }
}

function testResult(array $payload, string $phase): array
{
    $command = [PHP_BINARY, WORKSPACE.'/vendor/phpunit/phpunit/phpunit', '--configuration', WORKSPACE.'/__autoqa_phpunit.xml',
        '--do-not-cache-result', '--log-junit', WORKSPACE.'/__autoqa_junit.xml'];
    if (in_array($phase, ['baseline_regression_assertion', 'patched_regression'], true)) {
        $command[] = WORKSPACE.'/'.$payload['test'];
    }
    $process = boundedProcess($command, (int) $payload['test_timeout']);
    $report = WORKSPACE.'/__autoqa_junit.xml';
    if (! is_file($report) || filesize($report) > 1024 * 1024) {
        throw new RuntimeException('The test did not produce a bounded PHPUnit report. Syntax and bootstrap failures are not regression evidence.');
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($report, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException('The PHPUnit report could not be parsed.');
    }
    $tests = count($xml->xpath('//testcase'));
    $failures = $xml->xpath('//testcase/failure');
    $errors = count($xml->xpath('//testcase/error'));
    $skipped = count($xml->xpath('//testcase/skipped'));
    $assertionFailure = count($failures) > 0;
    foreach ($failures as $failure) {
        $assertionFailure = $assertionFailure && in_array((string) $failure['type'], ['PHPUnit\Framework\AssertionFailedError', 'PHPUnit\Framework\ExpectationFailedException'], true);
    }
    $status = 'failed';
    if ($tests > $skipped && $errors === 0 && count($failures) === 0 && $process['exit'] === 0) {
        $status = 'passed';
    } elseif ($phase === 'baseline_regression_assertion' && $tests > $skipped && $errors === 0 && $assertionFailure && $process['exit'] === 1) {
        $status = 'assertion_failed';
    }

    return ['status' => $status, 'tests' => $tests, 'failures' => count($failures), 'errors' => $errors, 'skipped' => $skipped,
        'message' => $status === 'failed' ? 'The required PHPUnit result was not established; runtime errors and skipped-only suites do not count.' : 'The required PHPUnit result was established.'];
}

$phase = $argv[1] ?? '';
$payload = [];
try {
    if (! in_array($phase, ['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'preview'], true)
        || filesize('/input/payload.json') > 131072) {
        throw new RuntimeException('The trusted runner input is invalid.');
    }
    $payload = json_decode(file_get_contents('/input/payload.json'), true, 32, JSON_THROW_ON_ERROR);
    if (! preg_match('/^[a-f0-9]{40}$/iD', $payload['source_sha'] ?? '') || ! preg_match('/^[a-f0-9]{64}$/D', $payload['patch_sha256'] ?? '')
        || ! is_int($payload['test_timeout'] ?? null) || $payload['test_timeout'] < 15 || $payload['test_timeout'] > 180
        || ! preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D', $payload['preview_origin'] ?? '')) {
        throw new RuntimeException('The trusted runner input identity or limits are invalid.');
    }
    validatePreparedSource($payload['source_sha']);
    chdir(WORKSPACE);
    extractSource('/input/source.tar.gz');
    copyPreparedAssets();
    copyDependencies();
    validateAndApply($payload, $phase);
    prepareRuntime($payload);
    if ($phase === 'preview') {
        if (boundedProcess([PHP_BINARY, WORKSPACE.'/artisan', 'migrate', '--force', '--seed', '--no-interaction'], 60)['exit'] !== 0) {
            throw new RuntimeException('The isolated preview fixtures could not be migrated and seeded.');
        }
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8080', '-t', WORKSPACE.'/public', '/opt/autoqa/preview-router.php'],
            [STDIN, STDOUT, STDERR], $pipes, WORKSPACE);
        if (! is_resource($server)) {
            throw new RuntimeException('The isolated preview server could not start.');
        }
        exit(proc_close($server));
    }
    $result = testResult($payload, $phase);
} catch (Throwable) {
    $result = ['status' => 'failed', 'tests' => 0, 'failures' => 0, 'errors' => 1,
        'message' => 'Isolated preparation or test execution failed. Check the image commit marker, frontend bundle, target lockfile, archive bounds, new regression file, and fixture recipe.'];
}
echo 'AUTOQA_RESULT:'.json_encode(['phase' => $phase, 'source_sha' => $payload['source_sha'] ?? null,
    'patch_sha256' => $payload['patch_sha256'] ?? null, ...$result], JSON_THROW_ON_ERROR)."\n";
