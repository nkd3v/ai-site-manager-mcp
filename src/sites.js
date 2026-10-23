import { readFile } from 'node:fs/promises';
import { SiteManagerApi, ApiError } from './api.js';

const NAME = /^[a-zA-Z0-9_.-]{1,64}$/;

/** Named AI Site Manager connections. Each site keeps its own key; keys never appear in listings. */
export class SiteRegistry {
  #sites;
  constructor(sites, defaultName) {
    this.#sites = new Map(sites);
    if (!this.#sites.size) throw new Error('Configure at least one site.');
    for (const name of this.#sites.keys()) if (!NAME.test(name)) throw new Error(`Invalid site name "${name}". Use letters, digits, ".", "_" or "-".`);
    if (defaultName !== undefined && !this.#sites.has(defaultName)) throw new Error(`Default site "${defaultName}" is not configured.`);
    this.defaultName = defaultName ?? (this.#sites.size === 1 ? [...this.#sites.keys()][0] : undefined);
  }

  static single(api, name = 'default') { return new SiteRegistry([[name, api]], name); }

  get(name) {
    const chosen = name ?? this.defaultName;
    if (chosen === undefined) throw new ApiError(`Several sites are configured and none is the default. Pass site as one of: ${this.names().join(', ')}.`, 0, 'aism_site_required');
    const api = this.#sites.get(chosen);
    if (!api) throw new ApiError(`Unknown site "${chosen}". Configured sites: ${this.names().join(', ')}.`, 0, 'aism_unknown_site');
    return api;
  }

  names() { return [...this.#sites.keys()]; }

  list() {
    return this.names().map(name => ({ name, base: this.#sites.get(name).base, default: name === this.defaultName }));
  }

  redact(text) {
    let result = String(text);
    for (const api of this.#sites.values()) result = api.redact(result);
    return result;
  }
}

/**
 * WP_AI_SITES_FILE points to JSON such as
 * {"default":"old","sites":{"old":{"base":"https://…/ai-site-manager/v1","key_file":"/path/old.key"},"new":{…,"key_env":"NEW_KEY"}}}.
 * WP_AI_BASE with WP_AI_KEY/WP_AI_KEY_FILE adds a site named WP_AI_SITE_NAME (default "default").
 */
export async function sitesFromEnv(env = process.env) {
  const common = { timeout: Number(env.WP_AI_TIMEOUT_MS ?? 120_000), maxUploadBytes: Number(env.WP_AI_MAX_UPLOAD_BYTES ?? 32 * 1024 * 1024) };
  const sites = [];
  let defaultName;
  if (env.WP_AI_SITES_FILE?.trim()) {
    let config;
    try { config = JSON.parse(await readFile(env.WP_AI_SITES_FILE.trim(), 'utf8')); }
    catch (error) { throw new Error(`Could not read WP_AI_SITES_FILE as JSON: ${error.code ?? error.message}`); }
    if (!config || typeof config.sites !== 'object' || Array.isArray(config.sites)) throw new Error('WP_AI_SITES_FILE must contain a "sites" object.');
    for (const [name, site] of Object.entries(config.sites)) {
      if (!site || typeof site !== 'object') throw new Error(`Site "${name}" must be an object.`);
      if ('key' in site) throw new Error(`Site "${name}": store keys in key_file or key_env, not in the sites file.`);
      if (Boolean(site.key_file) === Boolean(site.key_env)) throw new Error(`Site "${name}" needs exactly one of key_file or key_env.`);
      const key = site.key_file ? (await readFile(site.key_file, 'utf8')).trim() : env[site.key_env];
      if (!key) throw new Error(`Site "${name}": the key is empty or ${site.key_env ?? 'its key file'} is unset.`);
      sites.push([name, new SiteManagerApi({ base: site.base ?? '', key, authHeader: site.auth_header ?? env.WP_AI_AUTH_HEADER ?? 'Authorization', ...common })]);
    }
    defaultName = config.default;
  }
  if (env.WP_AI_BASE?.trim() || !sites.length) {
    if (env.WP_AI_KEY && env.WP_AI_KEY_FILE) throw new Error('Set only one of WP_AI_KEY and WP_AI_KEY_FILE.');
    const key = env.WP_AI_KEY_FILE ? (await readFile(env.WP_AI_KEY_FILE, 'utf8')).trim() : env.WP_AI_KEY;
    const name = env.WP_AI_SITE_NAME?.trim() || 'default';
    if (sites.some(([existing]) => existing === name)) throw new Error(`Site "${name}" is configured twice; set WP_AI_SITE_NAME to another name.`);
    sites.unshift([name, new SiteManagerApi({ base: env.WP_AI_BASE ?? '', key, authHeader: env.WP_AI_AUTH_HEADER ?? 'Authorization', ...common })]);
    defaultName ??= name;
  }
  return new SiteRegistry(sites, defaultName);
}
