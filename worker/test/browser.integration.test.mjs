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
    <label for="name">Name</label><input id="name" name="name" type="text">
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

    const filled = await manager.act(sessionId, { tool: 'fill', id: name.id, value: 'Alice' });
    assert.match(filled.snapshot.text, /Typing: Alice/);
    assert.notEqual(filled.snapshot.elements.find((element) => element.label === 'Name').id, name.id);
    await assert.rejects(manager.act(sessionId, { tool: 'fill', id: name.id, value: 'Stale action' }), /stale/);
    const submit = filled.snapshot.elements.find((element) => element.tag === 'button' && element.text === 'Create account');
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
