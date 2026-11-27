export const narrowViewport = { width: 390, height: 844 };

export async function measureViewport(page, { ui = true, ux = true, extended = false } = {}) {
  return page.evaluate(({ ui, ux, extended }) => {
    const root = document.documentElement;
    const scrollingElement = document.scrollingElement ?? root;
    const isVisible = (element) => {
      const { width, height } = element.getBoundingClientRect();
      return width > 0 && height > 0 && !element.closest('[inert], [aria-hidden="true"]')
        && element.checkVisibility({ opacityProperty: true, visibilityProperty: true });
    };
    const hasText = (value) => Boolean(value?.trim());
    const hasLabel = (element, acceptPlaceholder = true) => {
      const linkedLabels = (element.getAttribute('aria-labelledby') ?? '').trim().split(/\s+/);
      return hasText(element.getAttribute('aria-label')) || hasText(element.getAttribute('title'))
        || (acceptPlaceholder && hasText(element.getAttribute('placeholder')))
        || linkedLabels.some((id) => id && hasText(document.getElementById(id)?.textContent))
        || Array.from(element.labels ?? []).some((label) => hasText(label.textContent));
    };
    const fields = ux ? Array.from(document.querySelectorAll('textarea, select, input:not([type="hidden" i], [type="button" i], [type="submit" i], [type="reset" i], [type="image" i])')) : [];
    const rgb = (color) => {
      const match = /^rgba?\((\d+(?:\.\d+)?),\s*(\d+(?:\.\d+)?),\s*(\d+(?:\.\d+)?)(?:,\s*([\d.]+))?\)$/.exec(color);
      return match ? [Number(match[1]), Number(match[2]), Number(match[3]), Number(match[4] ?? 1)] : null;
    };
    const luminance = (color) => [0.2126, 0.7152, 0.0722].reduce((total, weight, index) => {
      const component = color[index] / 255;
      return total + weight * (component <= 0.04045 ? component / 12.92 : ((component + 0.055) / 1.055) ** 2.4);
    }, 0);
    const hasLowContrast = (element) => {
      if (!isVisible(element) || ![...element.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && hasText(node.textContent))) return false;
      const style = getComputedStyle(element);
      const foreground = rgb(style.color);
      if (!foreground || foreground[3] !== 1) return false;

      let fill;
      let layer = element;
      for (let depth = 0; layer && depth < 16; depth += 1, layer = layer.parentElement) {
        const background = getComputedStyle(layer);
        if (background.opacity !== '1' || background.mixBlendMode !== 'normal' || background.filter !== 'none') return false;
        if (fill) continue;
        if (background.backgroundImage !== 'none') return false;
        const color = rgb(background.backgroundColor);
        if (!color || (color[3] > 0 && color[3] < 1)) return false;
        if (color[3] === 1) fill = color;
      }
      if (!fill || layer) return false;

      const [dark, light] = [luminance(foreground), luminance(fill)].sort((first, second) => first - second);
      const fontSize = parseFloat(style.fontSize);
      const large = fontSize >= 24 || (fontSize >= 18.67 && Number(style.fontWeight) >= 700);
      return (light + 0.05) / (dark + 0.05) < (large ? 3 : 4.5);
    };
    return {
      width: window.innerWidth,
      height: window.innerHeight,
      ...(ui ? {
        horizontal_overflow_px: Math.max(0, scrollingElement.scrollWidth - root.clientWidth),
        missing_viewport: !Array.from(document.querySelectorAll('meta[name="viewport" i]')).some((element) => hasText(element.content)),
      } : {}),
      ...(ux ? {
        missing_page_title: !hasText(document.title),
        missing_document_language: !hasText(root.lang),
        missing_image_alt: Array.from(document.querySelectorAll('img:not([alt], [role="presentation"], [role="none"])'))
          .filter((image) => isVisible(image) && !hasLabel(image)).length,
        unlabeled_controls: fields.filter((field) => isVisible(field) && !hasLabel(field)).length,
        ...(extended ? {
          unnamed_buttons: Array.from(document.querySelectorAll('button, [role="button"]'))
            .filter((button) => isVisible(button) && !hasLabel(button) && !hasText(button.innerText)
              && !button.querySelector('img[alt]:not([alt=""])')).length,
          missing_main_heading: !Array.from(document.querySelectorAll('h1, [role="heading"][aria-level="1"]'))
            .some((heading) => isVisible(heading) && (hasText(heading.innerText) || hasLabel(heading))),
          placeholder_only_controls: fields.filter((field) => isVisible(field) && hasText(field.getAttribute('placeholder')) && !hasLabel(field, false)).length,
          low_contrast_sample: Array.from(document.querySelectorAll('p, a, label, button, h1, h2, h3, h4, li, th, td'))
            .slice(0, 100).filter(hasLowContrast).length,
        } : {}),
      } : {}),
    };
  }, { ui, ux, extended });
}

export function summarizeFrontEnd(desktop, mobile) {
  const checks = ['missing_viewport', 'missing_page_title', 'missing_document_language', 'missing_image_alt', 'unlabeled_controls', 'unnamed_buttons', 'missing_main_heading', 'placeholder_only_controls', 'low_contrast_sample'];
  const findings = checks.flatMap((code) => {
    const count = Math.max(desktop[code] ?? 0, mobile[code] ?? 0);
    return count ? [{ code, count }] : [];
  });
  const viewports = Object.fromEntries(Object.entries({ desktop, mobile }).map(([viewport, measurement]) => {
    const { width, height, horizontal_overflow_px } = measurement;
    if (horizontal_overflow_px > 1) findings.push({ code: 'horizontal_overflow', count: horizontal_overflow_px, viewport });
    return [viewport, { width, height, horizontal_overflow_px }];
  }));

  return { viewports, findings };
}

export async function measurePerformance(page) {
  return page.evaluate(() => {
    const navigation = performance.getEntriesByType('navigation')[0];
    if (!navigation) return null;
    const resources = performance.getEntriesByType('resource').slice(0, 400);
    const milliseconds = (value) => Number.isFinite(value) && value >= 0 ? Math.round(value) : null;
    return {
      response_start_ms: navigation.responseStart > 0 ? milliseconds(navigation.responseStart) : null,
      dom_content_loaded_ms: navigation.domContentLoadedEventEnd > 0 ? milliseconds(navigation.domContentLoadedEventEnd) : null,
      resource_count: resources.length,
      measured_transfer_bytes: resources.reduce((total, resource) => total + (Number.isFinite(resource.transferSize) ? resource.transferSize : 0), 0),
    };
  });
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
