import test from 'node:test';
import assert from 'node:assert/strict';
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
