import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';

export class PolicyError extends Error {
  constructor(message, status = 422) {
    super(message);
    this.name = 'PolicyError';
    this.status = status;
  }
}

export function parseHttpUrl(value) {
  if (typeof value !== 'string' || value.length > 2048) throw new PolicyError('A valid HTTP or HTTPS URL is required.');
  let url;
  try { url = new URL(value); } catch { throw new PolicyError('A valid HTTP or HTTPS URL is required.'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
    throw new PolicyError('Only HTTP and HTTPS URLs without embedded credentials are allowed.');
  }
  return url;
}

function ipv6Number(address) {
  let input = address.toLowerCase();
  if (input.includes('.')) {
    const lastColon = input.lastIndexOf(':');
    const bytes = input.slice(lastColon + 1).split('.').map(Number);
    input = `${input.slice(0, lastColon)}:${((bytes[0] << 8) | bytes[1]).toString(16)}:${((bytes[2] << 8) | bytes[3]).toString(16)}`;
  }
  const halves = input.split('::');
  const left = halves[0] ? halves[0].split(':') : [];
  const right = halves[1] ? halves[1].split(':') : [];
  const groups = halves.length === 2 ? [...left, ...Array(8 - left.length - right.length).fill('0'), ...right] : left;
  return groups.reduce((value, group) => (value << 16n) | BigInt(`0x${group || '0'}`), 0n);
}

export function isPublicIp(address) {
  const kind = isIP(address);
  if (kind === 4) {
    const [a, b, c] = address.split('.').map(Number);
    if (a === 0 || a === 10 || a === 127 || a >= 224) return false;
    if (a === 100 && b >= 64 && b <= 127) return false;
    if (a === 169 && b === 254) return false;
    if (a === 172 && b >= 16 && b <= 31) return false;
    if (a === 192 && (b === 168 || (b === 0 && (c === 0 || c === 2)) || (b === 88 && c === 99))) return false;
    if (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) return false;
    if (a === 203 && b === 0 && c === 113) return false;
    return true;
  }
  if (kind === 6) {
    const value = ipv6Number(address);
    // Only globally routable unicast; this rejects loopback, link-local, ULA,
    // multicast, and IPv4-mapped/compatible forms, including private addresses.
    if ((value >> 125n) !== 1n) return false;
    const first = Number(value >> 112n);
    const second = Number((value >> 96n) & 0xffffn);
    if (first === 0x2002) return false; // 6to4 can encode private IPv4 destinations.
    if (first === 0x2001 && (second < 0x0200 || second === 0x0db8)) return false;
    return true;
  }
  return false;
}

export function parseLocalTargets(value = '') {
  const origins = new Set();
  for (const entry of value.split(',').map((item) => item.trim()).filter(Boolean)) {
    const url = parseHttpUrl(entry);
    if (url.pathname !== '/' || url.search || url.hash) throw new PolicyError('AUTOQA_LOCAL_TARGETS must contain exact origins without paths or queries.');
    origins.add(url.origin);
  }
  return origins;
}

export function createTargetPolicy({ localTargets = new Set(), resolve = lookup } = {}) {
  async function check(value, mode = 'public') {
    const url = parseHttpUrl(value);
    if (mode === 'local') {
      if (!localTargets.has(url.origin)) throw new PolicyError('This local origin is not allowed by AUTOQA_LOCAL_TARGETS.');
      return url;
    }
    if (mode !== 'public') throw new PolicyError('Target mode must be public or local.');
    const hostname = url.hostname.replace(/^\[|\]$/g, '');
    if (hostname.toLowerCase() === 'localhost' || hostname.toLowerCase().endsWith('.localhost')) {
      throw new PolicyError('Local addresses require local mode and an administrator-approved origin.');
    }
    let addresses;
    try {
      addresses = isIP(hostname) ? [{ address: hostname }] : await resolve(hostname, { all: true, verbatim: true });
    } catch {
      throw new PolicyError('The target hostname could not be resolved.');
    }
    if (!addresses.length || addresses.some(({ address }) => !isPublicIp(address))) {
      throw new PolicyError('Public mode blocks private, loopback, link-local, and reserved network destinations.');
    }
    return url;
  }
  return { check, localTargets };
}

export function assertSameOrigin(value, origin) {
  const url = parseHttpUrl(value);
  if (url.origin !== origin) throw new PolicyError('Navigation must stay within the selected target origin.');
  return url;
}

export function validateAction(action, refs = new Set()) {
  if (!action || typeof action !== 'object' || Array.isArray(action)) throw new PolicyError('A browser action object is required.');
  const tools = ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url', 'screenshot', 'finish'];
  if (!tools.includes(action.tool)) throw new PolicyError(`Unknown browser tool. Allowed tools: ${tools.join(', ')}.`);
  if (['click', 'fill', 'select'].includes(action.tool) || (action.tool === 'press' && action.id !== undefined)) {
    if (typeof action.id !== 'string' || !refs.has(action.id)) throw new PolicyError('The element reference is missing or stale. Read a fresh snapshot.');
  }
  if (['fill', 'select'].includes(action.tool) && (typeof action.value !== 'string' || action.value.length > 4096)) {
    throw new PolicyError('The action value must be a string of at most 4096 characters.');
  }
  if (action.tool === 'press' && !['Enter', 'Tab', 'Escape', 'Space', 'ArrowDown', 'ArrowUp'].includes(action.key)) {
    throw new PolicyError('Only Enter, Tab, Escape, Space, ArrowDown, and ArrowUp keys are allowed.');
  }
  if (action.tool === 'navigate' || action.tool === 'assert_url') parseHttpUrl(action.url);
  if (action.tool === 'assert_text' && (typeof action.text !== 'string' || !action.text.trim() || action.text.length > 1000)) {
    throw new PolicyError('The assertion text must contain 1–1000 characters.');
  }
  return action;
}

export function redactUrl(value) {
  try {
    const url = new URL(value);
    for (const key of [...url.searchParams.keys()]) {
      if (/password|passwd|secret|token|api.?key|auth|credential/i.test(key)) url.searchParams.set(key, '[redacted]');
    }
    url.username = '';
    url.password = '';
    return url.toString();
  } catch { return '[invalid URL]'; }
}
