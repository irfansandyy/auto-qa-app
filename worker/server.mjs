import { createServer as createHttpServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { PolicyError } from './policy.mjs';

function authorized(header, token) {
  const expected = Buffer.from(`Bearer ${token}`);
  const actual = Buffer.from(header ?? '');
  return expected.length === actual.length && timingSafeEqual(expected, actual);
}

async function readJson(request) {
  if (!/^application\/json(?:\s*;|$)/i.test(request.headers['content-type'] ?? '')) throw new PolicyError('Content-Type must be application/json.', 415);
  let body = '';
  for await (const chunk of request) {
    body += chunk.toString();
    if (Buffer.byteLength(body) > 65536) throw new PolicyError('Request body is too large.', 413);
  }
  try {
    const value = JSON.parse(body);
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error();
    return value;
  } catch { throw new PolicyError('Request body must be a JSON object.', 400); }
}

export function createServer({ token, manager }) {
  if (typeof token !== 'string' || token.length < 24) throw new Error('BROWSER_WORKER_TOKEN must be configured with at least 24 characters.');
  return createHttpServer(async (request, response) => {
    const send = (status, body) => {
      response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff' });
      response.end(JSON.stringify(body));
    };
    if (!authorized(request.headers.authorization, token)) return send(401, { error: 'Browser worker authentication failed.' });
    try {
      const path = new URL(request.url, 'http://worker.invalid').pathname;
      if (request.method === 'GET' && path === '/health') return send(200, await manager.health());
      if (request.method === 'POST' && path === '/sessions') return send(201, await manager.start(await readJson(request)));
      const match = path.match(/^\/sessions\/([a-zA-Z0-9-]+)(\/action)?$/);
      if (!match) return send(404, { error: 'Browser worker endpoint was not found.' });
      const id = match[1];
      if (request.method === 'GET' && !match[2]) return send(200, { snapshot: await manager.snapshot(id) });
      if (request.method === 'DELETE' && !match[2]) return send(200, await manager.close(id));
      if (request.method === 'POST' && match[2]) return send(200, await manager.act(id, await readJson(request)));
      return send(405, { error: 'This method is not supported.' });
    } catch (error) {
      // Browser exceptions may include page-supplied content and credentials.
      // Policy errors are curated; all other details stay in private worker logs.
      if (!(error instanceof PolicyError)) console.error('Browser worker operation failed:', error.name);
      return send(error.status ?? 500, { error: error instanceof PolicyError ? error.message : 'The browser operation failed. Check the private worker log.' });
    }
  });
}
