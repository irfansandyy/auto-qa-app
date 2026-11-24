<?php

namespace App\Services;

use Composer\Semver\Semver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class DependencyAdvisories
{
    private const PACKAGIST = 'https://packagist.org/api/security-advisories/';

    private const OSV = 'https://api.osv.dev/v1/';

    private const MAX_PACKAGES = 300;

    private const MAX_FINDINGS = 20;

    private const MAX_OSV_DETAILS = 12;

    /** @param array<string, string> $lockfiles */
    public function review(array $lockfiles): array
    {
        $findings = [];
        $reviewed = 0;
        $skipped = 0;
        $checked = 0;
        $partial = false;
        $npm = $lockfiles['npm-shrinkwrap.json'] ?? $lockfiles['package-lock.json'] ?? null;
        $definitions = [
            'Packagist' => $lockfiles['composer.lock'] ?? null,
            'npm' => $npm,
        ];

        foreach ($definitions as $ecosystem => $contents) {
            if ($contents === null) {
                continue;
            }

            try {
                $lock = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($lock)) {
                    throw new RuntimeException('The lockfile has an unsupported format.');
                }

                $packages = $ecosystem === 'Packagist' ? $this->composerPackages($lock) : $this->npmPackages($lock);
                $skipped += $packages['skipped'];
                $list = array_slice($packages['versions'], 0, self::MAX_PACKAGES);
                $overflow = count($packages['versions']) - count($list);
                $skipped += $overflow;
                $partial = $partial || $overflow > 0;

                if ($list === []) {
                    $checked++;
                    continue;
                }

                $observed = $ecosystem === 'Packagist' ? $this->packagistAdvisories($list) : $this->npmAdvisories($list);
                array_push($findings, ...$observed['findings']);
                $reviewed += count($list);
                $partial = $partial || $observed['partial'];
                $checked++;
            } catch (ConnectionException|JsonException|RuntimeException) {
                $partial = true;
            }
        }

        $status = match (true) {
            $lockfiles === [] => 'no_supported_lockfiles',
            $checked === 0 => 'unavailable',
            $partial || count($findings) > self::MAX_FINDINGS => 'partial',
            default => 'reviewed',
        };

        return [
            'findings' => array_slice($findings, 0, self::MAX_FINDINGS),
            'advisory_status' => $status,
            'packages_reviewed' => $reviewed,
            'packages_skipped' => $skipped,
        ];
    }

    /** @return array{versions: list<array{name: string, version: string}>, skipped: int} */
    private function composerPackages(array $lock): array
    {
        if (! is_array($lock['packages'] ?? null) || ! is_array($lock['packages-dev'] ?? [])) {
            throw new RuntimeException('Composer lock format is unsupported.');
        }

        $packages = [];
        $skipped = 0;
        foreach ([...$lock['packages'], ...($lock['packages-dev'] ?? [])] as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            if (! is_string($name) || preg_match('~\A[a-z0-9_.-]{1,100}/[a-z0-9_.-]{1,100}\z~D', $name) !== 1
                || ! $this->isRelease($version) || in_array($package['dist']['type'] ?? null, ['path', 'file'], true)
                || ($package['type'] ?? null) === 'project') {
                $skipped++;
                continue;
            }
            $packages[$name.'@'.$version] = ['name' => $name, 'version' => $version];
        }

        return ['versions' => array_values($packages), 'skipped' => $skipped];
    }

    /** @return array{versions: list<array{name: string, version: string}>, skipped: int} */
    private function npmPackages(array $lock): array
    {
        if (! is_array($lock['packages'] ?? null)) {
            throw new RuntimeException('npm lockfile v2 or v3 is required.');
        }

        $packages = [];
        $skipped = 0;
        foreach ($lock['packages'] as $path => $package) {
            if ($path === '') {
                continue;
            }
            $version = $package['version'] ?? null;
            $resolved = $package['resolved'] ?? null;
            if (! is_string($path) || preg_match('~(?:^|/)node_modules/((?:@[a-z0-9_.-]+/)?[a-z0-9_.-]+)\z~D', $path, $match) !== 1
                || ! $this->isRelease($version) || ! is_string($resolved) || ($package['link'] ?? false)
                || ($package['name'] ?? $match[1]) !== $match[1] || ! $this->publicNpmDist($resolved, $match[1], $version)) {
                $skipped++;
                continue;
            }
            $packages[$match[1].'@'.$version] = ['name' => $match[1], 'version' => $version];
        }

        return ['versions' => array_values($packages), 'skipped' => $skipped];
    }

    private function publicNpmDist(string $url, string $name, string $version): bool
    {
        $package = basename($name);
        $expected = '/'.$name.'/-/'.$package.'-'.$version.'.tgz';
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'registry.npmjs.org'
            && ! array_intersect_key($parts, array_flip(['query', 'fragment', 'port', 'user', 'pass']))
            && strcasecmp(rawurldecode($parts['path'] ?? ''), $expected) === 0;
    }

    /** @param list<array{name: string, version: string}> $packages */
    private function packagistAdvisories(array $packages): array
    {
        $response = $this->request(12)->withOptions(['stream' => true])->get(self::PACKAGIST, ['updatedSince' => 1]);
        $records = $this->json($response, 6_500_000)['advisories'] ?? null;
        if (! is_array($records)) {
            throw new RuntimeException('Packagist did not return advisories.');
        }

        $findings = [];
        $partial = false;
        foreach ($packages as $package) {
            foreach ($records[$package['name']] ?? [] as $record) {
                $constraint = $record['affectedVersions'] ?? null;
                $id = $record['advisoryId'] ?? null;
                if (! is_string($constraint) || strlen($constraint) > 1000 || ! is_string($id)
                    || preg_match('/\APKSA-[a-z0-9-]{8,24}\z/D', $id) !== 1) {
                    $partial = true;
                    continue;
                }
                try {
                    if (! Semver::satisfies($package['version'], $constraint)) {
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    $partial = true;
                    continue;
                }
                $fixed = [];
                if (preg_match('/\A<(\S+)\z/D', $constraint, $bound) === 1 && $this->isRelease($bound[1])) {
                    $fixed[] = $bound[1];
                }
                $findings[] = $this->finding('Packagist', $package, $id, [$record['cve'] ?? null],
                    'https://packagist.org/security-advisories/'.$id, $this->severity($record['severity'] ?? null), $fixed);
                if (count($findings) > self::MAX_FINDINGS) {
                    return ['findings' => $findings, 'partial' => true];
                }
            }
        }

        return ['findings' => $findings, 'partial' => $partial];
    }

    /** @param list<array{name: string, version: string}> $packages */
    private function npmAdvisories(array $packages): array
    {
        $queries = array_map(static fn (array $package): array => [
            'version' => $package['version'],
            'package' => ['ecosystem' => 'npm', 'name' => $package['name']],
        ], $packages);
        $response = $this->request(8)->withOptions(['stream' => true])->post(self::OSV.'querybatch', ['queries' => $queries]);
        $results = $this->json($response, 1_000_000)['results'] ?? null;
        if (! is_array($results) || ! array_is_list($results) || count($results) !== count($packages)) {
            throw new RuntimeException('OSV batch did not return all results.');
        }

        $matches = [];
        $partial = false;
        foreach ($results as $index => $result) {
            $partial = $partial || isset($result['next_page_token']);
            foreach ($result['vulns'] ?? [] as $item) {
                $id = $item['id'] ?? null;
                if (! is_string($id) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{1,59}\z/D', $id) !== 1) {
                    $partial = true;
                    continue;
                }
                $matches[] = ['id' => $id, 'package' => $packages[$index]];
            }
        }

        $ids = array_values(array_unique(array_column($matches, 'id')));
        $details = $this->osvDetails(array_slice($ids, 0, self::MAX_OSV_DETAILS));
        $partial = $partial || count($matches) > self::MAX_FINDINGS || count($ids) !== count($details);
        $findings = [];

        foreach (array_slice($matches, 0, self::MAX_FINDINGS) as $match) {
            $id = $match['id'];
            $package = $match['package'];
            $record = $details[$id] ?? [];
            if (isset($record['withdrawn'])) {
                continue;
            }
            $aliases = is_array($record['aliases'] ?? null) ? $record['aliases'] : [];
            $findings[] = $this->finding('npm', $package, $id, [$id, ...$aliases],
                'https://osv.dev/vulnerability/'.$id, $this->severity($record['database_specific']['severity'] ?? null),
                $this->osvFixedVersions($record, $package));
        }

        return ['findings' => $findings, 'partial' => $partial];
    }

    /** @param list<string> $ids */
    private function osvDetails(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $responses = Http::pool(fn (Pool $pool): array => array_map(fn (string $id) => $pool->as($id)
            ->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->withOptions(['stream' => true])
            ->get(self::OSV.'vulns/'.$id), $ids), concurrency: 4);
        $records = [];

        foreach ($responses as $id => $response) {
            if (! $response instanceof Response) {
                continue;
            }
            try {
                $record = $this->json($response, 280_000);
                if (($record['id'] ?? null) === $id) {
                    $records[$id] = $record;
                }
            } catch (JsonException|RuntimeException) {
            }
        }

        return $records;
    }

    /** @param array{name: string, version: string} $package */
    private function osvFixedVersions(array $record, array $package): array
    {
        $versions = [];
        foreach ($record['affected'] ?? [] as $affected) {
            if (($affected['package']['ecosystem'] ?? null) !== 'npm' || ($affected['package']['name'] ?? null) !== $package['name']) {
                continue;
            }
            foreach ($affected['ranges'] ?? [] as $range) {
                if (($range['type'] ?? null) !== 'SEMVER') {
                    continue;
                }
                foreach ($range['events'] ?? [] as $event) {
                    $fixed = $event['fixed'] ?? null;
                    if ($this->isRelease($fixed) && version_compare($fixed, $package['version'], '>')) {
                        $versions[] = $fixed;
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($versions)), 0, 5);
    }

    /** @param array{name: string, version: string} $package */
    private function finding(string $ecosystem, array $package, string $id, array $aliases, string $url, string $severity, array $fixed): array
    {
        $cves = array_values(array_unique(array_filter($aliases,
            static fn (mixed $alias): bool => is_string($alias) && preg_match('/\ACVE-[0-9]{4}-[0-9]{4,10}\z/D', $alias) === 1)));

        return [
            'title' => ($cves[0] ?? $id).' affects '.$package['name'],
            'severity' => $severity,
            'status' => 'observed',
            'expected' => 'The locked dependency version has no known matching advisory.',
            'actual' => 'The advisory database lists '.$package['name'].' '.$package['version'].' as affected.',
            'evidence' => [
                'category' => 'dependency', 'ecosystem' => $ecosystem, 'package' => $package['name'],
                'version' => $package['version'], 'advisory_id' => $id, 'cves' => $cves,
                'advisory_url' => $url, 'fixed_versions' => $fixed,
                'remediation' => $fixed === []
                    ? 'Use the advisory to select a supported fixed release, refresh the lockfile, and run the project tests.'
                    : 'Review the listed fixed releases, update to a compatible supported release, and run the project tests.',
            ],
        ];
    }

    private function severity(mixed $value): string
    {
        return match (is_string($value) ? strtolower($value) : '') {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            'moderate', 'medium' => 'medium',
            default => 'info',
        };
    }

    private function isRelease(mixed $version): bool
    {
        return is_string($version) && strlen($version) <= 75
            && preg_match('/\Av?[0-9]+(?:\.[0-9]+){1,3}(?:[-+][a-z0-9.+-]+)?\z/iD', $version) === 1;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->withUserAgent('AutoQA Dependency Advisory Review')
            ->connectTimeout(3)->timeout($timeout);
    }

    /** @return array<string, mixed> */
    private function json(Response $response, int $limit): array
    {
        if (! $response->successful() || (int) $response->header('Content-Length') > $limit) {
            throw new RuntimeException('The advisory service is unavailable or exceeded its response limit.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            while (! $stream->eof() && strlen($body) <= $limit) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($body)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new RuntimeException('The advisory response did not finish.');
                }
                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }
        if (strlen($body) > $limit) {
            throw new RuntimeException('The advisory service exceeded its response limit.');
        }

        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('The advisory service did not return a JSON object.');
        }

        return $data;
    }
}
