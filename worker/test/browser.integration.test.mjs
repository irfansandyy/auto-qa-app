import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { BrowserManager } from '../browser.mjs';
import { createTargetPolicy } from '../policy.mjs';

const enabled = process.env.AUTOQA_REAL_BROWSER_TEST === '1';
const sourceSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const fixtureHtml = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Owned registration fixture</title></head>
<body>
  <h1>Registration</h1>
  <form id="registration">
    <label for="name">Name</label><input id="name" name="name" type="text" required>
    <label for="password">Password</label><input id="password" name="password" type="password" required>
    <label for="notes">Notes</label><textarea id="notes" name="notes"></textarea>
    <label for="plan">Plan</label><select id="plan" name="plan" required><option value="">Choose a plan</option><option value="basic">Basic</option></select>
    <button type="submit">Create account</button>
  </form>
  <p id="typing">Typing:</p><p id="result"></p>
  <script>
    const nameInput = document.getElementById('name');
    nameInput.addEventListener('input', () => {
      document.getElementById('typing').textContent = 'Typing: ' + nameInput.value;
    });
    document.getElementById('registration').addEventListener('submit', (event) => {
      event.preventDefault();
      document.getElementById('result').textContent = 'Welcome, ' + nameInput.value;
    });
  </script>
</body></html>`;

test('real Chromium completes an owned form journey, records evidence, and observes an initial HTTP 500', {
  skip: !enabled,
  timeout: 60000,
}, async () => {
  const evidenceDir = await mkdtemp(join(tmpdir(), 'autoqa-browser-integration-'));
  const server = createServer((request, response) => {
    if (request.url === '/favicon.ico') {
      response.writeHead(204);
      return response.end();
    }
    response.writeHead(request.url === '/failure' ? 500 : 200, {
      'content-type': 'text/html; charset=utf-8',
      'x-autoqa-commit': sourceSha,
    });
    response.end(request.url === '/failure' ? '<!doctype html><title>Owned error</title><h1>Owned server error</h1>' : fixtureHtml);
  });
  let manager;
  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    const origin = `http://127.0.0.1:${server.address().port}`;
    manager = new BrowserManager({
      chromium,
      policy: createTargetPolicy({ localTargets: new Set([origin]) }),
      evidenceDir,
    });
    const started = await manager.start({ url: `${origin}/`, mode: 'local' });
    const sessionId = started.session_id;
    assert.equal(started.snapshot.title, 'Owned registration fixture');
    assert.equal(started.snapshot.source_sha, sourceSha);
    const name = started.snapshot.elements.find((element) => element.label === 'Name');
    assert.ok(name, 'The worker discovers the visible input by its associated label.');
    assert.equal(name.required, true);
    assert.equal(name.has_value, false);
    const emptyPassword = started.snapshot.elements.find((element) => element.label === 'Password');
    assert.equal(emptyPassword.required, true);
    assert.equal(emptyPassword.has_value, false);
    const notes = started.snapshot.elements.find((element) => element.label === 'Notes');
    assert.equal(notes.required, false);
    assert.equal(notes.has_value, false);
    const plan = started.snapshot.elements.find((element) => element.label === 'Plan');
    assert.equal(plan.required, true);
    assert.equal(plan.has_value, false);

    const filled = await manager.act(sessionId, { tool: 'fill', id: name.id, value: 'Alice' });
    assert.match(filled.snapshot.text, /Typing: Alice/);
    const filledName = filled.snapshot.elements.find((element) => element.label === 'Name');
    assert.equal(filledName.required, true);
    assert.equal(filledName.has_value, true);
    assert.notEqual(filledName.id, name.id);
    await assert.rejects(manager.act(sessionId, { tool: 'fill', id: name.id, value: 'Stale action' }), /stale/);
    const password = filled.snapshot.elements.find((element) => element.label === 'Password');
    const secret = 'Owned-test-password-2026';
    const passwordFilled = await manager.act(sessionId, { tool: 'fill', id: password.id, value: secret });
    const passwordState = passwordFilled.snapshot.elements.find((element) => element.label === 'Password');
    assert.equal(passwordState.required, true);
    assert.equal(passwordState.has_value, true);
    assert.notEqual(passwordState.id, password.id);
    assert.equal(Object.hasOwn(passwordState, 'value'), false);
    assert.equal(JSON.stringify(passwordFilled.snapshot).includes(secret), false, 'Filled password values never appear in the browser snapshot.');
    const currentNotes = passwordFilled.snapshot.elements.find((element) => element.label === 'Notes');
    const notesFilled = await manager.act(sessionId, { tool: 'fill', id: currentNotes.id, value: 'Owned fixture note' });
    assert.equal(notesFilled.snapshot.elements.find((element) => element.label === 'Notes').has_value, true);
    const currentPlan = notesFilled.snapshot.elements.find((element) => element.label === 'Plan');
    const selected = await manager.act(sessionId, { tool: 'select', id: currentPlan.id, value: 'basic' });
    assert.equal(selected.snapshot.elements.find((element) => element.label === 'Plan').has_value, true);
    assert.equal(JSON.stringify(selected.snapshot).includes(secret), false);
    const submit = selected.snapshot.elements.find((element) => element.tag === 'button' && element.text === 'Create account');
    assert.ok(submit, 'The refreshed snapshot identifies the submit control.');

    const clicked = await manager.act(sessionId, { tool: 'click', id: submit.id });
    assert.match(clicked.snapshot.text, /Welcome, Alice/);
    const checked = await manager.act(sessionId, { tool: 'assert_text', text: 'Welcome, Alice' });
    assert.equal(checked.assertion.passed, true);
    const screenshot = await manager.act(sessionId, { tool: 'screenshot' });
    const screenshotBytes = await readFile(join(evidenceDir, screenshot.artifact));
    assert.deepEqual([...screenshotBytes.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
    const closed = await manager.close(sessionId);
    assert.equal(closed.closed, true);
    assert.deepEqual(closed.artifacts.map((artifact) => artifact.type), ['screenshot', 'trace']);
    const trace = closed.artifacts.find((artifact) => artifact.type === 'trace');
    const traceBytes = await readFile(join(evidenceDir, trace.filename));
    assert.deepEqual([...traceBytes.subarray(0, 2)], [0x50, 0x4b]);
    assert.ok(traceBytes.length > 100);

    const failed = await manager.start({ url: `${origin}/failure`, mode: 'local' });
    assert.match(failed.snapshot.text, /Owned server error/);
    assert.ok(failed.network_errors.some((error) => error.status === 500 && error.url === `${origin}/failure`), 'The initial page response is preserved as HTTP 500 evidence.');
    assert.equal((await manager.close(failed.session_id)).closed, true);
    assert.equal((await manager.health()).active_sessions, 0);
  } finally {
    await manager?.shutdown();
    server.closeAllConnections();
    if (server.listening) await new Promise((resolve) => server.close(resolve));
    await rm(evidenceDir, { recursive: true, force: true });
  }
});

