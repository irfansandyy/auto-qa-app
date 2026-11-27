<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class QaRuleCatalog
{
    public const SECTORS = [
        'security', 'ui', 'ux', 'performance', 'backend', 'database', 'reliability',
        'maintainability', 'api', 'compatibility', 'testing', 'observability', 'privacy', 'delivery',
    ];

    private const SEVERITY_WEIGHT = ['high' => 3, 'medium' => 2, 'low' => 1];

    private const FOCUS_FILLER = ['check', 'review', 'test', 'please', 'when', 'with', 'from', 'that', 'this', 'periksa', 'tolong', 'yang', 'dan', 'untuk', 'dengan', 'pada', 'uji'];

    /** @return array<string, array{label: string, count: int}> */
    public function categories(): array
    {
        $counts = DB::table('qa_rules')->selectRaw('sector, count(*) as total')->groupBy('sector')->pluck('total', 'sector');
        $categories = [];
        foreach (self::SECTORS as $sector) {
            $categories[$sector] = [
                'label' => __('qa-rule-catalog.'.$sector),
                'count' => (int) $counts->get($sector, 0),
            ];
        }

        return $categories;
    }

    /**
     * @param  list<string>|null  $sectors
     * @return list<array{id: string, sector: string, title: string, check: string, remediation: string, scope: string, severity: string, mode: string}>
     */
    public function search(?array $sectors = null, ?string $query = null, int $limit = 24, int $offset = 0): array
    {
        return $this->query($sectors, $query)->orderBy('sector')->orderBy('id')
            ->offset(max(0, $offset))->limit(max(1, min($limit, 100)))->get()
            ->map(static fn (object $rule): array => (array) $rule)->all();
    }

    /** @param list<string>|null $sectors */
    public function count(?array $sectors = null, ?string $query = null): int
    {
        return $this->query($sectors, $query)->count();
    }

    /** @return array{id: string, sector: string, title: string, check: string, remediation: string, scope: string, severity: string, mode: string}|null */
    public function find(string $id): ?array
    {
        $rule = DB::table('qa_rules')->where('id', mb_strtolower(trim($id)))->first();

        return $rule === null ? null : (array) $rule;
    }

    /**
     * @param  list<string>  $sectors
     * @return list<array{id: string, sector: string, title: string, check: string, remediation: string, scope: string, severity: string, mode: string}>
     */
    public function recommend(array $sectors, ?string $focus = null, int $limit = 8): array
    {
        $sectors = array_values(array_intersect(self::SECTORS, $sectors));
        $limit = max(1, min($limit, 24));
        if ($sectors === []) {
            return [];
        }

        $terms = array_diff($this->terms($focus), self::FOCUS_FILLER);
        $groups = $this->query($sectors, null)->orderBy('id')->get()->groupBy('sector')
            ->map(static function ($rules) use ($terms): array {
                return $rules->map(static function (object $rule) use ($terms): array {
                    $title = mb_strtolower($rule->title.' '.$rule->id);
                    $description = mb_strtolower($rule->check.' '.$rule->remediation);
                    $relevance = 0;
                    foreach ($terms as $term) {
                        $relevance += str_contains($title, $term) ? 3 : (int) str_contains($description, $term);
                    }

                    return ['rule' => (array) $rule, 'relevance' => $relevance, 'weight' => self::SEVERITY_WEIGHT[$rule->severity]];
                })->sortBy([['relevance', 'desc'], ['weight', 'desc'], ['rule.id', 'asc']])->values()->all();
            });

        $best = collect($sectors)->filter(static fn (string $sector): bool => $groups->has($sector))
            ->sortByDesc(static fn (string $sector): int => $groups[$sector][0]['relevance'])->values()->all();
        $recommendations = [];
        for ($round = 0; $round < $limit; $round++) {
            foreach ($best as $sector) {
                if (isset($groups[$sector][$round])) {
                    $recommendations[] = $groups[$sector][$round]['rule'];
                }
                if (count($recommendations) === $limit) {
                    return $recommendations;
                }
            }
        }

        return $recommendations;
    }

    /** @param list<string>|null $sectors */
    private function query(?array $sectors, ?string $query): Builder
    {
        $builder = DB::table('qa_rules');
        if ($sectors !== null) {
            $builder->whereIn('sector', array_values(array_intersect(self::SECTORS, $sectors)));
        }

        $terms = $this->terms($query);
        if ($terms === [] && is_string($query) && trim($query) !== '') {
            return $builder->whereRaw('1 = 0');
        }

        foreach ($terms as $term) {
            $pattern = '%'.$term.'%';
            $builder->where(static function (Builder $builder) use ($pattern): void {
                $builder->whereLike('id', $pattern)->orWhereLike('title', $pattern)
                    ->orWhereLike('check', $pattern)->orWhereLike('remediation', $pattern);
            });
        }

        return $builder;
    }

    /** @return list<string> */
    private function terms(?string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower(mb_substr($query ?? '', 0, 240)), $matches);

        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 10);
    }
}
