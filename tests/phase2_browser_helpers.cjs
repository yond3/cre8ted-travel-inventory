// Tests the actual inline request helpers; no browser packages or dependencies.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const root = path.resolve(__dirname, '..');

async function checkPage(file, endMarker, functionName) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');
  const start = html.indexOf('  const pendingWrites = new Map();');
  assert(start >= 0, 'request helper exists');
  const end = html.indexOf(endMarker, start);
  assert(end > start);
  const calls = [];
  const savedKeys = new Map();
  let mode = 'ok';
  const context = vm.createContext({
    API: 'api/', state: { csrfToken: 'fixture', user: { username: 'fixture' } },
    user: { username: 'fixture' }, csrfToken: 'fixture',
    sessionStorage: { getItem: key => savedKeys.get(key), setItem: (key, value) => savedKeys.set(key, value), removeItem: key => savedKeys.delete(key) },
    // Deliberately omit randomUUID to cover existing plain HTTP hosting.
    crypto: { getRandomValues: bytes => webcrypto.getRandomValues(bytes) },
    Uint8Array, location: {},
    fetch: async (url, options) => {
      calls.push({ url, ...options });
      if (mode === 'network') throw new Error('Simulated lost response');
      return { status: mode === 'conflict' ? 409 : 200, ok: mode !== 'conflict',
        json: async () => { if (mode === 'malformed') throw new Error('Invalid response'); return { status: mode }; } };
    },
  });
  vm.runInContext(html.slice(start, end) + `\nglobalThis.send = ${functionName};`, context);
  const body = { item_key: 'fixture', qty: 1 };
  await Promise.all([context.send('stock_issues.php', 'POST', body), context.send('stock_issues.php', 'POST', body)]);
  assert.equal(calls.length, 1, 'double submission makes one request');
  assert.match(calls[0].headers['X-Idempotency-Key'], /^[a-f0-9]{32}$/);
  mode = 'network';
  await assert.rejects(context.send('stock_issues.php', 'POST', body));
  const retryKey = calls.at(-1).headers['X-Idempotency-Key'];
  mode = 'ok';
  await context.send('stock_issues.php', 'POST', body);
  assert.equal(calls.at(-1).headers['X-Idempotency-Key'], retryKey, 'network retry retains operation identity');
  await context.send('stock_issues.php', 'POST', body);
  assert.notEqual(calls.at(-1).headers['X-Idempotency-Key'], retryKey, 'a later intentional operation has a new key');
  mode = 'conflict';
  await assert.rejects(context.send('stock_issues.php', 'POST', body));
  const conflictKey = calls.at(-1).headers['X-Idempotency-Key'];
  mode = 'ok';
  await context.send('stock_issues.php', 'POST', body);
  assert.notEqual(calls.at(-1).headers['X-Idempotency-Key'], conflictKey);
  mode = 'network';
  await assert.rejects(context.send('stock_issues.php', 'POST', body));
  const beforeReloadKey = calls.at(-1).headers['X-Idempotency-Key'];
  const reloaded = vm.createContext({ ...context });
  vm.runInContext(html.slice(start, end) + `\nglobalThis.send = ${functionName};`, reloaded);
  mode = 'ok';
  await reloaded.send('stock_issues.php', 'POST', body);
  assert.equal(calls.at(-1).headers['X-Idempotency-Key'], beforeReloadKey, 'page reload retains uncertain operation identity');
  mode = 'malformed';
  await assert.rejects(reloaded.send('stock_issues.php', 'POST', body));
  const malformedKey = calls.at(-1).headers['X-Idempotency-Key'];
  mode = 'ok';
  await reloaded.send('stock_issues.php', 'POST', body);
  assert.equal(calls.at(-1).headers['X-Idempotency-Key'], malformedKey, 'unreadable response does not create a new operation');
  // Parse every inline script as well, without executing DOM/CDN code.
  for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
    if (match[1].trim()) new vm.Script(match[1], { filename: file });
  }
  console.log(`PASS ${file}: double submit, network retry, page reload, unreadable response, new operation, conflicts, HTTP crypto and script syntax`);
}

(async () => {
  await checkPage('php/index.html', '\n  /* ---------- auth', 'apiSend');
  await checkPage('php/department-api.html', '\n  function toast(', 'api');
})().catch(error => { console.error(error); process.exitCode = 1; });
