import test from 'node:test';
import assert from 'node:assert/strict';
import { Client, InMemoryTransport } from '@modelcontextprotocol/client';
import { createServer } from '../src/server.js';
import { SiteManagerApi } from '../src/api.js';

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
    assert.equal(listing.tools.length, 17);
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
