<?php

namespace App\Services;

use Illuminate\Support\Collection;

class QaSectors
{
    public const ALL = ['security', 'ui', 'ux', 'performance', 'backend', 'database'];

    public const BROWSER = ['security', 'ui', 'ux', 'performance', 'backend'];

    public const REPOSITORY = ['security', 'backend', 'database'];

    /** @return list<string> */
    public static function selected(?array $sectors): array
    {
        return $sectors === null ? self::ALL : array_values(array_intersect(QaRuleCatalog::SECTORS, array_filter($sectors, 'is_string')));
    }

    /** @param list<string> $sectors */
    public static function needsBrowser(array $sectors): bool
    {
        return array_intersect($sectors, self::BROWSER) !== [];
    }

    /** @param list<string> $sectors */
    public static function coverage(array $sectors, array $audit, Collection $findings): array
    {
        $source = $audit['repository']['source_status'] ?? 'not_checked';
        $frontend = $audit['frontend']['status'] ?? 'not_checked';
        $checks = [
            'security' => ['headers' => $audit['hardening']['status'] ?? 'not_checked', 'source_configuration' => $audit['repository']['configuration_status'] ?? $source,
                'dependency_advisories' => $audit['repository']['advisory_status'] ?? 'not_checked'],
            'ui' => ['responsive_layout' => $frontend],
            'ux' => ['accessible_structure' => $frontend, 'link_health' => $audit['browser_status'] ?? 'not_checked'],
            'performance' => ['navigation_timing' => $audit['performance']['status'] ?? 'not_checked'],
            'backend' => ['http_behavior' => $audit['browser_status'] ?? 'not_checked', 'source_logic' => $source],
            'database' => ['migration_review' => $audit['repository']['database_status'] ?? 'not_checked', 'query_source' => $audit['repository']['query_status'] ?? 'not_checked'],
        ];

        $counts = $findings->countBy(static fn ($finding): string => $finding->evidence['sector'] ?? 'other');
        $coverage = [];
        foreach ($checks as $sector => $results) {
            if (! in_array($sector, $sectors, true)) {
                $coverage[$sector] = ['status' => 'disabled', 'checks' => [], 'findings_count' => 0];

                continue;
            }
            $status = match (true) {
                count(array_filter($results, static fn (string $status): bool => $status === 'reviewed')) === count($results) => 'reviewed',
                array_intersect($results, ['reviewed', 'partial']) !== [] => 'partial',
                array_intersect($results, ['unavailable', 'unauthorized']) !== [] => 'unavailable',
                default => 'skipped',
            };
            $coverage[$sector] = ['status' => $status, 'checks' => $results, 'findings_count' => $counts->get($sector, 0)];
        }

        return $coverage;
    }
}
