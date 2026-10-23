import { readFile, stat } from 'node:fs/promises';
import { basename } from 'node:path';

const BLOCKED_QUERY = new Set(['rest_route', '_method', '_jsonp', '_envelope', '_embed', '_wpnonce', 'api_key', 'key', 'token']);
const AUTH_HEADERS = new Set(['Authorization', 'X-AISM-Key']);
const MIME_TYPES = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', avif: 'image/avif', svg: 'image/svg+xml', pdf: 'application/pdf', zip: 'application/zip' };

export function endpointUrl(base, path, query = {}) {
  const url = new URL(base);
  if (url.protocol !== 'https:' || url.username || url.password || url.hash) {
    throw new Error('WP_AI_BASE must be an HTTPS URL without credentials or a fragment.');
  }
  const plainRoutes = url.searchParams.getAll('rest_route');
  const namespace = plainRoutes.length ? plainRoutes[0] : url.pathname;
  if (plainRoutes.length > 1 || !/\/ai-site-manager\/v1\/?$/.test(namespace)) {
    throw new Error('WP_AI_BASE must end in /ai-site-manager/v1, or use ?rest_route=/ai-site-manager/v1.');
  }
  if (!/^\/(?:[a-zA-Z0-9_.~-]+\/?)*$/.test(path) || path.split('/').some(p => p === '.' || p === '..')) {
    throw new Error('API path must be a relative, unencoded gateway path.');
  }
  for (const name of url.searchParams.keys()) {
    if (name !== 'rest_route' && BLOCKED_QUERY.has(name)) throw new Error('Authentication and routing overrides are forbidden in base URLs.');
  }
  if (plainRoutes.length) url.searchParams.set('rest_route', namespace.replace(/\/$/, '') + path);
  else url.pathname = url.pathname.replace(/\/$/, '') + path;
  for (const [name, value] of Object.entries(query)) {
    if (!/^[a-zA-Z0-9_-]+$/.test(name) || BLOCKED_QUERY.has(name)) throw new Error(`Unsupported query parameter: ${name}`);
    if (value === undefined || value === null) continue;
    const values = Array.isArray(value) ? value : [value];
    for (const item of values) {
      if (!['string', 'number', 'boolean'].includes(typeof item)) throw new Error('Query values must be scalars or arrays of scalars.');
      url.searchParams.append(Array.isArray(value) ? name + '[]' : name, String(item));
    }
  }
  return url;
}