test('read-only Chromium sessions record bounded previews and report a broken page without making write requests', {
  skip: !enabled,
  timeout: 60000,
}, async () => {
  const evidenceDir = await mkdtemp(join(tmpdir(), 'autoqa-preview-integration-'));
  const paths = [];
  const server = createServer((request, response) => {
    paths.push([request.method, request.url]);
    response.writeHead(request.url === '/broken' ? 404 : 200, { 'content-type': 'text/html; charset=utf-8' });
    if (request.url === '/blank') return response.end('<!doctype html><title>Blank fixture</title><body></body>');
    response.end(request.url === '/broken' ? '<title>Page not found</title><h1>Page not found</h1>' : [
      '<!doctype html><title>Owned help site</title><h1>Welcome</h1>',
      '<a href="/broken">Help articles</a><a href="/account/delete">Manage account</a>',
      '<a href="/account?token=fixture-secret">Account link</a><a href="/download" download>Download a file</a>',
      '<a href="https://outside.example/site">Other site</a><button>Send a message</button>',
      "<script>fetch('/write', {method: 'POST'}).catch(() => {});</script>",
    ].join(''));
  });
  let manager;
  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    const origin = 'http://127.0.0.1:'+server.address().port;
    manager = new BrowserManager({
      chromium, evidenceDir, policy: createTargetPolicy({ localTargets: new Set([origin]) }),
    });
    const opened = await manager.start({ url: origin+'/', mode: 'local', read_only: true });
    assert.equal(opened.snapshot.document_status, 200);
    const firstPreview = await readFile(join(evidenceDir, opened.preview_artifact));
    assert.deepEqual([...firstPreview.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);

    const links = opened.snapshot.elements.flatMap((element) => element.read_only_url ?? []);
    assert.deepEqual(links, [origin+'/broken']);
    const button = opened.snapshot.elements.find((element) => element.tag === 'button');
    await assert.rejects(manager.act(opened.session_id, { tool: 'click', id: button.id }), /read-only/);
    await assert.rejects(manager.act(opened.session_id, { tool: 'navigate', url: origin+'/unobserved' }), /visible page link/);
    const passed = await manager.act(opened.session_id, { tool: 'assert_page' });
    assert.equal(passed.assertion.passed, true);
    assert.notEqual(passed.preview_artifact, opened.preview_artifact);
    assert.equal((await manager.act(opened.session_id, { tool: 'assert_page', url: origin+'/' })).assertion.passed, true);
    assert.equal((await manager.act(opened.session_id, { tool: 'assert_page', url: origin+'/broken' })).assertion.passed, false);
    await assert.rejects(manager.act(opened.session_id, { tool: 'assert_page', url: 'https://outside.example/' }), /selected target/);

    const broken = await manager.act(opened.session_id, { tool: 'navigate', url: links[0] });
    assert.equal(broken.snapshot.document_status, 404);
    assert.ok(broken.network_errors.some((error) => error.status === 404 && error.main_document));
    const failed = await manager.act(opened.session_id, { tool: 'assert_page' });
    assert.equal(failed.assertion.passed, false);
    assert.equal(failed.artifact, failed.preview_artifact);
    const closed = await manager.close(opened.session_id);
    assert.ok(closed.artifacts.some(({ filename }) => filename === broken.preview_artifact));
    const blank = await manager.start({ url: origin+'/blank', mode: 'local', read_only: true });
    assert.equal(blank.snapshot.document_status, 200);
    assert.equal((await manager.act(blank.session_id, { tool: 'assert_page' })).assertion.passed, false);
    await manager.close(blank.session_id);
    assert.ok(paths.some(([method, path]) => method === 'GET' && path === '/broken'));
    assert.ok(!paths.some(([method, path]) => method === 'POST' || path === '/unobserved' || path === '/account/delete'));
  } finally {
    await manager?.shutdown();
    server.closeAllConnections();
    if (server.listening) await new Promise((resolve) => server.close(resolve));
    await rm(evidenceDir, { recursive: true, force: true });
  }
});

