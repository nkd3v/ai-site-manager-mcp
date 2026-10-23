import test from 'node:test';
import assert from 'node:assert/strict';
import { PassThrough } from 'node:stream';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startupEnv } from '../src/config.js';
import { apiFromEnv } from '../src/api.js';

const base = 'https://example.test/wp-json/ai-site-manager/v1';

function terminal(answers) {
  const input = new PassThrough();
  const output = new PassThrough();
  input.isTTY = output.isTTY = true;
  let transcript = '';
  output.on('data', chunk => {
    const text = chunk.toString();
    transcript += text;
    if (/WP_AI_(BASE|KEY_FILE) \(/.test(text)) {
      const answer = answers.shift();
      assert.notEqual(answer, undefined, 'unexpected prompt');
      setImmediate(() => answer === null ? input.end() : input.write(answer));
    }
  });
  return { input, output, transcript: () => transcript };
}

test('prompts for missing URL and key-file path without changing the environment', async () => {
  const tty = terminal([` ${base} \n`, ' /tmp/aism.key \n']);
  const env = {};
  const result = await startupEnv(env, tty);
  assert.deepEqual(result, { WP_AI_BASE: base, WP_AI_KEY_FILE: '/tmp/aism.key' });
  assert.deepEqual(env, {});
  assert.match(tty.transcript(), /WP_AI_BASE/);
  assert.match(tty.transcript(), /WP_AI_KEY_FILE/);
});

test('prompts only for missing values and accepts a direct API key', async () => {
  const urlOnly = terminal([base + '\n']);
  const result = await startupEnv({ WP_AI_KEY: 'test-key' }, urlOnly);
  assert.equal(result.WP_AI_KEY, 'test-key');
  assert.equal(result.WP_AI_BASE, base);
  assert.doesNotMatch(urlOnly.transcript(), /WP_AI_KEY_FILE/);
  const fileOnly = terminal(['/tmp/key\n']);
  assert.equal((await startupEnv({ WP_AI_BASE: base }, fileOnly)).WP_AI_KEY_FILE, '/tmp/key');
  assert.doesNotMatch(fileOnly.transcript(), /WP_AI_BASE/);
});

test('complete noninteractive configuration needs no prompt', async () => {
  for (const credential of [{ WP_AI_KEY: 'test-key' }, { WP_AI_KEY_FILE: '/tmp/key' }]) {
    const env = { WP_AI_BASE: base, ...credential };
    assert.deepEqual(await startupEnv(env, { input: {}, output: {} }), env);
  }
});

test('missing configuration in pipes fails immediately and names missing variables', async () => {
  for (const streams of [{ input: {}, output: { isTTY: true } }, { input: { isTTY: true }, output: {} }]) {
    await assert.rejects(startupEnv({}, streams), /Missing WP_AI_BASE and WP_AI_KEY_FILE/);
    await assert.rejects(startupEnv({ WP_AI_BASE: base }, streams), /Missing WP_AI_KEY_FILE/);
    await assert.rejects(startupEnv({ WP_AI_KEY: 'secret' }, streams), /Missing WP_AI_BASE\./);
  }
});

test('rejects ambiguous credentials before prompting and leaves validation to the API', async () => {
  await assert.rejects(startupEnv({ WP_AI_KEY: 'one', WP_AI_KEY_FILE: '/tmp/key' }, terminal([])), /only one/);
  const env = await startupEnv({ WP_AI_KEY: 'test-key' }, terminal(['http://example.test/ai-site-manager/v1\n']));
  await assert.rejects(apiFromEnv(env), /HTTPS/);
});

test('blank answers are prompted again; Ctrl+C and EOF cancel setup', async () => {
  const tty = terminal(['\n', base + '\n']);
  assert.equal((await startupEnv({ WP_AI_KEY: 'test-key' }, tty)).WP_AI_BASE, base);
  assert.match(tty.transcript(), /WP_AI_BASE is required/);
  for (const answer of ['\x03', null]) {
    await assert.rejects(startupEnv({}, terminal([answer])), /Configuration cancelled/);
  }
});

test('CLI keeps startup failures off stdout and help/version require no configuration', () => {
  const env = Object.fromEntries(Object.entries(process.env).filter(([name]) => !name.startsWith('WP_AI_')));
  const run = args => spawnSync(process.execPath, ['src/cli.js', ...args], { env, encoding: 'utf8', timeout: 5000 });
  const missing = run([]);
  assert.equal(missing.status, 1);
  assert.equal(missing.stdout, '');
  assert.match(missing.stderr, /Missing WP_AI_BASE and WP_AI_KEY_FILE/);
  for (const flag of ['--help', '--version']) {
    const result = run([flag]);
    assert.equal(result.status, 0);
    assert.equal(result.stderr, '');
    assert.ok(result.stdout.trim());
  }
});

test('CLI hands stdin to MCP after prompting and loading the key file', { timeout: 10000 }, async () => {
  const folder = await mkdtemp(join(tmpdir(), 'aism-startup-'));
  const file = join(folder, 'test.key');
  await writeFile(file, 'dummy-test-key\n', { mode: 0o600 });
  const env = Object.fromEntries(Object.entries(process.env).filter(([name]) => !name.startsWith('WP_AI_')));
  // Exercise the actual CLI with terminal flags on controllable child streams.
  const preload = 'process.stdin.isTTY = true; process.stderr.isTTY = true;';
  const child = spawn(process.execPath, ['--import', 'data:text/javascript,' + encodeURIComponent(preload), 'src/cli.js'], { env });
  let stderr = '';
  let stdout = '';
  child.stderr.on('data', chunk => { stderr += chunk; });
  child.stdout.on('data', chunk => { stdout += chunk; });
  const waitFor = (stream, getText, marker) => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => finish(new Error(`Timed out waiting for ${marker}`)), 3000);
    const check = () => { if (getText().includes(marker)) finish(); };
    const exited = () => finish(new Error('CLI exited before ' + marker));
    const finish = error => {
      clearTimeout(timeout);
      stream.off('data', check);
      child.off('exit', exited);
      error ? reject(error) : resolve();
    };
    stream.on('data', check);
    child.once('exit', exited);
    check();
  });
  try {
    await waitFor(child.stderr, () => stderr, 'WP_AI_BASE (');
    child.stdin.write(base + '\n');
    await waitFor(child.stderr, () => stderr, 'WP_AI_KEY_FILE (');
    child.stdin.write(file + '\n');
    await waitFor(child.stderr, () => stderr, 'listening for MCP messages');
    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {
      protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'startup-test', version: '1' },
    } }) + '\n');
    await waitFor(child.stdout, () => stdout, '\n');
    const response = JSON.parse(stdout.trim());
    assert.equal(response.id, 1);
    assert.equal(response.result.serverInfo.name, 'ai-site-manager');
    assert.doesNotMatch(stderr + stdout, /dummy-test-key/);
  } finally {
    child.kill();
    await rm(folder, { recursive: true });
  }
});
