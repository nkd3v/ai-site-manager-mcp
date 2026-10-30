import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Client, InMemoryTransport } from '@modelcontextprotocol/client';
import { SiteManagerApi } from '../src/api.js';
import { SiteRegistry, sitesFromEnv } from '../src/sites.js';
import { createServer } from '../src/server.js';
import * as migration from '../src/migration.js';

const SRC_UUID = '11111111-1111-4111-8111-111111111111';
const DST_UUID = '22222222-2222-4222-8222-222222222222';
const manifest = (uuid, home, extra = {}) => ({
  site_uuid: uuid, name: home, home, siteurl: home, uploads_baseurl: home + '/wp-content/uploads', wordpress_version: '6.8', php_version: '8.3',
  theme: { stylesheet: 'child', template: 'parent' }, themes: [], plugins: [], mu_plugins: [], dropins: [],
  post_types: { post: { total: 2 }, page: { total: 0 }, attachment: { total: 1 }, nav_menu_item: { total: 1 } }, taxonomies: { category: 1 }, comments: 0,
  imported_sources: [], capabilities: { import: true, unfiltered_html: true }, limits: { max_upload_bytes: 1e6 }, not_migrated: [], ...extra,
});
const json = (data, status = 200) => Response.json(data, { status });
const page = (items, total_pages = 1, extra = {}) => ({ items, total: items.length, total_pages, authors: {}, ...extra });

/** A pair of fake sites recording every request. */
function fakeSites({ source = {}, target = {}, delay = 0 } = {}) {
  const log = [];
  const handler = (name, routes) => async (url, options) => {
    const route = url.searchParams.get('rest_route')?.replace('/ai-site-manager/v1', '') ?? url.pathname.replace(/.*\/ai-site-manager\/v1/, '');
    const body = typeof options.body === 'string' ? JSON.parse(options.body) : options.body;
    log.push({ site: name, method: options.method, route, query: Object.fromEntries(url.searchParams), body });
    if (delay) await new Promise(resolve => setTimeout(resolve, delay));
    const handle = routes[`${options.method} ${route}`];
    if (!handle) return json({ code: 'rest_no_route', message: 'No route ' + route }, 404);
    const result = await handle({ url, body, query: Object.fromEntries(url.searchParams), log });
    return result instanceof Response ? result : json(result);
  };
  const src = new SiteManagerApi({ base: 'https://old.test/wp-json/ai-site-manager/v1', key: 'old-secret-key', fetchImpl: handler('old', {
    'GET /migration/manifest': () => manifest(SRC_UUID, 'https://old.test'), ...source,
  }) });
  const dst = new SiteManagerApi({ base: 'https://new.test/wp-json/ai-site-manager/v1', key: 'new-secret-key', fetchImpl: handler('new', {
    'GET /migration/manifest': () => manifest(DST_UUID, 'https://new.test', { post_types: { post: { total: 9 }, attachment: { total: 0 }, nav_menu_item: { total: 0 } } }),
    'POST /migration/begin': () => ({ source: {} }),
    'POST /migration/import': ({ body }) => ({ results: body.items.filter(i => i.type !== 'attachment').map(i => ({ source_id: i.id, target_id: i.id + 100, action: 'created' })), errors: [], needs_file: body.items.filter(i => i.type === 'attachment').map(i => i.id) }),
    'POST /migration/import-file': () => ({ results: [{ source_id: 1, target_id: 101, action: 'created' }], errors: [] }),
    'POST /migration/finalize': () => ({ processed: 3, remaining: 0, unresolved: [], unresolved_count: 0, errors: [] }),
    ...target,
  }) });
  return { src, dst, log };
}

