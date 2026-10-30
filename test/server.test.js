import test from 'node:test';
import assert from 'node:assert/strict';
import { Client, InMemoryTransport } from '@modelcontextprotocol/client';
import { createServer } from '../src/server.js';
import { SiteManagerApi } from '../src/api.js';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

test('real MCP handshake, tool schemas, validation, defaults, errors and guide resource', async () => {
  const requests = [];
  const api = new SiteManagerApi({ base: 'https://example.test/wp-json/ai-site-manager/v1', key: 'test-key', fetchImpl: async (url, options) => {
    requests.push({ url, ...options });
    if (url.pathname.endsWith('/guide')) return Response.json({ format: 'markdown', guide: '# Site guide' });
    if (url.pathname.endsWith('/settings/siteurl')) return Response.json({ code: 'aism_option_not_allowed', message: 'Denied test-key' }, { status: 403 });
    return Response.json({ id: 42, scopes: ['content:read'] });
  } });
  const server = createServer(api);
  const client = new Client({ name: 'test-client', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  try {
    await server.connect(serverTransport);
    await client.connect(clientTransport);
    const listing = await client.listTools();
    assert.equal(listing.tools.length, 42);
    assert.equal(listing.tools.find(t => t.name === 'aism_discover').annotations.readOnlyHint, true);
    assert.equal(listing.tools.find(t => t.name === 'aism_content_delete').annotations.destructiveHint, true);
    const discovery = await client.callTool({ name: 'aism_discover', arguments: {} });
    assert.equal(discovery.structuredContent.status, 200);
    assert.ok(requests.at(-1).url.pathname.endsWith('/discovery'));
    await client.callTool({ name: 'aism_content_create', arguments: { resource: 'posts', data: { title: 'Draft' } } });
    assert.deepEqual(JSON.parse(requests.at(-1).body), { status: 'draft', title: 'Draft' });
    await client.callTool({ name: 'aism_content_update', arguments: { resource: 'posts', id: 42, data: { title: 'Changed' }, method: 'PATCH' } });
    assert.equal(requests.at(-1).method, 'PATCH');
    assert.deepEqual(JSON.parse(requests.at(-1).body), { title: 'Changed' });
    await client.callTool({ name: 'aism_content_delete', arguments: { resource: 'posts', id: 42 } });
    assert.equal(requests.at(-1).url.searchParams.get('force'), 'false');
    await client.callTool({ name: 'aism_settings_update', arguments: { option: 'blogdescription', value: 'New description' } });
    assert.deepEqual(JSON.parse(requests.at(-1).body), { value: 'New description' });
    const denied = await client.callTool({ name: 'aism_settings_get', arguments: { option: 'siteurl' } });
    assert.equal(denied.isError, true);
    assert.equal(denied.structuredContent.code, 'aism_option_not_allowed');
    assert.ok(!JSON.stringify(denied).includes('test-key'));
    const count = requests.length;
    // Client/SDK versions can surface invalid arguments as an error result or rejection.
    for (const args of [{ resource: 'users', id: 1 }, { resource: '../users', id: 1 }, { resource: 'posts', id: -1 }]) {
      try {
        const result = await client.callTool({ name: 'aism_content_get', arguments: args });
        assert.equal(result.isError, true);
      } catch (error) { assert.match(error.message, /valid|resource|positive|greater|small|integer/i); }
    }
    assert.equal(requests.length, count);
    const resources = await client.listResources();
    assert.equal(resources.resources[0].uri, 'aism://guide');
    const guide = await client.readResource({ uri: 'aism://guide' });
    assert.equal(guide.contents[0].text, '# Site guide');
  } finally { await client.close(); await server.close(); }
});

test('theme tools expose native schema, ZIP upload, actions and destructive hints through MCP', async () => {
  const requests = [];
  const api = new SiteManagerApi({ base: 'https://example.test/wp-json/ai-site-manager/v1', key: 'test-key', fetchImpl: async (url, options) => {
    requests.push({ url, ...options });
    if (url.pathname.endsWith('/themes/action') && JSON.parse(options.body).action === 'delete') {
      return Response.json({ code: 'aism_active_theme', message: 'Switch themes first.' }, { status: 409 });
    }
    return Response.json({ theme: 'example', status: 'inactive' });
  } });
  const server = createServer(api);
  const client = new Client({ name: 'theme-test', version: '1.0.0' });
  const [ct, st] = InMemoryTransport.createLinkedPair();
  const folder = await mkdtemp(join(tmpdir(), 'aism-theme-'));
  try {
    await server.connect(st);
    await client.connect(ct);
    const { tools } = await client.listTools();
    assert.equal(tools.find(t => t.name === 'aism_themes_list').annotations.readOnlyHint, true);
    for (const name of ['aism_theme_install', 'aism_theme_upload', 'aism_theme_action']) {
      assert.equal(tools.find(t => t.name === name).annotations.readOnlyHint, false);
      assert.equal(tools.find(t => t.name === name).annotations.destructiveHint, true);
    }
    await client.callTool({ name: 'aism_schema', arguments: { resource: 'themes' } });
    assert.equal(requests.at(-1).url.searchParams.get('resource'), 'themes');
    await client.callTool({ name: 'aism_themes_list', arguments: {} });
    assert.equal(requests.at(-1).method, 'GET');
    assert.ok(requests.at(-1).url.pathname.endsWith('/themes'));
    await client.callTool({ name: 'aism_theme_install', arguments: { slug: 'twentytwentyone' } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/themes/install'));
    assert.deepEqual(JSON.parse(requests.at(-1).body), { slug: 'twentytwentyone' });
    const file = join(folder, 'theme.zip');
    const bytes = Buffer.from('fixture ZIP bytes');
    await writeFile(file, bytes);
    await client.callTool({ name: 'aism_theme_upload', arguments: { file_path: file } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/themes/upload'));
    const upload = requests.at(-1).body.get('themezip');
    assert.equal(upload.name, 'theme.zip');
    assert.deepEqual(Buffer.from(await upload.arrayBuffer()), bytes);
    for (const action of ['activate', 'update', 'delete']) {
      const result = await client.callTool({ name: 'aism_theme_action', arguments: { theme: 'collection/example', action } });
      assert.equal(requests.at(-1).method, 'POST');
      assert.ok(requests.at(-1).url.pathname.endsWith('/themes/action'));
      assert.deepEqual(JSON.parse(requests.at(-1).body), { theme: 'collection/example', action });
      if (action === 'delete') {
        assert.equal(result.isError, true);
        assert.equal(result.structuredContent.code, 'aism_active_theme');
        assert.equal(result.structuredContent.status, 409);
      }
    }
    const count = requests.length;
    for (const [name, args] of [
      ['aism_theme_install', { slug: '../example' }],
      ['aism_theme_action', { theme: 'example', action: 'deactivate' }],
      ['aism_content_get', { resource: 'themes', id: 1 }],
    ]) {
      const result = await client.callTool({ name, arguments: args });
      assert.equal(result.isError, true);
    }
    assert.equal(requests.length, count);
  } finally {
    await client.close(); await server.close();
    await rm(folder, { recursive: true });
  }
});

test('plugin update tool sends advertised updates or explicit local ZIP targets', async () => {
  const requests = [];
  const api = new SiteManagerApi({ base: 'https://example.test/wp-json/ai-site-manager/v1', key: 'test-key', fetchImpl: async (url, options) => {
    requests.push({ url, ...options });
    return Response.json({ plugin: 'example/example.php', updated: true, version: '2.0.0', active: true });
  } });
  const server = createServer(api);
  const client = new Client({ name: 'plugin-update-test', version: '1.0.0' });
  const [ct, st] = InMemoryTransport.createLinkedPair();
  const folder = await mkdtemp(join(tmpdir(), 'aism-plugin-update-'));
  try {
    await server.connect(st);
    await client.connect(ct);
    const { tools } = await client.listTools();
    const tool = tools.find(t => t.name === 'aism_plugin_update');
    assert.equal(tool.annotations.readOnlyHint, false);
    assert.equal(tool.annotations.destructiveHint, true);
    assert.deepEqual(tool.inputSchema.required, ['plugin']);
    const advertised = await client.callTool({ name: tool.name, arguments: { plugin: 'example/example' } });
    assert.equal(advertised.structuredContent.data.updated, true);
    assert.ok(requests.at(-1).url.pathname.endsWith('/plugins/action'));
    assert.deepEqual(JSON.parse(requests.at(-1).body), { plugin: 'example/example', action: 'update' });
    const file = join(folder, 'example.zip');
    const bytes = Buffer.from('ZIP fixture bytes');
    await writeFile(file, bytes);
    const uploaded = await client.callTool({ name: tool.name, arguments: { plugin: 'example/example.php', file_path: file } });
    assert.equal(uploaded.structuredContent.data.active, true);
    assert.equal(requests.at(-1).method, 'POST');
    assert.ok(requests.at(-1).url.pathname.endsWith('/plugins/update'));
    assert.equal(requests.at(-1).body.get('plugin'), 'example/example.php');
    assert.deepEqual(Buffer.from(await requests.at(-1).body.get('pluginzip').arrayBuffer()), bytes);
    const count = requests.length;
    const invalid = await client.callTool({ name: tool.name, arguments: { file_path: file } });
    assert.equal(invalid.isError, true);
    const wrongType = join(folder, 'example.txt');
    await writeFile(wrongType, bytes);
    const rejected = await client.callTool({ name: tool.name, arguments: { plugin: 'example/example', file_path: wrongType } });
    assert.equal(rejected.isError, true);
    assert.equal(requests.length, count);
  } finally {
    await client.close(); await server.close();
    await rm(folder, { recursive: true });
  }
});

test('user tools review accounts and quarantine or restore one by ID', async () => {
  const requests = [];
  const api = new SiteManagerApi({ base: 'https://example.test/wp-json/ai-site-manager/v1', key: 'test-key', fetchImpl: async (url, options) => {
    requests.push({ url, ...options });
    if (url.pathname.endsWith('/users')) return Response.json([{ id: 3, login: 'w2s_x', roles: ['administrator'] }], { headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' } });
    return Response.json({ quarantined: true, changed: true });
  } });
  const server = createServer(api);
  const client = new Client({ name: 'users-test', version: '1.0.0' });
  const [ct, st] = InMemoryTransport.createLinkedPair();
  try {
    await server.connect(st);
    await client.connect(ct);
    const { tools } = await client.listTools();
    const byName = Object.fromEntries(tools.map(t => [t.name, t]));
    assert.equal(byName.aism_users_list.annotations.readOnlyHint, true);
    assert.equal(byName.aism_user_get.annotations.readOnlyHint, true);
    for (const name of ['aism_user_quarantine', 'aism_user_restore']) {
      assert.equal(byName[name].annotations.readOnlyHint, false);
      assert.equal(byName[name].annotations.destructiveHint, true);
    }
    const listed = await client.callTool({ name: 'aism_users_list', arguments: { role: 'administrator' } });
    assert.equal(listed.structuredContent.headers['X-WP-Total'], '1');
    assert.equal(requests.at(-1).method, 'GET');
    assert.equal(requests.at(-1).url.searchParams.get('role'), 'administrator');
    assert.equal(requests.at(-1).url.searchParams.get('per_page'), '20');
    assert.equal(requests.at(-1).url.searchParams.has('search'), false);
    await client.callTool({ name: 'aism_user_get', arguments: { id: 3 } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/users/3'));
    const quarantined = await client.callTool({ name: 'aism_user_quarantine', arguments: { id: 3, reason: 'wp2shell account' } });
    assert.equal(quarantined.structuredContent.data.quarantined, true);
    assert.equal(requests.at(-1).method, 'POST');
    assert.ok(requests.at(-1).url.pathname.endsWith('/users/3/quarantine'));
    assert.deepEqual(JSON.parse(requests.at(-1).body), { reason: 'wp2shell account' });
    await client.callTool({ name: 'aism_user_restore', arguments: { id: 3 } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/users/3/restore'));
    const count = requests.length;
    assert.equal((await client.callTool({ name: 'aism_user_quarantine', arguments: { id: 0 } })).isError, true);
    assert.equal((await client.callTool({ name: 'aism_users_list', arguments: { role: '../x' } })).isError, true);
    assert.equal(requests.length, count);
  } finally {
    await client.close(); await server.close();
  }
});

test('database, diagnostics and maintenance tools send bounded requests with correct hints', async () => {
  const requests = [];
  const api = new SiteManagerApi({ base: 'https://example.test/wp-json/ai-site-manager/v1', key: 'test-key', fetchImpl: async (url, options) => {
    requests.push({ url, ...options });
    if (url.pathname.endsWith('/database/query') && JSON.parse(options.body).sql.includes('wp_users')) {
      return Response.json({ code: 'aism_sql_protected', message: 'Queries cannot reference wp_users.' }, { status: 403 });
    }
    return Response.json({ ok: true });
  } });
  const server = createServer(api);
  const client = new Client({ name: 'test-client', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  try {
    await server.connect(serverTransport);
    await client.connect(clientTransport);
    const tools = Object.fromEntries((await client.listTools()).tools.map(t => [t.name, t]));
    for (const name of ['aism_db_tables', 'aism_db_table', 'aism_db_query', 'aism_diagnostics', 'aism_cron_list', 'aism_logs']) {
      assert.equal(tools[name].annotations.readOnlyHint, true, name);
    }
    assert.equal(tools.aism_maintenance.annotations.readOnlyHint, false);
    assert.equal(tools.aism_maintenance.annotations.destructiveHint, false);

    await client.callTool({ name: 'aism_db_tables', arguments: {} });
    assert.equal(requests.at(-1).method, 'GET');
    assert.ok(requests.at(-1).url.pathname.endsWith('/database/tables'));
    await client.callTool({ name: 'aism_db_table', arguments: { table: 'wp_traffic_log' } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/database/tables/wp_traffic_log'));
    await client.callTool({ name: 'aism_db_query', arguments: { sql: 'SELECT COUNT(*) FROM wp_traffic_log' } });
    assert.equal(requests.at(-1).method, 'POST');
    assert.deepEqual(JSON.parse(requests.at(-1).body), { sql: 'SELECT COUNT(*) FROM wp_traffic_log', max_rows: 100, max_value_bytes: 4096, timeout_seconds: 10 });
    const denied = await client.callTool({ name: 'aism_db_query', arguments: { sql: 'SELECT * FROM wp_users' } });
    assert.equal(denied.isError, true);
    assert.equal(denied.structuredContent.code, 'aism_sql_protected');

    await client.callTool({ name: 'aism_diagnostics', arguments: {} });
    assert.equal(requests.at(-1).url.searchParams.has('site_health'), false);
    await client.callTool({ name: 'aism_diagnostics', arguments: { site_health: true } });
    assert.equal(requests.at(-1).url.searchParams.get('site_health'), '1');
    await client.callTool({ name: 'aism_cron_list', arguments: {} });
    assert.ok(requests.at(-1).url.pathname.endsWith('/diagnostics/cron'));
    await client.callTool({ name: 'aism_logs', arguments: { search: 'Fatal' } });
    assert.ok(requests.at(-1).url.pathname.endsWith('/diagnostics/logs'));
    assert.deepEqual(Object.fromEntries(requests.at(-1).url.searchParams), { source: 'debug', lines: '200', search: 'Fatal' });
    await client.callTool({ name: 'aism_maintenance', arguments: { action: 'run_cron', hook: 'traffic_log_analyze_history' } });
    assert.deepEqual(JSON.parse(requests.at(-1).body), { action: 'run_cron', hook: 'traffic_log_analyze_history' });
    await client.callTool({ name: 'aism_maintenance', arguments: { action: 'flush_cache' } });
    assert.deepEqual(JSON.parse(requests.at(-1).body), { action: 'flush_cache' });

    const count = requests.length;
    for (const [name, args] of [
      ['aism_db_query', { sql: 'SELECT 1', max_rows: 5000 }], ['aism_db_query', { sql: '' }], ['aism_db_table', { table: '../wp_users' }],
      ['aism_logs', { source: 'wp-config' }], ['aism_logs', { lines: 0 }], ['aism_maintenance', { action: 'drop_database' }], ['aism_maintenance', { action: 'run_cron', key: 'not-a-key' }],
    ]) {
      try {
        const result = await client.callTool({ name, arguments: args });
        assert.equal(result.isError, true, name);
      } catch (error) { assert.match(error.message, /valid|invalid|small|big|enum|expected|too/i); }
    }
    assert.equal(requests.length, count);
  } finally { await client.close(); await server.close(); }
});
