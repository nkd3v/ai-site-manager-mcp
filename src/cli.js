#!/usr/bin/env node
import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { apiFromEnv } from './api.js';
import { createServer, VERSION } from './server.js';

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log(`AI Site Manager MCP ${VERSION}

Usage: ai-site-manager-mcp [--help | --version]

Starts an MCP server over stdio. Configure WP_AI_BASE and WP_AI_KEY
(or WP_AI_KEY_FILE pointing to a plain-text key file).

Optional: WP_AI_AUTH_HEADER, WP_AI_TIMEOUT_MS, WP_AI_MAX_UPLOAD_BYTES.
See https://github.com/nkd3v/ai-site-manager-mcp for configuration.`);
} else if (process.argv.includes('--version')) {
  console.log(VERSION);
} else {
  try {
    const api = await apiFromEnv();
    await serveStdio(() => createServer(api));
  } catch (error) {
    // Startup errors contain no environment values or credentials.
    console.error('AI Site Manager MCP: ' + (error instanceof TypeError ? 'Invalid WP_AI_BASE URL.' : error.message));
    process.exitCode = 1;
  }
}