test('read-only front-end review measures a narrow viewport and observes layout, markup, and loading defects', {
  skip: !enabled,
  timeout: 60000,
}, async () => {
  const evidenceDir = await mkdtemp(join(tmpdir(), 'autoqa-frontend-integration-'));
  const visits = [];
  const server = createServer((request, response) => {
    visits.push([request.method, request.url]);
    if (request.url === '/broken.png' || request.url === '/favicon.ico') {
      response.writeHead(404);
      return response.end();
    }
    if (request.url === '/redirected.png') {
      response.writeHead(302, { location: '/broken.png' });
      return response.end();
    }
    if (request.url === '/next') {
      response.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'x-frame-options': 'SAMEORIGIN' });
      return response.end('<!doctype html><html lang="en"><title>Next</title><meta name="viewport" content="width=device-width"><h1>Next</h1></html>');
    }
    response.writeHead(200, {
      'content-type': 'text/html; charset=utf-8',
      'content-security-policy': "frame-ancestors 'none'",
      'x-content-type-options': 'nosniff',
      'strict-transport-security': 'max-age=3600',
      'set-cookie': 'localfixture=sample; Path=/',
    });
    response.end(`<!doctype html>
      <style>body{margin:0}.wide-panel{width:700px}@media(max-width:600px){.desktop-only{display:none}}</style>
      <main class="wide-panel"><h1>Owned check page</h1><a class="desktop-only" href="/next">Desktop link</a>
      <img src="/broken.png" width="30" height="20"><img src="/redirected.png" alt="" width="30" height="20">
      <label for="labelled">Name</label><input id="labelled"><input type="email"><input hidden></main>
      <script>console.error('Fixture console error'); throw new Error('Fixture runtime error');</script>`);
  });
  let manager;
  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    const origin = `http://127.0.0.1:${server.address().port}`;
    manager = new BrowserManager({ chromium, evidenceDir, policy: createTargetPolicy({ localTargets: new Set([origin]) }) });
    const started = await manager.start({ url: `${origin}/`, mode: 'local', read_only: true });
    const result = await manager.act(started.session_id, { tool: 'inspect_frontend' });

    assert.deepEqual(result.frontend.viewports, {
      desktop: { width: 1152, height: 768, horizontal_overflow_px: 0 },
      mobile: { width: 390, height: 844, horizontal_overflow_px: 310 },
    });
    assert.deepEqual(result.frontend.findings, [
      { code: 'missing_viewport', count: 1 },
      { code: 'missing_page_title', count: 1 },
      { code: 'missing_document_language', count: 1 },
      { code: 'missing_image_alt', count: 1 },
      { code: 'unlabeled_controls', count: 1 },
      { code: 'horizontal_overflow', count: 310, viewport: 'mobile' },
      { code: 'javascript_error', count: 1 },
      { code: 'failed_resource', count: 1 },
    ]);
    assert.deepEqual(result.hardening, { https: false, hsts: false, nosniff: true, csp: true, frame_restriction: true });
    assert.ok(result.snapshot.elements.some((element) => element.text === 'Desktop link'), 'The restored desktop snapshot keeps links for the next action.');
    const preview = await readFile(join(evidenceDir, result.preview_artifact));
    assert.deepEqual([preview.readUInt32BE(16), preview.readUInt32BE(20)], [390, 844]);

    await manager.act(started.session_id, { tool: 'navigate', url: `${origin}/next` });
    const next = await manager.act(started.session_id, { tool: 'inspect_frontend' });
    assert.deepEqual(next.frontend.findings, [], 'A second diagnostic only counts the current page.');
    assert.deepEqual(next.hardening, { https: false, hsts: false, nosniff: false, csp: false, frame_restriction: true });
    assert.equal(visits.filter(([method, path]) => method === 'GET' && path === '/').length, 1);
    assert.equal(visits.filter(([method, path]) => method === 'GET' && path === '/next').length, 1);
    assert.ok(visits.every(([method]) => method === 'GET'));
    await manager.close(started.session_id);

    const legacy = await manager.start({ url: `${origin}/`, mode: 'local' });
    await assert.rejects(manager.act(legacy.session_id, { tool: 'inspect_frontend' }), /read-only session/);
  } finally {
    await manager?.shutdown();
    server.closeAllConnections();
    if (server.listening) await new Promise((resolve) => server.close(resolve));
    await rm(evidenceDir, { recursive: true, force: true });
  }
});
