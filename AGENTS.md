# Repository instructions

- Every push to `main` must include a version increase. Use a patch bump by default, or a minor/major bump when the change requires it. Feature branches must include the intended version bump before merging to `main`.
- Keep `package.json`, both root version fields in `package-lock.json`, the WordPress plugin header, `AISM_VERSION`, and its readme stable tag synchronized. Add a companion changelog entry and rebuild `releases/ai-site-manager.zip` with `uv run --system-certs scripts/package-plugin.py`.
- After each push to `main`, ensure the Release workflow succeeds and publishes a new GitHub release tagged `v<version>` at the pushed commit. Verify the MCP tarball, WordPress upload ZIP, and `SHA256SUMS` are attached. Do not report the push complete while the corresponding release is missing or failed. Do not move or replace an existing release tag to reuse a version.
- Run `npm run check`, `npm test`, `npm run release:check`, and inspect `npm pack --dry-run` before publishing. Use `uv` with system certificates for Python tasks.
- Create commits as `nkd3v` using GitHub's GraphQL `createCommitOnBranch` API and verify GitHub's valid GPG signature. Do not substitute unsigned commits or local signing. Check Git status and review only intended changes before publishing.
- Use CLIs, supported APIs, and MCP tools; do not use Computer Use. Never commit credentials or local environment files.
