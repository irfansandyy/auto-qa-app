import { randomUUID } from 'node:crypto';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { resolve as resolvePath, join } from 'node:path';
import { inspectHardening, measurePerformance, measureViewport, narrowViewport, summarizeFrontEnd } from './frontend.mjs';
import { PolicyError, assertSameOrigin, validateAction, redactUrl, readOnlyLink } from './policy.mjs';

const actionTimeout = 12000;
const maxLogs = 120;
const maxPreviews = 26;
const countedResources = new Set(['stylesheet', 'image', 'media', 'font', 'script', 'xhr', 'fetch', 'manifest']);
const passiveActions = new Set(['navigate', 'assert_page', 'assert_text', 'assert_url', 'inspect_frontend', 'inspect_qa', 'screenshot', 'finish']);
const passiveMethods = new Set(['GET', 'HEAD', 'OPTIONS']);
const privateScreenshotStyle = [
  'input:not([type="submit"]):not([type="button"]):not([type="reset"]), select, textarea, [contenteditable] { color: transparent !important; -webkit-text-fill-color: transparent !important; text-shadow: none !important; caret-color: transparent !important; }',
  '[data-autoqa-private], [data-private] { visibility: hidden !important; }',
].join('\n');

export class BrowserManager {
  constructor({ chromium, policy, evidenceDir, idleTimeout = 20 * 60 * 1000 }) {
    this.chromium = chromium;
    this.policy = policy;
    this.evidenceDir = resolvePath(evidenceDir);
    this.sessions = new Map();
    this.browser = null;
    this.creating = false;
    this.idleTimeout = idleTimeout;
    this.cleanupTimer = setInterval(() => this.expire().catch(() => {}), 30000);
    this.cleanupTimer.unref();
  }

  async health() {
    let installed = true;
    try { await access(process.env.PLAYWRIGHT_EXECUTABLE_PATH ?? this.chromium.executablePath()); } catch { installed = false; }
    return { status: 'ok', ready: installed, browser_installed: installed, active_sessions: this.sessions.size, max_sessions: 1, local_targets: [...this.policy.localTargets] };
  }

  session(id) {
    const session = this.sessions.get(id);
    if (!session) throw new PolicyError('The browser session was not found or has expired.', 404);
    return session;
  }

  redact(session, value) {
    let result = String(value);
    for (const secret of session.secrets) if (secret) result = result.replaceAll(secret, '[redacted]');
    return result;
  }

  log(session, kind, value) {
    session[kind].push({ sequence: ++session.logSequences[kind], value });
    if (session[kind].length > maxLogs) session[kind].shift();
  }

  async withSession(id, callback) {
    const session = this.session(id);
    if (session.busy) throw new PolicyError('The browser session is already processing an operation.', 409);
    session.busy = true;
    session.lastUsed = Date.now();
    try { return await callback(session); } finally { session.busy = false; session.lastUsed = Date.now(); }
  }

