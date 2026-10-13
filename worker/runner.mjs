import { chromium } from 'playwright';
import { createTargetPolicy, parseLocalTargets } from './policy.mjs';
import { BrowserManager } from './browser.mjs';
import { createServer } from './server.mjs';
import { fileURLToPath } from 'node:url';

try { process.loadEnvFile(fileURLToPath(new URL('../.env', import.meta.url))); }
catch (error) { if (error.code !== 'ENOENT') throw error; }

const host = process.env.BROWSER_WORKER_HOST ?? '127.0.0.1';
if (!['127.0.0.1', '::1'].includes(host)) throw new Error('The private browser worker must listen on a loopback address.');
const port = Number(process.env.BROWSER_WORKER_PORT ?? 3001);
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('BROWSER_WORKER_PORT must be a valid port number.');
const policy = createTargetPolicy({ localTargets: parseLocalTargets(process.env.AUTOQA_LOCAL_TARGETS ?? '') });
const manager = new BrowserManager({
  chromium, policy,
  evidenceDir: process.env.BROWSER_EVIDENCE_DIR ?? process.env.EVIDENCE_DIR ?? fileURLToPath(new URL('../storage/app/private/evidence/', import.meta.url)),
});
const server = createServer({ token: process.env.BROWSER_WORKER_TOKEN, manager });
server.requestTimeout = 45000;
server.headersTimeout = 10000;
server.listen(port, host, () => console.log(`Private Auto QA browser worker ready on ${host}:${port}.`));

async function shutdown() {
  server.close();
  await manager.shutdown();
  process.exit(0);
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
