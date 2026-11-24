export const narrowViewport = { width: 390, height: 844 };

export async function measureViewport(page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    const scrollingElement = document.scrollingElement ?? root;
    const isVisible = (element) => {
      const { width, height } = element.getBoundingClientRect();
      return width > 0 && height > 0 && !element.closest('[inert], [aria-hidden="true"]')
        && element.checkVisibility({ opacityProperty: true, visibilityProperty: true });
    };
    const hasText = (value) => Boolean(value?.trim());
    const hasLabel = (element) => {
      const linkedLabels = (element.getAttribute('aria-labelledby') ?? '').trim().split(/\s+/);
      return hasText(element.getAttribute('aria-label')) || hasText(element.getAttribute('title'))
        || hasText(element.getAttribute('placeholder'))
        || linkedLabels.some((id) => id && hasText(document.getElementById(id)?.textContent))
        || Array.from(element.labels ?? []).some((label) => hasText(label.textContent));
    };
    const namelessImages = Array.from(document.querySelectorAll('img:not([alt], [role="presentation"], [role="none"])'))
      .filter((image) => isVisible(image) && !hasLabel(image)).length;
    const formFields = document.querySelectorAll('textarea, select, input:not([type="hidden" i], [type="button" i], [type="submit" i], [type="reset" i], [type="image" i])');
    const unnamedFields = Array.from(formFields).filter((field) => isVisible(field) && !hasLabel(field)).length;

    return {
      width: window.innerWidth,
      height: window.innerHeight,
      horizontal_overflow_px: Math.max(0, scrollingElement.scrollWidth - root.clientWidth),
      missing_viewport: !Array.from(document.querySelectorAll('meta[name="viewport" i]')).some((element) => hasText(element.content)),
      missing_page_title: !hasText(document.title),
      missing_document_language: !hasText(root.lang),
      missing_image_alt: namelessImages,
      unlabeled_controls: unnamedFields,
    };
  });
}

export function summarizeFrontEnd(desktop, mobile) {
  const checks = ['missing_viewport', 'missing_page_title', 'missing_document_language', 'missing_image_alt', 'unlabeled_controls'];
  const findings = checks.flatMap((code) => {
    const count = Math.max(desktop[code], mobile[code]);
    return count ? [{ code, count }] : [];
  });
  const viewports = Object.fromEntries(Object.entries({ desktop, mobile }).map(([viewport, measurement]) => {
    const { width, height, horizontal_overflow_px } = measurement;
    if (horizontal_overflow_px > 1) findings.push({ code: 'horizontal_overflow', count: horizontal_overflow_px, viewport });
    return [viewport, { width, height, horizontal_overflow_px }];
  }));

  return { viewports, findings };
}

export async function inspectHardening(response, pageUrl) {
  const expected = new URL(pageUrl);
  expected.hash = '';
  if (!response || response.url() !== expected.href) return null;

  const [hsts, nosniff, csp, frameOptions] = await Promise.all([
    response.headerValue('strict-transport-security'),
    response.headerValue('x-content-type-options'),
    response.headerValue('content-security-policy'),
    response.headerValue('x-frame-options'),
  ]);
  const https = expected.protocol === 'https:';
  const maxAge = /(?:^|;)\s*max-age\s*=\s*(?:"(\d+)"|(\d+))\s*(?:;|$)/i.exec(hsts ?? '');
  const restrictsAncestors = (csp ?? '').split(/[;,]/).some((directive) => {
    const [name, ...sources] = directive.toLowerCase().trim().split(/\s+/);
    return name === 'frame-ancestors' && sources.length > 0 && !sources.includes('*');
  });

  return {
    https,
    hsts: https && Boolean(maxAge && /[1-9]/.test(maxAge[1] ?? maxAge[2])),
    nosniff: nosniff?.trim().toLowerCase() === 'nosniff',
    csp: Boolean(csp?.trim()),
    frame_restriction: restrictsAncestors || /^(?:deny|sameorigin)$/i.test(frameOptions?.trim() ?? ''),
  };
}