  async start({ url, mode = 'public', read_only: readOnly = false }) {
    if (this.creating || this.sessions.size >= 1) throw new PolicyError('The browser worker supports one session at a time. Close the current session first.', 409);
    this.creating = true;
    let context;
    let session;
    try {
      const target = await this.policy.check(url, mode);
      await mkdir(this.evidenceDir, { recursive: true, mode: 0o700 });
      this.browser ??= await this.chromium.launch({
        headless: true,
        chromiumSandbox: process.env.BROWSER_CHROMIUM_SANDBOX === 'true',
        args: ['--disable-dev-shm-usage', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'],
        ...(process.env.PLAYWRIGHT_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH } : {}),
      });
      const viewport = readOnly ? { width: 1152, height: 768 } : { width: 1440, height: 960 };
      context = await this.browser.newContext({ viewport, acceptDownloads: false, serviceWorkers: 'block' });
      context.setDefaultTimeout(actionTimeout);
      context.setDefaultNavigationTimeout(30000);
      const page = await context.newPage();
      session = {
        id: randomUUID(), page, context, origin: target.origin, mode, readOnly,
        refs: new Set(), links: new Set(), epoch: 0, screenshots: 0, previews: 0, artifacts: [], secrets: new Set(),
        network_errors: [], console_errors: [], logSequences: { network_errors: 0, console_errors: 0 }, lastUsed: Date.now(), busy: false,
        finished: false, tracing: true, sourceSha: null, documentStatus: null, documentUrl: null, documentResponse: null,
        javascriptErrors: 0, failedResources: 0,
      };
      await context.tracing.start({ screenshots: !readOnly, snapshots: !readOnly });
      await context.route('**/*', async (route) => {
        const request = route.request();
        try {
          if (session.readOnly && !passiveMethods.has(request.method())) throw new PolicyError('This request was skipped during a read-only check.');
          await this.policy.check(request.url(), mode);
          if (request.isNavigationRequest()) assertSameOrigin(request.url(), session.origin);
          await route.continue();
        } catch (error) {
          this.log(session, 'network_errors', { url: redactUrl(request.url()), error: error instanceof PolicyError ? error.message : 'Network policy rejected the request.', blocked: true });
          await route.abort('blockedbyclient').catch(() => {});
        }
      });
      // WebSockets are a separate Playwright interception path. They are disabled
      // for the initial QA scope rather than bypassing HTTP destination checks.
      await context.routeWebSocket('**/*', (socket) => socket.close());
      context.on('page', (popup) => { if (popup !== page) popup.close().catch(() => {}); });
      page.on('download', (download) => download.cancel().catch(() => {}));
      page.on('console', (message) => {
        if (message.type() === 'error') this.log(session, 'console_errors', this.redact(session, message.text()).slice(0, 1000));
      });
      page.on('pageerror', (error) => {
        if (session.readOnly) session.javascriptErrors = Math.min(maxLogs, session.javascriptErrors + 1);
        this.log(session, 'console_errors', this.redact(session, error.message).slice(0, 1000));
      });
      page.on('response', async (response) => {
        let mainDocument = false;
        try {
          const request = response.request();
          const responseUrl = new URL(response.url());
          if (request.isNavigationRequest() && request.frame() === page.mainFrame() && responseUrl.origin === session.origin) {
            mainDocument = true;
            session.documentStatus = response.status();
            session.documentUrl = response.url();
          } else if (session.readOnly && response.status() >= 400 && response.status() < 600
            && !request.isNavigationRequest() && request.frame() === page.mainFrame() && !request.redirectedFrom()
            && responseUrl.origin === session.origin && responseUrl.pathname !== '/favicon.ico'
            && countedResources.has(request.resourceType())) {
            session.failedResources = Math.min(maxLogs, session.failedResources + 1);
          }
        } catch { /* A detached frame has no useful source identity. */ }
        if (response.status() >= 400) this.log(session, 'network_errors', {
          url: redactUrl(response.url()),
          status: response.status(),
          ...(session.readOnly ? { main_document: mainDocument } : {}),
        });
        if (mainDocument) {
          try {
            const sha = await response.headerValue('x-autoqa-commit');
            if (sha && /^[a-f0-9]{40,64}$/i.test(sha)) session.sourceSha = sha.toLowerCase();
          } catch { /* A detached frame has no useful source identity. */ }
        }
      });
      page.on('requestfailed', (request) => this.log(session, 'network_errors', { url: redactUrl(request.url()), error: request.failure()?.errorText ?? 'Request failed.' }));
      this.sessions.set(session.id, session);
      await this.navigate(session, target.href);
      const snapshot = await this.observe(session);
      const preview = await this.preview(session);
      return {
        session_id: session.id,
        snapshot,
        ...(preview ? { preview_artifact: preview } : {}),
        network_errors: session.network_errors.map(({ value }) => value),
        console_errors: session.console_errors.map(({ value }) => value),
      };
    } catch (error) {
      if (session) this.sessions.delete(session.id);
      if (context) await context.close().catch(() => {});
      throw error;
    } finally { this.creating = false; }
  }

  async navigate(session, url) {
    session.javascriptErrors = 0;
    session.failedResources = 0;
    const response = await session.page.goto(url, { waitUntil: 'domcontentloaded' });
    if (response) {
      session.documentStatus = response.status();
      session.documentUrl = response.url();
      if (session.readOnly) session.documentResponse = response;
    }
  }