const exportRoutes = {
  'GET /migration/export': ({ query }) => {
    if (query.kind === 'terms') return page([{ id: 5, taxonomy: 'category', name: 'News' }]);
    if (query.kind === 'comments') return page([]);
    if (query.kind === 'site') return page([{ id: 1, options: { blogname: 'Old' } }], 1, { skipped_options: ['weird_object'] });
    if (query.post_type === 'attachment') return page([{ id: 1, type: 'attachment', attachment: { file: '2024/05/a.jpg', url: 'https://old.test/wp-content/uploads/2024/05/a.jpg' } }]);
    if (query.post_type === 'post') return page([{ id: Number(query.page) * 10, type: 'post' }], 2);
    return page([{ id: 30, type: query.post_type }]);
  },
  'GET /migration/file': () => new Response(Buffer.from('JPEGDATA'), { headers: { 'Content-Type': 'image/jpeg', 'Content-Disposition': 'attachment; filename="a.jpg"' } }),
};

test('site registry selects defaults, rejects ambiguity and redacts every key', () => {
  const { src, dst } = fakeSites();
  const two = new SiteRegistry([['old', src], ['new', dst]]);
  assert.throws(() => two.get(), /Several sites/);
  assert.throws(() => two.get('missing'), /Unknown site "missing"/);
  assert.equal(two.get('new'), dst);
  assert.equal(new SiteRegistry([['old', src], ['new', dst]], 'new').get(), dst);
  assert.equal(SiteRegistry.single(src).get(), src);
  assert.throws(() => new SiteRegistry([['bad name', src]]), /Invalid site name/);
  assert.equal(two.redact('old-secret-key and new-secret-key'), '[REDACTED] and [REDACTED]');
  assert.ok(!JSON.stringify(two.list()).includes('secret'));
});

test('sites file configures named sites with key files or env vars and never inline keys', async () => {
  const folder = await mkdtemp(join(tmpdir(), 'aism-sites-'));
  try {
    const keyFile = join(folder, 'old.key');
    await writeFile(keyFile, 'old-key\n');
    const file = join(folder, 'sites.json');
    await writeFile(file, JSON.stringify({ default: 'old', sites: { old: { base: 'https://old.test/wp-json/ai-site-manager/v1', key_file: keyFile }, new: { base: 'https://new.test/?rest_route=/ai-site-manager/v1', key_env: 'NEW_KEY', auth_header: 'X-AISM-Key' } } }));
    const sites = await sitesFromEnv({ WP_AI_SITES_FILE: file, NEW_KEY: 'new-key' });
    assert.deepEqual(sites.names(), ['old', 'new']);
    assert.equal(sites.defaultName, 'old');
    assert.equal(sites.get('new').authHeader, 'X-AISM-Key');
    const both = await sitesFromEnv({ WP_AI_SITES_FILE: file, NEW_KEY: 'new-key', WP_AI_BASE: 'https://env.test/wp-json/ai-site-manager/v1', WP_AI_KEY: 'k', WP_AI_SITE_NAME: 'env' });
    assert.deepEqual(both.names(), ['env', 'old', 'new']);
    assert.equal(both.defaultName, 'old');
    await writeFile(file, JSON.stringify({ sites: { old: { base: 'https://old.test/wp-json/ai-site-manager/v1', key: 'inline' } } }));
    await assert.rejects(sitesFromEnv({ WP_AI_SITES_FILE: file }), /key_file or key_env/);
    await writeFile(file, JSON.stringify({ sites: { new: { base: 'https://new.test/wp-json/ai-site-manager/v1', key_env: 'UNSET_KEY' } } }));
    await assert.rejects(sitesFromEnv({ WP_AI_SITES_FILE: file }), /UNSET_KEY/);
    await writeFile(file, JSON.stringify({ default: 'nope', sites: { old: { base: 'https://old.test/wp-json/ai-site-manager/v1', key_file: keyFile } } }));
    await assert.rejects(sitesFromEnv({ WP_AI_SITES_FILE: file }), /Default site "nope"/);
    await writeFile(file, 'not json');
    await assert.rejects(sitesFromEnv({ WP_AI_SITES_FILE: file }), /JSON/);
    assert.equal((await sitesFromEnv({ WP_AI_BASE: 'https://env.test/wp-json/ai-site-manager/v1', WP_AI_KEY: 'k' })).defaultName, 'default');
  } finally { await rm(folder, { recursive: true }); }
});

