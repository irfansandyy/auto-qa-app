import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile, spawn } from 'node:child_process';
import { copyFile, mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { createServer } from '../server.mjs';
import { PolicyError } from '../policy.mjs';

const token = 'test-worker-token-32-characters-long';

async function withServer(callback, overrides = {}) {
  const calls = [];
  const manager = {
    health: async () => ({ status: 'ok', ready: true }),
    start: async (body) => { calls.push(body); return { session_id: 'session-1', snapshot: { title: 'Test' } }; },
    snapshot: async (id) => ({ title: id }),
    act: async (id, body) => { calls.push({ id, ...body }); return { snapshot: { title: 'After action' } }; },
    close: async () => ({ closed: true }),
    ...overrides,
  };
  const server = createServer({ token, manager });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  try { await callback(`http://127.0.0.1:${server.address().port}`, calls); }
  finally { server.closeAllConnections(); await new Promise((resolve) => server.close(resolve)); }
}

test('worker requires a long secret before it can start', () => {
  assert.throws(() => createServer({ token: '', manager: {} }), /at least 24/);
});

test('worker startup loads the configured browser cache before Playwright resolves its executable', { timeout: 15000 }, async () => {
  const directory = await mkdtemp(join(tmpdir(), 'autoqa-worker-startup-'));
  const workerDirectory = join(directory, 'worker');
  const sourceDirectory = fileURLToPath(new URL('../', import.meta.url));
  const cacheDirectory = join(directory, 'browser-cache');
  const environment = { ...process.env };
  for (const key of ['PLAYWRIGHT_BROWSERS_PATH', 'PLAYWRIGHT_EXECUTABLE_PATH', 'BROWSER_WORKER_HOST', 'BROWSER_WORKER_PORT', 'BROWSER_WORKER_TOKEN']) delete environment[key];
  let child;
  const reserve = createServer({ token, manager: {} });
  try {
    await mkdir(workerDirectory);
    await Promise.all(['runner.mjs', 'policy.mjs', 'browser.mjs', 'server.mjs'].map((filename) => copyFile(join(sourceDirectory, filename), join(workerDirectory, filename))));
    await symlink(join(sourceDirectory, 'node_modules'), join(workerDirectory, 'node_modules'), 'dir');
    const { stdout } = await promisify(execFile)(process.execPath, ['--input-type=module', '-e', "import { chromium } from 'playwright'; process.stdout.write(chromium.executablePath());"], {
      cwd: workerDirectory, env: { ...environment, PLAYWRIGHT_BROWSERS_PATH: cacheDirectory },
    });
    await mkdir(dirname(stdout), { recursive: true });
    await writeFile(stdout, 'browser cache health-check fixture');
    await new Promise((resolve) => reserve.listen(0, '127.0.0.1', resolve));
    const port = reserve.address().port;
    await new Promise((resolve) => reserve.close(resolve));
    await writeFile(join(directory, '.env'), `PLAYWRIGHT_BROWSERS_PATH=${cacheDirectory}\nBROWSER_WORKER_TOKEN=${token}\nBROWSER_WORKER_HOST=127.0.0.1\nBROWSER_WORKER_PORT=${port}\n`);
    child = spawn(process.execPath, [join(workerDirectory, 'runner.mjs')], { cwd: workerDirectory, env: environment, stdio: ['ignore', 'pipe', 'pipe'] });
    let diagnostics = '';
    child.stderr.on('data', (chunk) => { diagnostics += chunk; });
    await new Promise((resolve, reject) => {
      const deadline = setTimeout(() => reject(new Error('The isolated worker did not become ready.')), 8000);
      child.once('error', (error) => { clearTimeout(deadline); reject(error); });
      child.once('exit', (code) => { clearTimeout(deadline); reject(new Error(`The isolated worker exited with ${code}: ${diagnostics}`)); });
      child.stdout.on('data', (chunk) => {
        if (String(chunk).includes('Private Auto QA browser worker ready')) { clearTimeout(deadline); resolve(); }
      });
    });
    const response = await fetch(`http://127.0.0.1:${port}/health`, { headers: { authorization: `Bearer ${token}` } });
    assert.equal(response.status, 200);
    const health = await response.json();
    assert.equal(health.ready, true);
    assert.equal(health.browser_installed, true);
  } finally {
    if (reserve.listening) await new Promise((resolve) => reserve.close(resolve));
    if (child && child.exitCode === null && child.signalCode === null) {
      const stopped = new Promise((resolve) => child.once('exit', resolve));
      child.kill('SIGTERM');
      await stopped;
    }
    await rm(directory, { recursive: true, force: true });
  }
});

test('health and session endpoints both require the exact bearer token', async () => {
  await withServer(async (base, calls) => {
    for (const path of ['/health', '/sessions', '/sessions/session-1']) {
      const response = await fetch(base + path, { headers: { authorization: 'Bearer wrong' } });
      assert.equal(response.status, 401);
    }
    assert.equal(calls.length, 0);
    const response = await fetch(base + '/health', { headers: { authorization: `Bearer ${token}` } });
    assert.equal(response.status, 200);
    assert.equal((await response.json()).ready, true);
    assert.equal(response.headers.get('cache-control'), 'no-store');
  });
});

test('session creation receives only JSON objects and is dispatched once', async () => {
  await withServer(async (base, calls) => {
    const headers = { authorization: `Bearer ${token}`, 'content-type': 'application/json' };
    let response = await fetch(base + '/sessions', { method: 'POST', headers, body: '[]' });
    assert.equal(response.status, 400);
    response = await fetch(base + '/sessions', { method: 'POST', headers, body: JSON.stringify({ url: 'http://localhost:8080/', mode: 'local' }) });
    assert.equal(response.status, 201);
    assert.equal((await response.json()).session_id, 'session-1');
    assert.deepEqual(calls, [{ url: 'http://localhost:8080/', mode: 'local' }]);
  });
});

test('worker rejects non-JSON or oversized bodies before running browser actions', async () => {
  await withServer(async (base, calls) => {
    let response = await fetch(base + '/sessions', { method: 'POST', headers: { authorization: `Bearer ${token}` }, body: 'hello' });
    assert.equal(response.status, 415);
    response = await fetch(base + '/sessions', { method: 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: JSON.stringify({ value: 'x'.repeat(65536) }) });
    assert.equal(response.status, 413);
    assert.equal(calls.length, 0);
  });
});

test('actions, snapshots, close and policy failures return the documented shapes', async () => {
  await withServer(async (base, calls) => {
    const headers = { authorization: `Bearer ${token}`, 'content-type': 'application/json' };
    let response = await fetch(base + '/sessions/session-1', { headers });
    assert.equal((await response.json()).snapshot.title, 'session-1');
    response = await fetch(base + '/sessions/session-1/action', { method: 'POST', headers, body: JSON.stringify({ tool: 'screenshot' }) });
    assert.equal((await response.json()).snapshot.title, 'After action');
    assert.deepEqual(calls, [{ id: 'session-1', tool: 'screenshot' }]);
    response = await fetch(base + '/sessions/session-1', { method: 'DELETE', headers });
    assert.equal((await response.json()).closed, true);
  });
  await withServer(async (base) => {
    const response = await fetch(base + '/sessions/missing', { headers: { authorization: `Bearer ${token}` } });
    assert.equal(response.status, 404);
    assert.equal((await response.json()).error, 'Session expired.');
  }, { snapshot: async () => { throw new PolicyError('Session expired.', 404); } });
});
