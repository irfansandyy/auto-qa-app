<?php

namespace App\Services;

class WebsiteReview
{
    private const FRONTEND_CHECKS = [
        'horizontal_overflow' => ['Content overflows horizontally', 'Page content should fit within the tested viewport.', 'Check fixed widths, wide tables, and flex or grid children that cannot shrink.'],
        'missing_viewport' => ['Mobile viewport is not declared', 'The page should declare a viewport suited to the device width.', 'Set a viewport meta tag with width=device-width and preserve browser zoom.'],
        'missing_page_title' => ['Page title is missing', 'The document should have a meaningful title.', 'Set a descriptive title for this page.'],
        'missing_document_language' => ['Document language is missing', 'The document should declare its main language.', 'Set the HTML lang attribute to the language of the page.'],
        'missing_image_alt' => ['Images have no text alternative attribute', 'Images should have an alternative text attribute.', 'Describe meaningful images and use empty alt text for decorative images.'],
        'unlabeled_controls' => ['Controls have no accessible name', 'Interactive controls should have an accessible name.', 'Associate a visible label with each field or provide an accessible name for icon controls.'],
        'unnamed_buttons' => ['Buttons have no accessible name', 'Buttons describe the action they perform.', 'Add action text or a concise accessible name to icon-only controls.'],
        'missing_main_heading' => ['No visible primary heading was found', 'A primary heading identifies the page content.', 'Use a visible heading at level one where the page structure calls for one.'],
        'placeholder_only_controls' => ['Fields rely on placeholder text for their name', 'Users should be able to identify a field after entering a value.', 'Add a persistent, associated label. Reserve placeholder text for an example or short hint.'],
        'low_contrast_sample' => ['Low text contrast in a sampled element', 'Text on a solid background should meet the applicable WCAG contrast ratio.', 'Review the affected foreground and background colors at both viewport sizes. Use at least 4.5:1 for normal text or 3:1 for large text.'],
        'javascript_error' => ['Uncaught browser errors', 'The page should render without uncaught JavaScript errors.', 'Review the browser errors in a local or staging build and add a regression check for the affected behavior.'],
        'failed_resource' => ['Page resources returned HTTP errors', 'Resources required by the page should load successfully.', 'Check the referenced asset paths, deployment output, and server logs.'],
    ];

    private const UI_CHECKS = ['horizontal_overflow', 'missing_viewport', 'javascript_error', 'failed_resource'];

    private const PERFORMANCE_BUDGETS = [
        'response_start_ms' => [800, 'Slow first response in this sample', 'Check application, upstream, and cache latency before optimizing page code.'],
        'dom_content_loaded_ms' => [2500, 'Document preparation was slow in this sample', 'Review render-blocking assets and script work before the document becomes ready.'],
        'resource_count' => [100, 'The browser recorded many page resources', 'Review unused or duplicated assets before changing bundling or caching.'],
        'measured_transfer_bytes' => [1_500_000, 'Large measured resource transfer in this sample', 'Review large images, script bundles, and cache policy. This count excludes unreported transfer sizes.'],
    ];

    private const HEADER_CHECKS = [
        'https' => ['The page was served over HTTP', 'Serve public pages over HTTPS.', 'Configure a valid TLS certificate and redirect public HTTP traffic to HTTPS.'],
        'hsts' => ['HSTS was not observed', 'Public HTTPS responses should use an appropriate HSTS policy.', 'Set Strict-Transport-Security at the HTTPS edge once the site is ready for HTTPS-only access.'],
        'nosniff' => ['MIME sniffing protection was not observed', 'The document should declare X-Content-Type-Options: nosniff.', 'Set the correct Content-Type for each resource and add X-Content-Type-Options: nosniff.'],
        'csp' => ['Content Security Policy was not observed', 'The site should use a Content Security Policy appropriate to the application.', 'Test a policy against legitimate resources before enabling enforcement.'],
        'frame_restriction' => ['Framing policy was not observed', 'The site should explicitly control which sites can frame this page.', 'Set a Content Security Policy frame-ancestors directive that matches the intended embed behavior.'],
    ];

