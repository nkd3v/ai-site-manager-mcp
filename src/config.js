import { createInterface } from 'node:readline/promises';

export async function startupEnv(env = process.env, { input = process.stdin, output = process.stderr } = {}) {
  const configured = { ...env };
  if (configured.WP_AI_KEY && configured.WP_AI_KEY_FILE) {
    throw new Error('Set only one of WP_AI_KEY and WP_AI_KEY_FILE.');
  }
  const missing = [];
  if (!configured.WP_AI_BASE?.trim()) missing.push('WP_AI_BASE');
  if (!configured.WP_AI_KEY && !configured.WP_AI_KEY_FILE?.trim()) missing.push('WP_AI_KEY_FILE');
  if (!missing.length) return configured;
  if (!input.isTTY || !output.isTTY) {
    throw new Error(`Missing ${missing.join(' and ')}. Set these in your MCP client's environment, or run ai-site-manager-mcp in a terminal to enter them. WP_AI_KEY can replace WP_AI_KEY_FILE.`);
  }

  // Never read a prompt answer from a pipe: stdin belongs to the MCP transport.
  const prompt = createInterface({ input, output });
  const controller = new AbortController();
  const cancel = () => controller.abort();
  prompt.once('SIGINT', cancel);
  prompt.once('close', cancel);
  try {
    for (const name of missing) {
      const hint = name === 'WP_AI_BASE' ? 'HTTPS API namespace URL' : 'path to a plain-text API key file';
      let value;
      do {
        value = (await prompt.question(`${name} (${hint}): `, { signal: controller.signal })).trim();
        if (!value) output.write(`${name} is required.\n`);
      } while (!value);
      configured[name] = value;
    }
    return configured;
  } catch (error) {
    if (controller.signal.aborted) throw new Error('Configuration cancelled. Set WP_AI_BASE and WP_AI_KEY_FILE (or WP_AI_KEY) before restarting.');
    throw error;
  } finally {
    prompt.close();
  }
}
