import test from 'node:test';
import assert from 'node:assert/strict';
import { isPublicIp, parseHttpUrl, parseLocalTargets, createTargetPolicy, assertSameOrigin, validateAction, redactUrl } from '../policy.mjs';

test('public policy rejects private, metadata, reserved and encoded loopback addresses', () => {
  for (const ip of ['0.0.0.0', '10.20.1.1', '127.0.0.1', '100.64.0.1', '169.254.169.254', '172.16.1.1', '172.31.255.255', '192.168.1.1', '192.0.2.12', '198.18.0.1', '198.51.100.1', '203.0.113.4', '224.1.1.1', '255.255.255.255', '::', '::1', 'fe80::1', 'fc00::1234', 'fd00::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '2001:db8::1', '2002:7f00:0001::1']) {
    assert.equal(isPublicIp(ip), false, ip);
  }
  for (const ip of ['1.1.1.1', '8.8.8.8', '93.184.216.34', '2606:4700:4700::1111', '2001:4860:4860::8888']) assert.equal(isPublicIp(ip), true, ip);
  assert.equal(parseHttpUrl('http://2130706433/').hostname, '127.0.0.1');
  assert.equal(parseHttpUrl('http://0177.0.0.1/').hostname, '127.0.0.1');
});

test('DNS is checked again for every request, including after a public answer', async () => {
  let calls = 0;
  const policy = createTargetPolicy({ resolve: async () => [{ address: ++calls === 1 ? '1.1.1.1' : '127.0.0.1' }] });
  await policy.check('https://target.example/');
  await assert.rejects(policy.check('https://target.example/asset.js'), /blocks private/);
  assert.equal(calls, 2);
});

test('a hostname with mixed public and private answers is rejected', async () => {
  const policy = createTargetPolicy({ resolve: async () => [{ address: '1.1.1.1' }, { address: '::1' }] });
  await assert.rejects(policy.check('https://target.example/'), /blocks private/);
  await assert.rejects(policy.check('http://localhost:8080/'), /require local mode/);
});

test('local mode accepts only exact administrator-approved origins', async () => {
  const policy = createTargetPolicy({ localTargets: parseLocalTargets('http://localhost:8080,http://127.0.0.1:9000') });
  assert.equal((await policy.check('http://localhost:8080/register', 'local')).origin, 'http://localhost:8080');
  await assert.rejects(policy.check('http://localhost:8081/', 'local'), /not allowed/);
  await assert.rejects(policy.check('http://127.0.0.1:8080/', 'local'), /not allowed/);
  await assert.rejects(policy.check('https://localhost:8080/', 'local'), /not allowed/);
  await assert.rejects(policy.check('http://169.254.169.254/', 'local'), /not allowed/);
  assert.throws(() => parseLocalTargets('http://localhost:8080/path'), /exact origins/);
});

test('embedded credentials and non-HTTP URLs cannot enter the worker', () => {
  for (const value of ['file:///etc/passwd', 'javascript:alert(1)', 'data:text/html,test', 'ftp://example.com', 'https://user:password@example.com', 'invalid']) {
    assert.throws(() => parseHttpUrl(value));
  }
});

test('navigation remains on the selected target, including assertions', () => {
  assert.equal(assertSameOrigin('https://target.example/login', 'https://target.example').pathname, '/login');
  assert.throws(() => assertSameOrigin('https://outside.example/', 'https://target.example'), /selected target/);
  assert.throws(() => assertSameOrigin('http://target.example/', 'https://target.example'), /selected target/);
});

test('actions reject stale references, arbitrary evaluation and unsafe keys', () => {
  const refs = new Set(['e2-1']);
  assert.equal(validateAction({ tool: 'fill', id: 'e2-1', value: 'person@example.com' }, refs).tool, 'fill');
  assert.throws(() => validateAction({ tool: 'click', id: 'e1-1' }, refs), /stale/);
  assert.throws(() => validateAction({ tool: 'evaluate', code: 'fetch("http://localhost")' }, refs), /Unknown browser tool/);
  assert.throws(() => validateAction({ tool: 'press', id: 'e2-1', key: 'Control+L' }, refs), /Only Enter/);
  assert.throws(() => validateAction({ tool: 'fill', id: 'e2-1', value: 'x'.repeat(4097) }, refs), /4096/);
  assert.throws(() => validateAction({ tool: 'assert_text', text: '' }, refs), /assertion text/);
  assert.equal(validateAction({ tool: 'screenshot' }, refs).tool, 'screenshot');
});

test('evidence URLs redact credential query fields', () => {
  const result = new URL(redactUrl('https://target.example/error?password=supersecret&token=abc&step=2'));
  assert.equal(result.searchParams.get('password'), '[redacted]');
  assert.equal(result.searchParams.get('token'), '[redacted]');
  assert.equal(result.searchParams.get('step'), '2');
});