test('run migrates every phase in order, transfers original media files and finishes', async () => {
  const { src, dst, log } = fakeSites({ source: exportRoutes });
  const result = await migration.run(src, dst, {});
  assert.equal(result.done, true);
  assert.equal(result.cursor, null);
  assert.equal(result.stats.files, 1);
  assert.ok(result.warnings.some(w => /non-portable/.test(w)));
  const imports = log.filter(r => r.site === 'new' && r.route === '/migration/import').map(r => r.body.kind + ':' + r.body.items.map(i => i.type ?? i.taxonomy ?? 'site').join(','));
  assert.deepEqual(imports, ['terms:category', 'site:site', 'posts:attachment', 'posts:post', 'posts:post', 'posts:nav_menu_item']);
  const begin = log.find(r => r.route === '/migration/begin');
  assert.deepEqual(Object.keys(begin.body.source).sort(), ['home', 'name', 'site_uuid', 'siteurl', 'uploads_baseurl']);
  const upload = log.find(r => r.route === '/migration/import-file');
  assert.equal(upload.body.get('source'), SRC_UUID);
  assert.equal(JSON.parse(upload.body.get('item')).attachment.file, '2024/05/a.jpg');
  assert.equal(upload.body.get('file').name, 'a.jpg');
  assert.equal(upload.body.get('file').type, 'image/jpeg');
  assert.deepEqual(Buffer.from(await upload.body.get('file').arrayBuffer()), Buffer.from('JPEGDATA'));
  assert.equal(log.at(-1).route, '/migration/finalize');
  assert.ok(!log.some(r => r.site === 'old' && r.method !== 'GET'), 'The source site must never be written');
});

test('run stops within its time budget and resumes from the returned cursor', async () => {
  const { src, dst, log } = fakeSites({ source: exportRoutes, delay: 3 });
  const first = await migration.run(src, dst, { max_seconds: 0.001 });
  assert.equal(first.done, false);
  assert.equal(first.cursor.source, SRC_UUID);
  let cursor = first.cursor;
  let rounds = 0;
  while (cursor && rounds++ < 40) cursor = (await migration.run(src, dst, { cursor: JSON.parse(JSON.stringify(cursor)), max_seconds: 0.02 })).cursor;
  assert.equal(cursor, null);
  assert.equal(log.filter(r => r.route === '/migration/begin').length, 1, 'Resuming must not begin again');
  await assert.rejects(migration.run(src, dst, { cursor: { ...first.cursor, source: DST_UUID } }), /cursor/);
  await assert.rejects(migration.run(src, dst, { cursor: { ...first.cursor, phases: ['drop_tables'] } }), /cursor/);
});

test('failed media files are reported once and not retried forever; public URL fallback is used for offloaded media', async () => {
  let downloads = 0;
  let publicFetches = 0;
  const { src, dst } = fakeSites({
    source: { ...exportRoutes, 'GET /migration/file': () => { downloads++; return json({ code: 'aism_file_missing', message: 'offsite' }, 404); } },
    target: { 'POST /migration/import-file': () => json({ code: 'aism_upload_rejected', message: 'Sorry, this file type is not permitted.' }, 400) },
  });
  const original = src.fetchImpl;
  src.fetchImpl = async (url, options) => {
    if (url.hostname === 'old.test' && url.pathname.startsWith('/wp-content/')) { publicFetches++; assert.equal(options.headers.Authorization, undefined); return new Response(Buffer.from('PUBLIC')); }
    return original(url, options);
  };
  const result = await migration.run(src, dst, { phases: ['media'] });
  assert.equal(result.done, true);
  assert.equal(downloads, 1);
  assert.equal(publicFetches, 1);
  assert.equal(result.stats.errors, 1);
  assert.match(result.errors[0].message, /not permitted/);
  assert.deepEqual(result.errors[0].source_id, 1);
});

