import { randomUUID } from 'node:crypto';
import { access, mkdir } from 'node:fs/promises';
import { resolve as resolvePath, join } from 'node:path';
import { PolicyError, assertSameOrigin, validateAction, redactUrl } from './policy.mjs';

const actionTimeout = 12000;
const maxLogs = 120;

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

  async start({ url, mode = 'public' }) {
    if (this.creating || this.sessions.size >= 1) throw new PolicyError('The browser worker supports one session at a time. Close the current session first.', 409);
    this.creating = true;
    let context;
    let session;
    try {
      const target = await this.policy.check(url, mode);
      await mkdir(this.evidenceDir, { recursive: true, mode: 0o700 });
      this.browser ??= await this.chromium.launch({
        headless: true,
        args: ['--disable-dev-shm-usage', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'],
        ...(process.env.PLAYWRIGHT_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH } : {}),
      });
      context = await this.browser.newContext({ viewport: { width: 1440, height: 960 }, acceptDownloads: false, serviceWorkers: 'block' });
      context.setDefaultTimeout(actionTimeout);
      context.setDefaultNavigationTimeout(30000);
      const page = await context.newPage();
      session = {
        id: randomUUID(), page, context, origin: target.origin, mode,
        refs: new Set(), epoch: 0, screenshots: 0, artifacts: [], secrets: new Set(),
        network_errors: [], console_errors: [], logSequences: { network_errors: 0, console_errors: 0 }, lastUsed: Date.now(), busy: false,
        finished: false, tracing: true, sourceSha: null,
      };
      await context.tracing.start({ screenshots: true, snapshots: true });
      await context.route('**/*', async (route) => {
        const request = route.request();
        try {
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
      page.on('pageerror', (error) => this.log(session, 'console_errors', this.redact(session, error.message).slice(0, 1000)));
      page.on('response', async (response) => {
        if (response.status() >= 400) this.log(session, 'network_errors', { url: redactUrl(response.url()), status: response.status() });
        try {
          const request = response.request();
          if (request.isNavigationRequest() && request.frame() === page.mainFrame() && new URL(response.url()).origin === session.origin) {
            const sha = await response.headerValue('x-autoqa-commit');
            if (sha && /^[a-f0-9]{40,64}$/i.test(sha)) session.sourceSha = sha.toLowerCase();
          }
        } catch { /* A detached frame has no useful source identity. */ }
      });
      page.on('requestfailed', (request) => this.log(session, 'network_errors', { url: redactUrl(request.url()), error: request.failure()?.errorText ?? 'Request failed.' }));
      this.sessions.set(session.id, session);
      await page.goto(target.href, { waitUntil: 'domcontentloaded' });
      return {
        session_id: session.id,
        snapshot: await this.observe(session),
        network_errors: session.network_errors.map(({ value }) => value),
        console_errors: session.console_errors.map(({ value }) => value),
      };
    } catch (error) {
      if (session) this.sessions.delete(session.id);
      if (context) await context.close().catch(() => {});
      throw error;
    } finally { this.creating = false; }
  }

  async observe(session) {
    assertSameOrigin(session.page.url(), session.origin);
    await this.policy.check(session.page.url(), session.mode);
    const epoch = ++session.epoch;
    const snapshot = await session.page.evaluate(({ epoch }) => {
      const short = (value, limit) => String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
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
          ...(element.tagName === 'SELECT' ? { options: [...element.options].slice(0, 25).map((option) => ({ value: short(option.value, 160), text: short(option.text, 160) })) } : {}),
        });
      }
      return { url: location.href, title: document.title.slice(0, 200), text: short(document.body?.innerText, 4000), elements };
    }, { epoch });
    session.refs = new Set(snapshot.elements.map((element) => element.id));
    snapshot.url = redactUrl(snapshot.url);
    snapshot.title = this.redact(session, snapshot.title);
    snapshot.text = this.redact(session, snapshot.text);
    for (const element of snapshot.elements) {
      element.label = this.redact(session, element.label);
      element.text = this.redact(session, element.text);
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
    await session.page.screenshot({ path: join(this.evidenceDir, filename), fullPage: false, animations: 'disabled' });
    session.artifacts.push({ type: 'screenshot', filename });
    return filename;
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
      const locator = action.id ? session.page.locator(`[data-autoqa-ref="${action.id}"]`) : null;
      const logStart = { network: session.logSequences.network_errors, console: session.logSequences.console_errors };
      let assertion;
      let artifact;
      if (action.tool === 'navigate') {
        const url = assertSameOrigin(action.url, session.origin);
        await this.policy.check(url.href, session.mode);
        await session.page.goto(url.href, { waitUntil: 'domcontentloaded' });
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
      } else if (action.tool === 'screenshot' || action.tool === 'finish') artifact = await this.screenshot(session);
      if (action.tool === 'finish') { await this.stopTrace(session); session.finished = true; }
      const snapshot = await this.observe(session);
      if (assertion && !assertion.passed) artifact = await this.screenshot(session);
      return {
        snapshot,
        network_errors: session.network_errors.filter(({ sequence }) => sequence > logStart.network).map(({ value: error }) => ({ ...error, url: this.redact(session, error.url) })),
        console_errors: session.console_errors.filter(({ sequence }) => sequence > logStart.console).map(({ value: error }) => this.redact(session, error)),
        ...(assertion ? { assertion } : {}),
        ...(artifact ? { artifact } : {}),
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