  async inspectFrontend(session, sectors = null) {
    const { page } = session;
    const pageUrl = page.url();
    const ui = sectors === null || sectors.includes('ui');
    const ux = sectors === null || sectors.includes('ux');
    const security = sectors === null || sectors.includes('security');
    await page.waitForLoadState('load', { timeout: 2000 }).catch(() => {});
    const hardening = security ? await inspectHardening(session.documentResponse, pageUrl).catch(() => null) : null;
    const performance = sectors?.includes('performance') ? await measurePerformance(page).catch(() => null) : null;

    let frontend;
    let preview;
    if (ui || ux) {
      const desktopViewport = page.viewportSize();
      const options = { ui, ux, extended: sectors !== null };
      const desktop = await measureViewport(page, options);
      let mobile;
      try {
        await page.setViewportSize(narrowViewport);
        mobile = await measureViewport(page, options);
        if (page.url() !== pageUrl) throw new PolicyError('The page navigated during the responsive check.', 409);
        preview = await this.preview(session);
      } finally {
        await page.setViewportSize(desktopViewport);
      }
      frontend = summarizeFrontEnd(desktop, mobile);
      if (ui && session.javascriptErrors > 0) frontend.findings.push({ code: 'javascript_error', count: session.javascriptErrors });
      if (ui && session.failedResources > 0) frontend.findings.push({ code: 'failed_resource', count: session.failedResources });
    }
    if (page.url() !== pageUrl) throw new PolicyError('The page navigated during the browser review.', 409);

    return {
      ...(frontend ? { frontend } : {}),
      ...(hardening ? { hardening } : {}),
      ...(performance ? { performance } : {}),
      ...(preview ? { preview_artifact: preview } : {}),
    };
  }

  async observe(session) {
    assertSameOrigin(session.page.url(), session.origin);
    await this.policy.check(session.page.url(), session.mode);
    const epoch = ++session.epoch;
    const snapshot = await session.page.evaluate(({ epoch, readOnly }) => {
      const short = (value, limit) => String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
      const hasValue = (element) => {
        if (['checkbox', 'radio'].includes(element.type)) return Boolean(element.checked);
        if (element.type === 'file') return Boolean(element.files?.length);
        if (element.tagName === 'SELECT') return [...element.selectedOptions].some((option) => option.value !== '');
        return String(element.value ?? '').length > 0;
      };
      for (const element of document.querySelectorAll('[data-autoqa-ref]')) element.removeAttribute('data-autoqa-ref');
      const elements = [];
      const candidates = document.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,[role="button"],[role="link"],[role="checkbox"],[role="radio"],[role="combobox"],[contenteditable="true"]');
      for (const element of candidates) {
        if (elements.length >= 80) break;
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        if (!rect.width || !rect.height || style.visibility === 'hidden' || style.display === 'none') continue;
        const id = `e${epoch}-${elements.length + 1}`;
        element.setAttribute('data-autoqa-ref', id);
        const labels = element.labels ? [...element.labels].map((label) => label.innerText).join(' ') : '';
        const labelledBy = (element.getAttribute('aria-labelledby') ?? '').split(/\s+/).map((id) => document.getElementById(id)?.innerText ?? '').join(' ');
        elements.push({
          id, tag: element.tagName.toLowerCase(), role: element.getAttribute('role') ?? '',
          label: short(element.getAttribute('aria-label') || labelledBy || labels || element.getAttribute('placeholder') || element.getAttribute('title'), 160),
          text: short(element.innerText, 200), type: element.getAttribute('type') ?? '',
          disabled: Boolean(element.disabled || element.getAttribute('aria-disabled') === 'true'),
          ...(['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) ? {
            required: Boolean(element.required || element.getAttribute('aria-required') === 'true'),
            has_value: hasValue(element),
          } : {}),
          ...(element.tagName === 'SELECT' ? { options: [...element.options].slice(0, 25).map((option) => ({ value: short(option.value, 160), text: short(option.text, 160) })) } : {}),
          ...(readOnly && element.tagName === 'A' && !element.hasAttribute('download') && !element.closest('form, [hidden], [inert], [aria-hidden="true"]')
            ? { href_candidate: element.href }
            : {}),
        });
      }
      const body = document.body;
      const text = short(body?.innerText, 4000);
      const hasVisibleContent = () => {
        if (!body?.checkVisibility({ opacityProperty: true, visibilityProperty: true })) return false;
        return Boolean(text) || [...document.images].some((image) => {
          const { width, height } = image.getBoundingClientRect();
          return image.naturalWidth > 0 && width > 0 && height > 0
            && image.checkVisibility({ opacityProperty: true, visibilityProperty: true });
        });
      };
      return {
        url: location.href, title: document.title.slice(0, 200), text, elements,
        ...(readOnly ? { has_visible_content: hasVisibleContent() } : {}),
      };
    }, { epoch, readOnly: session.readOnly });
    session.refs = new Set(snapshot.elements.map((element) => element.id));
    session.links = new Set();
    if (session.readOnly) {
      const documentUrl = new URL(snapshot.url);
      documentUrl.hash = '';
      snapshot.document_status = documentUrl.href === session.documentUrl ? session.documentStatus : null;
    }
    snapshot.url = redactUrl(snapshot.url);
    snapshot.title = this.redact(session, snapshot.title);
    snapshot.text = this.redact(session, snapshot.text);
    for (const element of snapshot.elements) {
      element.label = this.redact(session, element.label);
      element.text = this.redact(session, element.text);
      if (session.readOnly && element.href_candidate) {
        const url = readOnlyLink(element.href_candidate, element.label + ' ' + element.text, session.origin);
        if (url && !element.disabled) {
          element.read_only_url = url;
          session.links.add(url);
        }
      }
      delete element.href_candidate;
    }
    if (session.sourceSha) snapshot.source_sha = session.sourceSha;
    return snapshot;
  }

