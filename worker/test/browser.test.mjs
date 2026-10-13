import test from 'node:test';
import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BrowserManager } from '../browser.mjs';
import { createTargetPolicy, parseLocalTargets } from '../policy.mjs';

function browserFixture() {
  const operations = [];
  const events = new Map();
  const page = {
    currentUrl: 'about:blank',
    url() { return this.currentUrl; },
    on(event, callback) { events.set(event, callback); },
    mainFrame() { return this; },
    async goto(url) { this.currentUrl = url; operations.push({ tool: 'navigate', url }); },
    async evaluate(callback, { epoch }) { return { url: this.currentUrl, title: 'Registration', text: 'Welcome', elements: [{ id: `e${epoch}-1`, tag: 'input', role: '', label: 'Password', text: '', type: 'password' }] }; },
    locator(selector) {
      return {
        async click() { operations.push({ tool: 'click', selector }); },
        async fill(value) { operations.push({ tool: 'fill', selector, value }); },
        async selectOption(value) { operations.push({ tool: 'select', selector, value }); },
        async press(key) { operations.push({ tool: 'press', selector, key }); },
        async getAttribute() { return 'password'; },
        async innerText() { return 'Welcome'; },
      };
    },
    async waitForFunction() {},
    async waitForURL() {},
    async screenshot(options) { operations.push({ tool: 'screenshot', ...options }); },
  };
  const context = {
    setDefaultTimeout() {}, setDefaultNavigationTimeout() {},
    async newPage() { return page; },
    async route(pattern, callback) { this.routeHandler = callback; },
    async routeWebSocket(pattern, callback) { this.socketHandler = callback; },
    on() {},
    tracing: { async start() {}, async stop(options) { operations.push({ tool: 'trace', ...options }); } },
    async close() { operations.push({ tool: 'close' }); },
  };
  const chromium = {
    executablePath: () => process.execPath,
    async launch() { return { async newContext() { return context; }, async close() {} }; },
  };
  const manager = new BrowserManager({ chromium, policy: createTargetPolicy({ localTargets: parseLocalTargets('http://localhost:8080') }), evidenceDir: join(tmpdir(), 'autoqa-browser-unit-evidence') });
  return { manager, page, context, operations, events };
}

test('browser limits concurrency and rejects references after reobservation', async () => {
  const { manager, operations } = browserFixture();
  try {
    const first = manager.start({ url: 'http://localhost:8080/', mode: 'local' });
    await assert.rejects(manager.start({ url: 'http://localhost:8080/', mode: 'local' }), /one session/);
    const { session_id, snapshot } = await first;
    const result = await manager.act(session_id, { tool: 'fill', id: snapshot.elements[0].id, value: 'private-password' });
    assert.notEqual(result.snapshot.elements[0].id, snapshot.elements[0].id);
    await assert.rejects(manager.act(session_id, { tool: 'click', id: snapshot.elements[0].id }), /stale/);
    assert.equal(operations.filter((operation) => operation.tool === 'fill').length, 1);
    assert.equal(manager.redact(manager.session(session_id), 'Error private-password'), 'Error [redacted]');
  } finally { await manager.shutdown(); }
});

test('network interception blocks private assets and cross-origin redirects', async () => {
  const { manager, context, page } = browserFixture();
  try {
    await manager.start({ url: 'http://1.1.1.1/', mode: 'public' });
    async function request(url, navigation = false) {
      let outcome;
      const fakeRequest = { url: () => url, isNavigationRequest: () => navigation, frame: () => page };
      await context.routeHandler({ request: () => fakeRequest, async continue() { outcome = 'continue'; }, async abort() { outcome = 'abort'; } });
      return outcome;
    }
    assert.equal(await request('http://169.254.169.254/latest/meta-data/'), 'abort');
    assert.equal(await request('http://127.0.0.1/admin'), 'abort');
    assert.equal(await request('http://8.8.8.8/redirect', true), 'abort');
    assert.equal(await request('http://8.8.8.8/public.css'), 'continue');
    assert.equal(await request('http://1.1.1.1/register', true), 'continue');
  } finally { await manager.shutdown(); }
});

test('screenshots require the target origin and finish stops further actions', async () => {
  const { manager, page, operations } = browserFixture();
  try {
    const { session_id } = await manager.start({ url: 'http://localhost:8080/', mode: 'local' });
    page.currentUrl = 'http://outside.example/';
    await assert.rejects(manager.act(session_id, { tool: 'screenshot' }), /selected target/);
    assert.equal(operations.filter((operation) => operation.tool === 'screenshot').length, 0);
    page.currentUrl = 'http://localhost:8080/';
    const result = await manager.act(session_id, { tool: 'finish' });
    assert.equal(result.finished, true);
    assert.deepEqual(result.artifacts.map((artifact) => artifact.type), ['screenshot', 'trace']);
    for (const { filename } of result.artifacts) assert.match(filename, /^browser-[a-z0-9-]+\.(png|zip)$/);
    await assert.rejects(manager.act(session_id, { tool: 'screenshot' }), /has finished/);
    assert.equal((await manager.close(session_id)).closed, true);
    await assert.rejects(manager.snapshot(session_id), /not found/);
  } finally { await manager.shutdown(); }
});

test('bounded logs still return new errors after the ring fills', async () => {
  const { manager, events } = browserFixture();
  try {
    const { session_id } = await manager.start({ url: 'http://localhost:8080/', mode: 'local' });
    const session = manager.session(session_id);
    for (let index = 0; index < 150; index++) manager.log(session, 'console_errors', `initial-${index}`);
    const originalObserve = manager.observe.bind(manager);
    manager.observe = async (current) => {
      events.get('console')({ type: () => 'error', text: () => 'new-error' });
      return originalObserve(current);
    };
    const result = await manager.act(session_id, { tool: 'assert_text', text: 'Welcome' });
    assert.deepEqual(result.console_errors, ['new-error']);
    assert.equal(session.console_errors.length, 120);
  } finally { await manager.shutdown(); }
});