test('a served -scaled copy is sent with the original, fetched without credentials; failures fall back to the original', async () => {
  const scaled = { id: 1, type: 'attachment', attachment: { file: '2020/12/ภาคใต้.jpg', url: 'https://old.test/wp-content/uploads/2020/12/ภาคใต้-scaled.jpg', urls: { full: 'https://old.test/wp-content/uploads/2020/12/ภาคใต้-scaled.jpg', original: 'https://old.test/wp-content/uploads/2020/12/ภาคใต้.jpg' } } };
  for (const fail of [false, true]) {
    const { src, dst, log } = fakeSites({ source: { ...exportRoutes, 'GET /migration/export': ({ query }) => page(query.post_type === 'attachment' ? [scaled] : []) } });
    const original = src.fetchImpl;
    let publicFetches = 0;
    src.fetchImpl = async (url, options) => {
      if (url.pathname.includes('/wp-content/')) {
        publicFetches++;
        assert.equal(options.headers.Authorization, undefined);
        assert.match(decodeURIComponent(url.pathname), /ภาคใต้-scaled\.jpg$/);
        return fail ? new Response('gone', { status: 404 }) : new Response(Buffer.from('ROTATED'), { headers: { 'Content-Type': 'image/jpeg' } });
      }
      return original(url, options);
    };
    const result = await migration.run(src, dst, { phases: ['media'] });
    const upload = log.find(r => r.route === '/migration/import-file');
    assert.equal(publicFetches, 1);
    assert.equal(upload.body.get('file').name, 'ภาคใต้.jpg');
    if (fail) {
      assert.equal(upload.body.get('attached'), null);
      assert.ok(result.warnings.some(w => /served file/.test(w)));
    } else {
      assert.equal(upload.body.get('attached').name, 'ภาคใต้-scaled.jpg');
      assert.deepEqual(Buffer.from(await upload.body.get('attached').arrayBuffer()), Buffer.from('ROTATED'));
    }
  }
  // Attachments without a separate served copy send only the original.
  const { src, dst, log } = fakeSites({ source: exportRoutes });
  await migration.run(src, dst, { phases: ['media'] });
  assert.equal(log.find(r => r.route === '/migration/import-file').body.get('attached'), null);
});

test('same-site and import-incapable targets are refused before any import', async () => {
  const { src, log } = fakeSites();
  await assert.rejects(migration.run(src, src, {}), /same site/);
  const { src: s2, dst } = fakeSites({ target: { 'GET /migration/manifest': () => manifest(DST_UUID, 'https://new.test', { capabilities: { import: false } }) } });
  await assert.rejects(migration.run(s2, dst, {}), /cannot import/);
  assert.ok(!log.some(r => r.route === '/migration/import'));
});

test('copy follows dependencies, imports terms, media before posts, and defaults to drafts', async () => {
  const { src, dst, log } = fakeSites({ source: {
    'GET /migration/export': ({ query }) => {
      if (query.kind === 'terms') return page(query.ids.split(',').map(id => ({ id: Number(id), taxonomy: 'category' })));
      const items = query.ids.split(',').map(Number).map(id => id === 7
        ? { id, type: 'post', title: 'Wanted', terms: { category: [5] }, dependencies: { posts: [8, 9], terms: [6] } }
        : { id, type: id === 8 ? 'attachment' : 'wp_block', dependencies: { posts: id === 9 ? [7] : [], terms: [] }, attachment: { file: 'x.png' } });
      return page(items);
    },
    'GET /migration/file': () => new Response(Buffer.from('PNG'), { headers: { 'Content-Type': 'image/png' } }),
  } });
  const result = await migration.copy(src, dst, { ids: [7] });
  assert.equal(result.done, true);
  assert.deepEqual(result.copied.map(c => [c.source_id, c.requested]), [[7, true], [8, false], [9, false]]);
  const terms = log.find(r => r.route === '/migration/export' && r.query.kind === 'terms');
  assert.equal(terms.query.ids, '5,6');
  const posts = log.find(r => r.site === 'new' && r.route === '/migration/import' && r.body.kind === 'posts');
  assert.deepEqual(posts.body.items.map(i => i.id), [8, 9, 7]);
  assert.equal(posts.body.options.status, 'draft');
  assert.equal(result.stats.files, 1);
});

