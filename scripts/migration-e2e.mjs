#!/usr/bin/env node
// Full two-site migration through the real MCP server, for DISPOSABLE LOCAL WordPress sites only.
//
// Start two isolated sites from the companion's Compose file (run from this repository):
//   for p in aism-src aism-dst; do docker compose -p $p -f wordpress-plugin/ai-site-manager/tests/compose.yaml up -d; done
//   docker compose -p aism-src -f wordpress-plugin/ai-site-manager/tests/compose.yaml port wordpress 80   # source PORT
// Install WordPress on each (see wordpress-plugin/ai-site-manager/tests/README.md, using --url=http://localhost:PORT),
// activate ai-site-manager, add content to the source, and create a key with all scopes on each into a key file, e.g.
//   docker exec --user 33:33 aism-src-wordpress-1 php -r 'require "/var/www/html/wp-load.php"; echo AISM_Store::create_key("e2e", 1, AISM_Store::scopes(), array(), 1)["token"];' > /tmp/src.key
// Then: node scripts/migration-e2e.mjs SRC_PORT /tmp/src.key DST_PORT /tmp/dst.key
//
// The local sites use the companion's HTTP test exception; this script maps https://*.aism.test to them.
import { readFileSync } from 'node:fs';
import { Client, InMemoryTransport } from '@modelcontextprotocol/client';
import { SiteManagerApi } from '../src/api.js';
import { SiteRegistry } from '../src/sites.js';
import { createServer } from '../src/server.js';

const [srcPort, srcKey, dstPort, dstKey] = process.argv.slice(2);
if (!dstKey) { console.error('Usage: node scripts/migration-e2e.mjs SRC_PORT SRC_KEY_FILE DST_PORT DST_KEY_FILE'); process.exit(2); }
const local = port => (url, options) => {
  const target = new URL(url);
  if (target.hostname.endsWith('.aism.test')) { target.protocol = 'http:'; target.host = '127.0.0.1:' + port; }
  if (!['127.0.0.1', 'localhost'].includes(target.hostname)) throw new Error('Refusing a non-local request: ' + target.origin);
  return fetch(target, options);
};
const site = (name, port, keyFile) => new SiteManagerApi({ base: `https://${name}.aism.test/?rest_route=/ai-site-manager/v1`, key: readFileSync(keyFile, 'utf8').trim(), fetchImpl: local(port), timeout: 300_000 });
const server = createServer(new SiteRegistry([['source', site('source', srcPort, srcKey)], ['target', site('target', dstPort, dstKey)]]));
const client = new Client({ name: 'migration-e2e', version: '1' });
const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
await server.connect(serverTransport);
await client.connect(clientTransport);
const call = async (name, args) => {
  const result = await client.callTool({ name, arguments: args });
  if (result.isError) throw new Error(`${name}: ${result.content[0].text}`);
  return result.structuredContent;
};
const pair = { source: 'source', target: 'target' };
let failures = 0;
try {
  const plan = await call('aism_migration_plan', pair);
  console.log('Plan warnings:', plan.warnings);
  for (const theme of plan.themes.filter(t => t.action === 'transfer' && !t.parent)) console.log('theme', theme.theme, (await call('aism_migration_transfer_package', { ...pair, type: 'theme', id: theme.theme })).steps.map(s => s.action));
  for (const theme of plan.themes.filter(t => t.action === 'transfer' && t.parent)) console.log('theme', theme.theme, (await call('aism_migration_transfer_package', { ...pair, type: 'theme', id: theme.theme })).steps.map(s => s.action));
  for (const plugin of plan.plugins.filter(p => /transfer|update|activate/.test(p.action))) {
    console.log('plugin', plugin.plugin, (await call('aism_migration_transfer_package', { ...pair, type: 'plugin', id: plugin.plugin, activate: plugin.active_on_source })).steps.map(s => s.action));
  }
  const active = plan.themes.find(t => t.active_on_source);
  if (active && !active.active_on_target) await call('aism_theme_action', { site: 'target', theme: active.theme, action: 'activate' });
  let cursor;
  let rounds = 0;
  do {
    const run = await call('aism_migration_run', { ...pair, ...plan.recommended_options, max_seconds: 60, ...(cursor ? { cursor } : {}) });
    console.log(`run ${++rounds}: phase=${run.phase} stats=${JSON.stringify(run.stats)}`);
    for (const message of [...run.warnings, ...run.errors.map(e => JSON.stringify(e)), ...run.unresolved_references.map(u => 'unresolved ' + JSON.stringify(u))]) console.log('  ', message);
    failures += run.errors.length;
    cursor = run.cursor;
  } while (cursor && rounds < 100);
  const status = await call('aism_migration_status', pair);
  for (const row of status.post_types) {
    if (row.on_target < row.source) { failures++; console.log('MISSING', row); }
  }
  console.log('Status:', JSON.stringify({ finalized: status.finalized, terms: status.terms, comments: status.comments }));
  if (!status.finalized) failures++;
} finally {
  await client.close();
  await server.close();
}
console.log(failures ? `FAIL: ${failures} problems` : 'PASS: migration completed without errors');
process.exitCode = failures ? 1 : 0;