  async snapshot(id) {
    return this.withSession(id, (session) => this.observe(session));
  }

  async screenshot(session) {
    assertSameOrigin(session.page.url(), session.origin);
    await this.policy.check(session.page.url(), session.mode);
    if (session.screenshots >= 20) throw new PolicyError('This session has reached the 20-screenshot limit.');
    const filename = `browser-${session.id}-${++session.screenshots}.png`;
    await this.capture(session, filename);
    session.artifacts.push({ type: 'screenshot', filename });
    return filename;
  }

  async preview(session) {
    if (!session.readOnly || session.previews >= maxPreviews) return null;
    const filename = `browser-${session.id}-preview-${++session.previews}.png`;
    try {
      assertSameOrigin(session.page.url(), session.origin);
      await this.policy.check(session.page.url(), session.mode);
      await this.capture(session, filename);
      session.artifacts.push({ type: 'preview', filename });
      return filename;
    } catch {
      return null;
    }
  }

  async capture(session, filename) {
    const path = join(this.evidenceDir, filename);
    if (!session.readOnly) {
      await session.page.screenshot({ path, fullPage: false, animations: 'disabled' });
      return;
    }
    const url = session.page.url();
    const bytes = await session.page.screenshot({ fullPage: false, timeout: 4000, style: privateScreenshotStyle });
    if (session.page.url() !== url) throw new PolicyError('The page moved before the screenshot was ready.');
    await writeFile(path, bytes, { flag: 'wx', mode: 0o600 });
  }

  async stopTrace(session) {
    if (!session.tracing) return;
    const filename = `browser-${session.id}-trace.zip`;
    await session.context.tracing.stop({ path: join(this.evidenceDir, filename) });
    session.tracing = false;
    session.artifacts.push({ type: 'trace', filename });
  }

