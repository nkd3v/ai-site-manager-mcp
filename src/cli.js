#!/usr/bin/env node
import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { sitesFromEnv } from './sites.js';
import { startupEnv } from './config.js';
import { createServer, VERSION } from './server.js';

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log(`AI Site Manager MCP ${VERSION}

Usage: ai-site-manager-mcp [--help | --version]

Starts an MCP server over stdio. Configure WP_AI_BASE and WP_AI_KEY
(or WP_AI_KEY_FILE pointing to a plain-text key file).
Missing WP_AI_BASE and WP_AI_KEY_FILE are prompted for in a terminal.
For MCP clients without a terminal, supply them in the client environment.

For several sites (for example to migrate between them), set WP_AI_SITES_FILE
to a JSON file listing named sites with key_file or key_env entries.

Optional: WP_AI_AUTH_HEADER, WP_AI_TIMEOUT_MS, WP_AI_MAX_UPLOAD_BYTES, WP_AI_SITE_NAME.
See https://github.com/nkd3v/ai-site-manager-mcp for configuration.`);
} else if (process.argv.includes('--version')) {
  console.log(VERSION);
} else {
  try {
    const sites = await sitesFromEnv(await startupEnv());
    await serveStdio(() => createServer(sites));
    // readline closes by pausing stdin; resume only after the transport owns it.
    process.stdin.resume();
    if (process.stdin.isTTY && process.stderr.isTTY) {
      console.error('AI Site Manager MCP: listening for MCP messages on stdin.');
    }
  } catch (error) {
    // Startup errors contain no environment values or credentials.
    console.error('AI Site Manager MCP: ' + (error instanceof TypeError ? 'Invalid WP_AI_BASE URL.' : error.message));
    process.exitCode = 1;
  }
}