test('package transfer installs, updates, activates and handles parents and WordPress.org', async () => {
  const sourceManifest = manifest(SRC_UUID, 'https://old.test', {
    plugins: [{ plugin: 'custom/custom.php', slug: 'custom', version: '2.0.0', active: true }, { plugin: 'ai-site-manager/ai-site-manager.php', slug: 'ai-site-manager', self: true }],
    themes: [{ theme: 'child', template: 'parent', version: '1.0' }, { theme: 'parent', template: 'parent', version: '3.0' }],
  });
  const targetManifest = manifest(DST_UUID, 'https://new.test', { plugins: [{ plugin: 'custom/custom.php', version: '1.0.0', active: false }], themes: [] });
  const zip = () => new Response(Buffer.from('PK'), { headers: { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="pkg.zip"' } });
  const { src, dst, log } = fakeSites({
    source: { 'GET /migration/manifest': () => sourceManifest, 'GET /migration/package': zip },
    target: { 'GET /migration/manifest': () => targetManifest, 'POST /plugins/update': () => ({ updated: true }), 'POST /plugins/action': () => ({ status: 'active' }), 'POST /themes/upload': () => ({ installed: true }), 'POST /themes/install': () => ({ installed: true }) },
  });
  const plugin = await migration.transferPackage(src, dst, { type: 'plugin', id: 'custom', activate: true });
  assert.deepEqual(plugin.steps.map(s => s.action), ['updated', 'activated']);
  const update = log.find(r => r.route === '/plugins/update');
  assert.equal(update.body.get('plugin'), 'custom/custom.php');
  assert.equal(update.body.get('pluginzip').name, 'pkg.zip');
  const theme = await migration.transferPackage(src, dst, { type: 'theme', id: 'child' });
  assert.deepEqual(theme.steps.map(s => s.action), ['parent', 'installed']);
  assert.deepEqual(log.filter(r => r.route === '/migration/package' && r.query.type === 'theme').map(r => r.query.id), ['parent', 'child']);
  await migration.transferPackage(src, dst, { type: 'theme', id: 'parent', from: 'wordpress.org' });
  assert.deepEqual(log.find(r => r.route === '/themes/install').body, { slug: 'parent' });
  await assert.rejects(migration.transferPackage(src, dst, { type: 'plugin', id: 'ai-site-manager' }), /release ZIP/);
  await assert.rejects(migration.transferPackage(src, dst, { type: 'plugin', id: 'missing' }), /not installed/);
});

test('plan reports missing packages, unsupported types, fresh targets and version risks', async () => {
  const { src, dst } = fakeSites({
    source: { 'GET /migration/manifest': () => manifest(SRC_UUID, 'https://old.test', { wordpress_version: '6.9', post_types: { post: { total: 4 }, book: { total: 2 } }, taxonomies: { genre: 3 }, plugins: [{ plugin: 'books/books.php', name: 'Books', version: '1.0', active: true }], themes: [{ theme: 'child', template: 'parent', version: '1', active: true }, { theme: 'parent', template: 'parent', version: '2' }] }) },
    target: { 'GET /migration/manifest': () => manifest(DST_UUID, 'https://new.test', { wordpress_version: '6.8', post_types: { post: { total: 1 }, page: { total: 1 } }, taxonomies: {}, themes: [{ theme: 'parent', version: '1' }] }) },
  });
  const result = await migration.plan(src, dst);
  assert.equal(result.plugins[0].action, 'transfer_and_activate');
  assert.deepEqual(result.themes.map(t => [t.theme, t.action]), [['child', 'transfer'], ['parent', 'update_on_target']]);
  assert.equal(result.post_types.book.supported_on_target, false);
  assert.equal(result.recommended_options.match_slugs, true);
  for (const pattern of [/book/, /genre/, /older than source/, /freshly installed/]) assert.ok(result.warnings.some(w => pattern.test(w)), String(pattern));
});

test('MCP tools route to named sites, refuse same source/target and keep keys out of results', async () => {
  const { src, dst, log } = fakeSites({ source: { 'GET /discovery': () => ({ site: 'old' }) }, target: { 'GET /discovery': () => ({ site: 'new' }), 'GET /migration/status': () => json({ code: 'aism_migration_not_started', message: 'not started' }, 409) } });
  const server = createServer(new SiteRegistry([['old', src], ['new', dst]], 'old'));
  const client = new Client({ name: 'migration-test', version: '1.0.0' });
  const [ct, st] = InMemoryTransport.createLinkedPair();
  try {
    await server.connect(st);
    await client.connect(ct);
    const { tools } = await client.listTools();
    for (const name of ['aism_migration_run', 'aism_migration_copy', 'aism_migration_transfer_package', 'aism_migration_rollback']) assert.equal(tools.find(t => t.name === name).annotations.destructiveHint, true, name);
    for (const name of ['aism_sites', 'aism_migration_plan', 'aism_migration_status', 'aism_migration_manifest', 'aism_migration_export']) assert.equal(tools.find(t => t.name === name).annotations.readOnlyHint, true, name);
    assert.ok(tools.find(t => t.name === 'aism_content_get').inputSchema.properties.site);
    const listed = await client.callTool({ name: 'aism_sites', arguments: {} });
    assert.deepEqual(listed.structuredContent.sites.map(s => [s.name, s.default]), [['old', true], ['new', false]]);
    assert.ok(!JSON.stringify(listed).includes('secret'));
    assert.equal((await client.callTool({ name: 'aism_discover', arguments: {} })).structuredContent.data.site, 'old');
    assert.equal((await client.callTool({ name: 'aism_discover', arguments: { site: 'new' } })).structuredContent.data.site, 'new');
    const unknown = await client.callTool({ name: 'aism_discover', arguments: { site: 'other' } });
    assert.equal(unknown.isError, true);
    assert.equal(unknown.structuredContent.code, 'aism_unknown_site');
    const same = await client.callTool({ name: 'aism_migration_run', arguments: { source: 'old', target: 'old' } });
    assert.equal(same.isError, true);
    const status = await client.callTool({ name: 'aism_migration_status', arguments: { source: 'old', target: 'new' } });
    assert.equal(status.structuredContent.started, false);
    const before = log.length;
    await client.callTool({ name: 'aism_plugin_install', arguments: { site: 'new', slug: 'hello-dolly' } }).catch(() => {});
    assert.deepEqual(log.slice(before).at(-1).body, { slug: 'hello-dolly' }, 'site must not leak into request bodies');
    const rollback = await client.callTool({ name: 'aism_migration_rollback', arguments: { target: 'new' } });
    assert.equal(rollback.isError, true);
    assert.match(rollback.content[0].text, /source or source_uuid/);
  } finally { await client.close(); await server.close(); }
});

test('downloads enforce the size limit and surface JSON errors', async () => {
  const api = new SiteManagerApi({ base: 'https://old.test/wp-json/ai-site-manager/v1', key: 'k', maxUploadBytes: 4, fetchImpl: async url => url.searchParams.get('id') === '1'
    ? new Response(Buffer.from('12345'), { headers: { 'Content-Type': 'image/png' } })
    : json({ code: 'aism_file_missing', message: 'gone' }, 404) });
  await assert.rejects(api.download('/migration/file', { id: 1 }), /exceeds/);
  await assert.rejects(api.download('/migration/file', { id: 2 }), error => error.code === 'aism_file_missing' && error.status === 404);
});
