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
        'javascript_error' => ['Uncaught browser errors', 'The page should render without uncaught JavaScript errors.', 'Review the browser errors in a local or staging build and add a regression check for the affected behavior.'],
        'failed_resource' => ['Page resources returned HTTP errors', 'Resources required by the page should load successfully.', 'Check the referenced asset paths, deployment output, and server logs.'],
    ];

    private const HEADER_CHECKS = [
        'https' => ['The page was served over HTTP', 'Serve public pages over HTTPS.', 'Configure a valid TLS certificate and redirect public HTTP traffic to HTTPS.'],
        'hsts' => ['HSTS was not observed', 'Public HTTPS responses should use an appropriate HSTS policy.', 'Set Strict-Transport-Security at the HTTPS edge once the site is ready for HTTPS-only access.'],
        'nosniff' => ['MIME sniffing protection was not observed', 'The document should declare X-Content-Type-Options: nosniff.', 'Set the correct Content-Type for each resource and add X-Content-Type-Options: nosniff.'],
        'csp' => ['Content Security Policy was not observed', 'The site should use a Content Security Policy appropriate to the application.', 'Test a policy against legitimate resources before enabling enforcement.'],
        'frame_restriction' => ['Framing policy was not observed', 'The site should explicitly control which sites can frame this page.', 'Set a Content Security Policy frame-ancestors directive that matches the intended embed behavior.'],
    ];

    /** @return array{frontend: array, hardening: array, findings: list<array>} */
    public function summarize(array $observation, string $mode, ?string $screenshot): array
    {
        $viewports = $observation['frontend']['viewports'] ?? null;
        $signals = $observation['hardening'] ?? null;
        $frontend = ['status' => is_array($viewports) ? 'reviewed' : 'unavailable'];
        $hardening = ['status' => is_array($signals) ? 'reviewed' : 'unavailable'];
        $findings = [];
        if (is_array($viewports)) {
            $frontend['viewports'] = array_intersect_key($viewports, array_flip(['desktop', 'mobile']));
            foreach ($observation['frontend']['findings'] ?? [] as $item) {
                $code = $item['code'] ?? null;
                if (! is_string($code) || ! isset(self::FRONTEND_CHECKS[$code]) || ! is_int($item['count'] ?? null) || $item['count'] < 1) {
                    continue;
                }
                $viewport = $item['viewport'] ?? null;
                [$title, $expected, $remediation] = self::FRONTEND_CHECKS[$code];
                $findings[] = ['title' => $title, 'severity' => 'medium', 'status' => 'observed', 'expected' => $expected,
                    'actual' => $code === 'horizontal_overflow' ? $item['count'].' px of horizontal overflow.' : $item['count'].' matching observation(s).',
                    'evidence' => ['category' => 'frontend', 'code' => $code, 'count' => $item['count'], 'remediation' => $remediation,
                        ...(in_array($viewport, ['desktop', 'mobile'], true) ? ['viewport' => $viewport] : []),
                        ...($screenshot !== null && $viewport !== 'desktop' ? ['artifact' => $screenshot] : [])],
                ];
            }
        }
        if (is_array($signals)) {
            $hardening['signals'] = array_intersect_key($signals, self::HEADER_CHECKS);
            foreach (self::HEADER_CHECKS as $code => [$title, $expected, $remediation]) {
                if (($signals[$code] ?? null) !== false || ($code === 'https' && $mode === 'local')
                    || ($code === 'hsts' && ($signals['https'] ?? null) !== true)) {
                    continue;
                }
                $findings[] = ['title' => $title, 'severity' => $code === 'https' ? 'medium' : 'low', 'status' => 'recommendation', 'expected' => $expected,
                    'actual' => 'The starting document response did not show this setting.',
                    'evidence' => ['category' => 'hardening', 'code' => $code, 'remediation' => $remediation],
                ];
            }
        }

        return ['frontend' => $frontend, 'hardening' => $hardening, 'findings' => $findings];
    }
}