export class ApiError extends Error {
  constructor(message, status = 0, code = 'aism_client_error') {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export class SiteManagerApi {
  #key;
  constructor({ base, key, authHeader = 'Authorization', timeout = 120_000, maxUploadBytes = 32 * 1024 * 1024, fetchImpl = fetch }) {
    endpointUrl(base, '/discovery');
    if (!key?.trim() || /[\r\n]/.test(key)) throw new Error('Set WP_AI_KEY or WP_AI_KEY_FILE to a valid API key.');
    if (!AUTH_HEADERS.has(authHeader)) throw new Error('WP_AI_AUTH_HEADER must be Authorization or X-AISM-Key.');
    if (!Number.isInteger(timeout) || timeout < 1000 || timeout > 600_000) throw new Error('WP_AI_TIMEOUT_MS must be an integer from 1000 to 600000.');
    if (!Number.isInteger(maxUploadBytes) || maxUploadBytes < 1 || maxUploadBytes > 1024 ** 3) throw new Error('WP_AI_MAX_UPLOAD_BYTES must be an integer from 1 to 1073741824.');
    this.base = base;
    this.#key = key.trim();
    this.authHeader = authHeader;
    this.timeout = timeout;
    this.maxUploadBytes = maxUploadBytes;
    this.fetchImpl = fetchImpl;
  }

  redact(text) { return String(text).replaceAll(this.#key, '[REDACTED]'); }

  async request(method, path, { query, body, form } = {}) {
    const url = endpointUrl(this.base, path, query);
    const headers = {
      Accept: 'application/json',
      'User-Agent': 'AI-Site-Manager-MCP/1.0 (+https://github.com/nkd3v/ai-site-manager-mcp)',
      [this.authHeader]: this.authHeader === 'Authorization' ? `Bearer ${this.#key}` : this.#key,
    };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    let response;
    try {
      response = await this.fetchImpl(url, {
        method, headers, redirect: 'manual', signal: AbortSignal.timeout(this.timeout),
        body: form ?? (body === undefined ? undefined : JSON.stringify(body)),
      });
    } catch (error) {
      const timeout = error.name === 'TimeoutError' || error.name === 'AbortError';
      throw new ApiError(timeout
        ? 'API request timed out. For writes, read/search for the resulting object before retrying; it may have succeeded.'
        : 'API connection failed. Check the host, network and TLS certificates. For writes, verify state before retrying.');
    }
    if (response.status >= 300 && response.status < 400) {
      await response.body?.cancel();
      throw new ApiError('API redirect refused. Configure the final HTTPS base URL; credentials are never forwarded through redirects.', response.status);
    }
    const chunks = [];
    let bytes = 0;
    const reader = response.body?.getReader();
    try {
      if (reader) {
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          bytes += value.length;
          if (bytes > 16 * 1024 * 1024) {
            await reader.cancel();
            throw new ApiError('API response exceeds 16 MiB. Use pagination or _fields; verify any writes before retrying.', response.status);
          }
          chunks.push(Buffer.from(value));
        }
      }
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw new ApiError('API response interrupted. Verify any writes before retrying.', response.status);
    }
    let data;
    try { data = JSON.parse(this.redact(Buffer.concat(chunks).toString('utf8'))); }
    catch {
      throw new ApiError(`HTTP ${response.status}: API returned a non-JSON response. Check the host or firewall. For writes, verify state before retrying.`, response.status);
    }
    if (!response.ok) {
      throw new ApiError(this.redact(`HTTP ${response.status}: ${data?.message ?? data?.detail ?? data?.title ?? 'API request failed'}`), response.status, this.redact(data?.code ?? data?.error_name ?? 'aism_http_error'));
    }
    const pagination = {};
    for (const name of ['X-WP-Total', 'X-WP-TotalPages']) {
      if (response.headers.has(name)) pagination[name] = response.headers.get(name);
    }
    return { status: response.status, headers: pagination, data };
  }

  async upload(path, filePath, field, fields = {}) {
    const info = await stat(filePath);
    if (!info.isFile() || info.size < 1 || info.size > this.maxUploadBytes) throw new Error('Upload must be a nonempty regular file within WP_AI_MAX_UPLOAD_BYTES (default 32 MiB).');
    const filename = basename(filePath).replace(/[\r\n"]/g, '_');
    const extension = filename.split('.').at(-1).toLowerCase();
    if (field === 'pluginzip' && extension !== 'zip') throw new Error('Plugin uploads require a .zip file.');
    const data = await readFile(filePath);
    if (data.length > this.maxUploadBytes) throw new Error('File exceeds WP_AI_MAX_UPLOAD_BYTES.');
    const form = new FormData();
    form.append(field, new Blob([data], { type: MIME_TYPES[extension] ?? 'application/octet-stream' }), filename);
    for (const [name, value] of Object.entries(fields)) if (value !== undefined) form.append(name, String(value));
    return this.request('POST', path, { form });
  }
}

export async function apiFromEnv(env = process.env) {
  if (env.WP_AI_KEY && env.WP_AI_KEY_FILE) throw new Error('Set only one of WP_AI_KEY and WP_AI_KEY_FILE.');
  const key = env.WP_AI_KEY_FILE ? (await readFile(env.WP_AI_KEY_FILE, 'utf8')).trim() : env.WP_AI_KEY;
  return new SiteManagerApi({
    base: env.WP_AI_BASE ?? '', key, authHeader: env.WP_AI_AUTH_HEADER ?? 'Authorization',
    timeout: Number(env.WP_AI_TIMEOUT_MS ?? 120_000), maxUploadBytes: Number(env.WP_AI_MAX_UPLOAD_BYTES ?? 32 * 1024 * 1024),
  });
}