  async act(id, input) {
    return this.withSession(id, async (session) => {
      if (session.finished) throw new PolicyError('This browser session has finished. Start a new session for more actions.', 409);
      assertSameOrigin(session.page.url(), session.origin);
      await this.policy.check(session.page.url(), session.mode);
      const action = validateAction(input, session.refs);
      if (session.readOnly && !passiveActions.has(action.tool)) throw new PolicyError('This action is unavailable during a read-only check.');
      if (!session.readOnly && ['assert_page', 'inspect_frontend', 'inspect_qa'].includes(action.tool)) throw new PolicyError('Page checks require a read-only session.');
      const expectedPageUrl = action.tool === 'assert_page' && action.url !== undefined
        ? assertSameOrigin(action.url, session.origin).href
        : null;
      const locator = action.id ? session.page.locator(`[data-autoqa-ref="${action.id}"]`) : null;
      const logStart = { network: session.logSequences.network_errors, console: session.logSequences.console_errors };
      let assertion;
      let artifact;
      let frontendInspection;
      if (action.tool === 'navigate') {
        const url = assertSameOrigin(action.url, session.origin);
        if (session.readOnly && !session.links.has(url.href)) throw new PolicyError('Read-only checks can only follow a visible page link from the latest snapshot.');
        await this.policy.check(url.href, session.mode);
        await this.navigate(session, url.href);
      } else if (action.tool === 'click') await locator.click();
      else if (action.tool === 'fill') {
        if (await locator.getAttribute('type') === 'password') session.secrets.add(action.value);
        await locator.fill(action.value);
      } else if (action.tool === 'select') await locator.selectOption(action.value);
      else if (action.tool === 'press') {
        if (locator) await locator.press(action.key);
        else await session.page.keyboard.press(action.key);
      }
      else if (action.tool === 'assert_text') {
        await session.page.waitForFunction((text) => document.body?.innerText.includes(text), action.text, { timeout: 3000 }).catch(() => {});
        const text = await session.page.locator('body').innerText();
        assertion = { type: 'text', expected: this.redact(session, action.text), passed: text.includes(action.text) };
      } else if (action.tool === 'assert_url') {
        const expected = assertSameOrigin(action.url, session.origin);
        await session.page.waitForURL(expected.href, { timeout: 3000 }).catch(() => {});
        assertion = { type: 'url', expected: redactUrl(expected.href), passed: session.page.url() === expected.href };
      } else if (['inspect_frontend', 'inspect_qa'].includes(action.tool)) {
        frontendInspection = await this.inspectFrontend(session, action.sectors ?? null);
      } else if (action.tool === 'screenshot' || action.tool === 'finish') {
        artifact = session.readOnly ? await this.preview(session) : await this.screenshot(session);
      }
      if (action.tool === 'finish') { await this.stopTrace(session); session.finished = true; }
      const snapshot = await this.observe(session);
      if (action.tool === 'assert_page') {
        const matchesUrl = expectedPageUrl === null || session.page.url() === expectedPageUrl;
        assertion = {
          type: 'page',
          expected: 'The page responds successfully and renders content.',
          passed: matchesUrl && snapshot.document_status >= 200 && snapshot.document_status < 300 && snapshot.has_visible_content,
        };
      }
      const preview = frontendInspection ? null : action.tool === 'screenshot' || action.tool === 'finish' ? artifact : await this.preview(session);
      if (assertion && !assertion.passed) artifact = session.readOnly ? preview : await this.screenshot(session);
      return {
        snapshot,
        network_errors: session.network_errors.filter(({ sequence }) => sequence > logStart.network).map(({ value: error }) => ({ ...error, url: this.redact(session, error.url) })),
        console_errors: session.console_errors.filter(({ sequence }) => sequence > logStart.console).map(({ value: error }) => this.redact(session, error)),
        ...(assertion ? { assertion } : {}),
        ...(artifact ? { artifact } : {}),
        ...(preview ? { preview_artifact: preview } : {}),
        ...frontendInspection,
        ...(action.tool === 'finish' ? { artifacts: session.artifacts, finished: true } : {}),
      };
    });
  }

  async close(id) {
    return this.withSession(id, async (session) => {
      await this.stopTrace(session);
      await session.context.close();
      this.sessions.delete(id);
      return { closed: true, artifacts: session.artifacts };
    });
  }

  async expire() {
    for (const [id, session] of this.sessions) {
      if (!session.busy && Date.now() - session.lastUsed > this.idleTimeout) await this.close(id).catch(() => {});
    }
  }

  async shutdown() {
    clearInterval(this.cleanupTimer);
    for (const [id, session] of this.sessions) {
      await this.stopTrace(session).catch(() => {});
      await session.context.close().catch(() => {});
      this.sessions.delete(id);
    }
    await this.browser?.close();
  }
}
