import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { SiteManagerApi, endpointUrl, apiFromEnv } from '../src/api.js';

const base = 'https://example.test/wordpress/wp-json/ai-site-manager/v1';
const key = 'aism_test_secret_not_a_real_key';
const jsonResponse = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), { status, headers });

test('constructs pretty and plain URLs, preserving subdirectory and array query arguments', () => {
  const pretty = endpointUrl(base, '/wp/posts', { status: ['draft', 'private'], context: 'edit', page: 2 });
  assert.equal(pretty.pathname, '/wordpress/wp-json/ai-site-manager/v1/wp/posts');
  assert.deepEqual(pretty.searchParams.getAll('status[]'), ['draft', 'private']);
  const plain = endpointUrl('https://example.test/wordpress/?rest_route=/ai-site-manager/v1', '/schema', { resource: 'posts' });
  assert.equal(plain.pathname, '/wordpress/');
  assert.equal(plain.searchParams.get('rest_route'), '/ai-site-manager/v1/schema');
  assert.equal(plain.searchParams.get('resource'), 'posts');
});

test('rejects insecure bases, route escapes and authentication/routing query overrides', () => {
  for (const value of ['http://example.test/ai-site-manager/v1', 'https://user:pass@example.test/ai-site-manager/v1', 'https://example.test/wp-json/wp/v2', base + '#fragment', base + '?api_key=secret', 'https://example.test/x-ai-site-manager/v1']) {
    assert.throws(() => endpointUrl(value, '/discovery'));
  }
  for (const path of ['https://other.test/', '//other.test', '/../users', '/wp/%2e%2e', '/discovery?api_key=x']) assert.throws(() => endpointUrl(base, path));
  for (const name of ['rest_route', '_method', '_jsonp', '_envelope', '_wpnonce', 'api_key']) assert.throws(() => endpointUrl(base, '/discovery', { [name]: 'x' }));
});

test('authenticates with configured header, sets application UA and preserves pagination', async () => {
  for (const authHeader of ['Authorization', 'X-AISM-Key']) {
    const api = new SiteManagerApi({ base, key, authHeader, fetchImpl: async (url, options) => {
      assert.equal(options.redirect, 'manual');
      assert.equal(options.headers[authHeader], authHeader === 'Authorization' ? 'Bearer ' + key : key);
      assert.match(options.headers['User-Agent'], /^AI-Site-Manager-MCP\//);
      assert.equal(url.origin, 'https://example.test');
      return jsonResponse([{ id: 1 }], 200, { 'X-WP-Total': '3', 'X-WP-TotalPages': '2' });
    } });
    const result = await api.request('GET', '/wp/posts');
    assert.deepEqual(result.headers, { 'X-WP-Total': '3', 'X-WP-TotalPages': '2' });
    assert.deepEqual(result.data, [{ id: 1 }]);
  }
});

test('refuses redirects without retrying or forwarding credentials', async () => {
  let calls = 0;
  const api = new SiteManagerApi({ base, key, fetchImpl: async () => {
    calls++;
    return new Response(null, { status: 302, headers: { Location: 'https://other.test' } });
  } });
  await assert.rejects(api.request('GET', '/discovery'), /redirect refused/);
  assert.equal(calls, 1);
});

test('redacts secrets in successful data and API errors, including Cloudflare errors', async () => {
  const success = new SiteManagerApi({ base, key, fetchImpl: async () => jsonResponse({ message: key }) });
  assert.equal((await success.request('GET', '/guide')).data.message, '[REDACTED]');
  const failure = new SiteManagerApi({ base, key, fetchImpl: async () => jsonResponse({ detail: key, error_name: 'browser_signature_banned' }, 403) });
  await assert.rejects(failure.request('GET', '/guide'), error => error.status === 403 && error.code === 'browser_signature_banned' && !error.message.includes(key));
});

test('reports HTML responses and uncertain writes without exposing HTML or retrying', async () => {
  let calls = 0;
  const api = new SiteManagerApi({ base, key, fetchImpl: async () => {
    calls++;
    return new Response('<html>' + key + '</html>', { status: 500 });
  } });
  await assert.rejects(api.request('POST', '/wp/posts', { body: { title: 'test' } }), error => !error.message.includes(key) && /verify state/.test(error.message));
  assert.equal(calls, 1);
});

test('reports timeouts and interrupted responses without leaking transport errors', async () => {
  const timeout = new SiteManagerApi({ base, key, fetchImpl: async () => { throw new DOMException(key, 'TimeoutError'); } });
  await assert.rejects(timeout.request('POST', '/wp/posts', { body: {} }), /timed out.*before retrying/);
  const broken = new SiteManagerApi({ base, key, fetchImpl: async () => new Response(new ReadableStream({ start(controller) { controller.error(new Error(key)); } })) });
  await assert.rejects(broken.request('GET', '/guide'), /response interrupted/);
});

test('enforces response size bound', async () => {
  const api = new SiteManagerApi({ base, key, fetchImpl: async () => new Response(new Uint8Array(16 * 1024 * 1024 + 1)) });
  await assert.rejects(api.request('GET', '/guide'), /exceeds 16 MiB/);
});

test('multipart upload sends actual file bytes and fields; file size and ZIP checks apply', async () => {
  const folder = await mkdtemp(join(tmpdir(), 'aism-test-'));
  try {
    const file = join(folder, 'image.png');
    await writeFile(file, Buffer.from([1, 2, 3]));
    const api = new SiteManagerApi({ base, key, fetchImpl: async (_url, options) => {
      assert.ok(options.body instanceof FormData);
      const upload = options.body.get('file');
      assert.equal(upload.name, 'image.png');
      assert.equal(upload.type, 'image/png');
      assert.deepEqual(Buffer.from(await upload.arrayBuffer()), Buffer.from([1, 2, 3]));
      assert.equal(options.body.get('alt_text'), 'Test image');
      assert.equal(options.headers['Content-Type'], undefined);
      return jsonResponse({ id: 3 }, 201);
    } });
    assert.equal((await api.upload('/wp/media', file, 'file', { alt_text: 'Test image' })).status, 201);
    await assert.rejects(api.upload('/plugins/upload', file, 'pluginzip'), /\.zip/);
    const limited = new SiteManagerApi({ base, key, maxUploadBytes: 2 });
    await assert.rejects(limited.upload('/wp/media', file, 'file'), /within WP_AI_MAX_UPLOAD_BYTES/);
  } finally { await rm(folder, { recursive: true }); }
});

test('loads a plain key file and rejects ambiguous or invalid configuration', async () => {
  const folder = await mkdtemp(join(tmpdir(), 'aism-key-'));
  try {
    const file = join(folder, 'key');
    await writeFile(file, key + '\n', { mode: 0o600 });
    assert.ok(await apiFromEnv({ WP_AI_BASE: base, WP_AI_KEY_FILE: file }));
    await assert.rejects(apiFromEnv({ WP_AI_BASE: base, WP_AI_KEY_FILE: file, WP_AI_KEY: key }), /only one/);
    await assert.rejects(apiFromEnv({ WP_AI_BASE: base, WP_AI_KEY: key, WP_AI_TIMEOUT_MS: 'NaN' }), /integer/);
    await assert.rejects(apiFromEnv({ WP_AI_BASE: base }), /valid API key/);
  } finally { await rm(folder, { recursive: true }); }
});