    /** @return array{frontend: array, hardening: array, performance: array, findings: list<array>} */
    public function summarize(array $observation, string $mode, ?string $screenshot, array $sectors = QaSectors::ALL): array
    {
        $viewports = $observation['frontend']['viewports'] ?? null;
        $signals = $observation['hardening'] ?? null;
        $frontend = ['status' => match (true) {
            array_intersect($sectors, ['ui', 'ux']) === [] => 'disabled',
            is_array($viewports) => 'reviewed',
            default => 'unavailable',
        }];
        $hardening = ['status' => match (true) {
            ! in_array('security', $sectors, true) => 'disabled',
            is_array($signals) => 'reviewed',
            default => 'unavailable',
        }];
        $findings = [];
        if ($frontend['status'] === 'reviewed') {
            $frontend['viewports'] = array_intersect_key($viewports, array_flip(['desktop', 'mobile']));
            foreach ($observation['frontend']['findings'] ?? [] as $item) {
                $code = $item['code'] ?? null;
                if (! is_string($code) || ! isset(self::FRONTEND_CHECKS[$code]) || ! is_int($item['count'] ?? null) || $item['count'] < 1) {
                    continue;
                }
                $sector = in_array($code, self::UI_CHECKS, true) ? 'ui' : 'ux';
                if (! in_array($sector, $sectors, true)) {
                    continue;
                }
                $viewport = $item['viewport'] ?? null;
                [$title, $expected, $remediation] = self::FRONTEND_CHECKS[$code];
                $findings[] = ['title' => $title, 'severity' => 'medium', 'status' => 'observed', 'expected' => $expected,
                    'actual' => $code === 'horizontal_overflow' ? $item['count'].' px of horizontal overflow.' : $item['count'].' matching observation(s).',
                    'evidence' => ['category' => 'frontend', 'sector' => $sector, 'code' => $code, 'count' => $item['count'], 'remediation' => $remediation,
                        ...(in_array($viewport, ['desktop', 'mobile'], true) ? ['viewport' => $viewport] : []),
                        ...($screenshot !== null && $viewport !== 'desktop' ? ['artifact' => $screenshot] : [])],
                ];
            }
        }
        if ($hardening['status'] === 'reviewed') {
            $hardening['signals'] = array_intersect_key($signals, self::HEADER_CHECKS);
            foreach (self::HEADER_CHECKS as $code => [$title, $expected, $remediation]) {
                if (($signals[$code] ?? null) !== false || ($code === 'https' && $mode === 'local')
                    || ($code === 'hsts' && ($signals['https'] ?? null) !== true)) {
                    continue;
                }
                $findings[] = ['title' => $title, 'severity' => $code === 'https' ? 'medium' : 'low', 'status' => 'recommendation', 'expected' => $expected,
                    'actual' => 'The starting document response did not show this setting.',
                    'evidence' => ['category' => 'hardening', 'sector' => 'security', 'code' => $code, 'remediation' => $remediation],
                ];
            }
        }

        $performance = ['status' => 'disabled'];
        if (in_array('performance', $sectors, true)) {
            $metrics = [];
            foreach (self::PERFORMANCE_BUDGETS as $code => [$budget, $title, $remediation]) {
                $value = $observation['performance'][$code] ?? null;
                if (! is_int($value) || $value < 0 || $value > 1_000_000_000) {
                    continue;
                }
                $metrics[$code] = $value;
                if ($value > $budget) {
                    $unit = str_ends_with($code, '_ms') ? ' ms' : (str_ends_with($code, '_bytes') ? ' bytes' : '');
                    $findings[] = ['title' => $title, 'severity' => 'medium', 'status' => 'recommendation',
                        'expected' => 'This diagnostic uses a sample budget of '.$budget.$unit.'.',
                        'actual' => 'The browser measured '.$value.$unit.' during this page load.',
                        'evidence' => ['category' => 'performance', 'sector' => 'performance', 'code' => $code, 'remediation' => $remediation]];
                }
            }
            $performance = ['status' => $metrics === [] ? 'unavailable' : (count($metrics) < count(self::PERFORMANCE_BUDGETS) ? 'partial' : 'reviewed'), 'metrics' => $metrics];
        }

        return ['frontend' => $frontend, 'hardening' => $hardening, 'performance' => $performance, 'findings' => $findings];
    }
}
