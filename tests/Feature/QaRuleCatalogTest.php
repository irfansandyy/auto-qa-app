<?php

namespace Tests\Feature;

use App\Services\QaRuleCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QaRuleCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_fresh_database_has_hundreds_of_distinct_guidance_rules(): void
    {
        $catalog = app(QaRuleCatalog::class);

        $categories = $catalog->categories();
        $rule = $catalog->find('security.access-boundaries');

        $this->assertCount(14, $categories);
        $this->assertGreaterThanOrEqual(200, array_sum(array_column($categories, 'count')));
        $this->assertGreaterThanOrEqual(10, min(array_column($categories, 'count')));
        $this->assertSame('guidance', $rule['mode']);
        $this->assertSame('source', $rule['scope']);
        $this->assertNotEmpty($rule['check']);
        $this->assertNotEmpty($rule['remediation']);
    }

    public function test_categories_publish_labels_in_the_application_language(): void
    {
        app()->setLocale('id');

        $categories = app(QaRuleCatalog::class)->categories();

        $this->assertSame('Basis data', $categories['database']['label']);
        $this->assertSame('API dan integrasi', $categories['api']['label']);
    }

    public function test_search_filters_by_sector_and_words_and_paginates_in_stable_order(): void
    {
        $catalog = app(QaRuleCatalog::class);

        $first = $catalog->search(['database'], limit: 3);
        $second = $catalog->search(['database'], limit: 3, offset: 3);
        $matches = $catalog->search(['database'], 'query timeout');

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->assertSame('database.query-timeout', $matches[0]['id']);
        $this->assertSame(count($matches), $catalog->count(['database'], 'query timeout'));
        $this->assertSame(0, $catalog->count(['ui'], 'query timeout'));
    }

    public function test_lookup_ignores_wildcard_like_punctuation_and_limits_page_size(): void
    {
        $catalog = app(QaRuleCatalog::class);

        $this->assertSame([], $catalog->search(query: '%%_'));
        $this->assertSame(0, $catalog->count([]));
        $this->assertCount(100, $catalog->search(limit: PHP_INT_MAX));
        $this->assertNull($catalog->find('not-a-real-rule'));
    }

    public function test_recommendations_prioritize_focus_and_include_other_selected_sectors(): void
    {
        $catalog = app(QaRuleCatalog::class);

        $rules = $catalog->recommend(['reliability', 'database', 'unknown'], 'Review the statement timeout', 4);

        $this->assertCount(4, $rules);
        $this->assertSame('database.query-timeout', $rules[0]['id']);
        $this->assertSame(['database', 'reliability', 'database', 'reliability'], array_column($rules, 'sector'));
        $this->assertSame([], $catalog->recommend(['unknown'], 'Review the statement timeout'));
    }
}
