import { ApiError } from './api.js';

// Site settings come before media so the source's image sizes apply when media sizes are generated.
export const PHASES = ['terms', 'site', 'media', 'content', 'comments', 'finalize'];
const MAX_ERRORS = 50;

const strip = url => String(url ?? '').replace(/\/+$/, '');

/** Compare dotted versions numerically; non-numeric parts compare as text. */
export function compareVersions(a, b) {
  const left = String(a ?? '').split(/[.+-]/);
  const right = String(b ?? '').split(/[.+-]/);
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const [x, y] = [left[i] ?? '0', right[i] ?? '0'];
    const [nx, ny] = [Number(x), Number(y)];
    const diff = Number.isFinite(nx) && Number.isFinite(ny) ? nx - ny : x.localeCompare(y);
    if (diff) return Math.sign(diff);
  }
  return 0;
}

export async function manifest(api) {
  return (await api.request('GET', '/migration/manifest')).data;
}

function sourceRecord(sm) {
  return { site_uuid: sm.site_uuid, name: sm.name, home: sm.home, siteurl: sm.siteurl, uploads_baseurl: sm.uploads_baseurl };
}

async function begin(target, sm, tm) {
  if (sm.site_uuid === tm.site_uuid && strip(sm.home) === strip(tm.home)) throw new ApiError('Source and target are the same site.', 0, 'aism_same_site');
  if (!tm.capabilities?.import) throw new ApiError('The target key owner cannot import: it needs manage_options and import on single-site WordPress.', 403, 'aism_migration_forbidden');
  return (await target.request('POST', '/migration/begin', { body: { source: sourceRecord(sm) } })).data;
}

export async function plan(source, target) {
  const [sm, tm] = await Promise.all([manifest(source), manifest(target)]);
  const warnings = [];
  if (sm.site_uuid === tm.site_uuid && strip(sm.home) === strip(tm.home)) warnings.push('Source and target are the same site; migration is refused.');
  if (!tm.capabilities?.import) warnings.push('The target key owner cannot import (needs manage_options and import, single-site only).');
  if (!tm.capabilities?.unfiltered_html) warnings.push('The target key owner lacks unfiltered_html: content is filtered by WordPress, most metadata is skipped and site settings cannot be imported.');
  if (compareVersions(tm.wordpress_version, sm.wordpress_version) < 0) warnings.push(`Target WordPress ${tm.wordpress_version} is older than source ${sm.wordpress_version}; newer block markup may not be supported.`);
  if (compareVersions(tm.php_version, sm.php_version) < 0) warnings.push(`Target PHP ${tm.php_version} is older than source ${sm.php_version}; check plugin and theme requirements.`);
  if (sm.mu_plugins?.length || sm.dropins?.length) warnings.push(`Copy must-use plugins (${sm.mu_plugins.join(', ') || 'none'}) and drop-ins (${sm.dropins.join(', ') || 'none'}) manually if the site needs them.`);

  const needed = new Set([sm.theme.stylesheet, sm.theme.template]);
  const themes = sm.themes.map(theme => {
    const existing = tm.themes.find(t => t.theme === theme.theme);
    let action = 'none';
    if (!existing) action = needed.has(theme.theme) ? 'transfer' : 'optional';
    else if (compareVersions(existing.version, theme.version) < 0) action = 'update_on_target';
    return { theme: theme.theme, source_version: theme.version, target_version: existing?.version ?? null, parent: theme.template !== theme.theme ? theme.template : null, active_on_source: theme.active, active_on_target: existing?.active ?? false, required: needed.has(theme.theme), action };
  }).filter(theme => theme.required || theme.action !== 'optional');

  const plugins = sm.plugins.filter(p => !p.self).map(plugin => {
    const existing = tm.plugins.find(p => p.plugin === plugin.plugin);
    let action = 'none';
    if (!existing) action = plugin.plugin.includes('/') ? 'transfer' : 'manual_single_file';
    else if (compareVersions(existing.version, plugin.version) < 0) action = 'update';
    if (plugin.active && !existing?.active && action !== 'manual_single_file') action = action === 'none' ? 'activate' : action + '_and_activate';
    return { plugin: plugin.plugin, name: plugin.name, source_version: plugin.version, target_version: existing?.version ?? null, active_on_source: plugin.active, active_on_target: existing?.active ?? false, action };
  }).filter(plugin => plugin.active_on_source || plugin.action !== 'transfer');

  const post_types = {};
  for (const [name, info] of Object.entries(sm.post_types)) {
    if (!info.total) continue;
    post_types[name] = { source_total: info.total, supported_on_target: Boolean(tm.post_types[name]) };
    if (!tm.post_types[name]) warnings.push(`Post type ${name} (${info.total}) is not registered on the target yet. Transfer and activate the plugin or theme that provides it before running the content phase.`);
  }
  const taxonomies = {};
  for (const [name, total] of Object.entries(sm.taxonomies)) {
    if (!total) continue;
    taxonomies[name] = { source_total: total, supported_on_target: name in tm.taxonomies };
    if (!(name in tm.taxonomies)) warnings.push(`Taxonomy ${name} (${total}) is not registered on the target yet.`);
  }
  const previous = (tm.imported_sources ?? []).find(s => s.site_uuid === sm.site_uuid) ?? null;
  const targetContent = ['post', 'page'].reduce((sum, type) => sum + (tm.post_types[type]?.total ?? 0), 0);
  const recommended = { match_slugs: targetContent <= 3, status: 'preserve' };
  if (recommended.match_slugs) warnings.push('The target looks freshly installed: run with match_slugs: true so its default posts and pages (for example "hello-world" and "sample-page") are replaced instead of duplicated.');
  return {
    source: { name: sm.name, home: sm.home, site_uuid: sm.site_uuid, wordpress: sm.wordpress_version, php: sm.php_version, theme: sm.theme, permalink_structure: sm.permalink_structure, comments: sm.comments },
    target: { name: tm.name, home: tm.home, site_uuid: tm.site_uuid, wordpress: tm.wordpress_version, php: tm.php_version, theme: tm.theme, max_upload_bytes: tm.limits?.max_upload_bytes },
    previous_migration: previous,
    recommended_options: recommended,
    warnings, themes, plugins, post_types, taxonomies,
    not_migrated: sm.not_migrated,
    steps: [
      'Confirm scope with the operator: whole site or selected posts, whether to keep publication status, and whether target content may be overwritten.',
      'Transfer required themes (parents first) and active plugins with aism_migration_transfer_package (activate: true for plugins active on the source). Re-run aism_migration_plan until required post types and taxonomies are supported.',
      'Run aism_migration_run repeatedly with the returned cursor until done is true. Phases: ' + PHASES.join(' → ') + '.',
      'Activate the source theme on the target (aism_theme_action) if it is not active, then check aism_migration_status and spot-check content, menus, media and the front page.',
      'If the result is wrong, aism_migration_rollback removes what the migration created and restores changed settings.',
    ],
  };
}

function context(source, target, cursor, deadline) {
  const report = { errors: [], warnings: [], authors: {}, skipped_meta_keys: new Set(), unresolved: [] };
  const absorb = (kind, data) => {
    for (const result of data.results ?? []) cursor.stats[result.action] = (cursor.stats[result.action] ?? 0) + 1;
    for (const error of data.errors ?? []) {
      cursor.stats.errors++;
      if (report.errors.length < MAX_ERRORS) report.errors.push({ kind, ...error });
    }
    Object.assign(report.authors, data.authors ?? {});
    for (const key of data.skipped_meta_keys ?? []) report.skipped_meta_keys.add(key);
  };
  const fail = (kind, source_id, error) => {
    cursor.stats.errors++;
    if (report.errors.length < MAX_ERRORS) report.errors.push({ kind, source_id, code: error.code ?? 'aism_client_error', message: error.message });
  };
  return { source, target, cursor, deadline, report, absorb, fail, expired: () => Date.now() > deadline };
}

async function exportPage(api, query) {
  return (await api.request('GET', '/migration/export', { query })).data;
}

async function fetchPublic(api, url) {
  if (!/^https?:\/\//i.test(url)) throw new ApiError('Attachment URL is not HTTP(S).', 0, 'aism_file_missing');
  // Offloaded media: fetched without credentials from the URL the source reports.
  const response = await api.fetchImpl(new URL(url), { headers: { 'User-Agent': 'AI-Site-Manager-MCP/1.0' }, redirect: 'follow', signal: AbortSignal.timeout(api.timeout) });
  if (!response.ok) throw new ApiError(`Public media download failed with HTTP ${response.status}.`, response.status, 'aism_file_missing');
  const data = Buffer.from(await response.arrayBuffer());
  if (data.length > api.maxUploadBytes) throw new ApiError('Media file exceeds WP_AI_MAX_UPLOAD_BYTES.', 413, 'aism_upload_too_large');
  return { data, contentType: (response.headers.get('content-type') ?? 'application/octet-stream').split(';')[0], filename: url.split('/').at(-1) };
}

async function transferFile(ctx, item, authors) {
  let file;
  try { file = await ctx.source.download('/migration/file', { id: item.id }); }
  catch (error) {
    if (error.code !== 'aism_file_missing' || !item.attachment?.url) throw error;
    file = await fetchPublic(ctx.source, item.attachment.url);
  }
  const name = item.attachment?.file?.split('/').at(-1) || file.filename;
  const response = await ctx.target.uploadBuffer('/migration/import-file', 'file', file.data, name, {
    source: ctx.cursor.source, item: JSON.stringify(item), authors: JSON.stringify(authors ?? {}), options: JSON.stringify(ctx.cursor.options),
  }, file.contentType);
  ctx.absorb('media', response.data);
  ctx.cursor.stats.files++;
}

/** Import one exported page. Returns false when time ran out before every file was transferred. */
async function importPage(ctx, kind, page) {
  if (!page.items?.length) return true;
  const response = await ctx.target.request('POST', '/migration/import', {
    body: { source: ctx.cursor.source, kind, items: page.items, authors: page.authors ?? {}, options: ctx.cursor.options },
  });
  ctx.absorb(kind, response.data);
  for (const id of response.data.needs_file ?? []) {
    if (ctx.cursor.failed_files.includes(id)) continue;
    if (ctx.expired()) return false;
    const item = page.items.find(candidate => candidate.id === id);
    try { await transferFile(ctx, item, page.authors); }
    catch (error) {
      // Remember failures so a resumed run does not retry the same file forever.
      ctx.cursor.failed_files.push(id);
      ctx.fail('media', id, error);
    }
  }
  return true;
}

async function finalizeAll(ctx) {
  while (!ctx.expired()) {
    const data = (await ctx.target.request('POST', '/migration/finalize', { body: { source: ctx.cursor.source, limit: 200 } })).data;
    ctx.cursor.stats.finalized += data.processed;
    ctx.cursor.stats.unresolved += data.unresolved_count;
    for (const item of data.unresolved ?? []) if (ctx.report.unresolved.length < MAX_ERRORS) ctx.report.unresolved.push(item);
    for (const error of data.errors ?? []) ctx.fail('finalize', error.source_id, error);
    if (!data.remaining) return true;
  }
  return false;
}

function advancePaged(cursor, page) {
  if (!page.total_pages || cursor.page >= page.total_pages) { cursor.page = 1; return true; }
  cursor.page++;
  return false;
}

const UNITS = {
  async terms(ctx) {
    const page = await exportPage(ctx.source, { kind: 'terms', page: ctx.cursor.page, per_page: 100 });
    return await importPage(ctx, 'terms', page) && advancePaged(ctx.cursor, page);
  },
  async media(ctx) {
    const page = await exportPage(ctx.source, { kind: 'posts', post_type: 'attachment', page: ctx.cursor.page, per_page: 20, modified_after: ctx.cursor.modified_after });
    return await importPage(ctx, 'posts', page) && advancePaged(ctx.cursor, page);
  },
  async content(ctx) {
    const type = ctx.cursor.types[ctx.cursor.type];
    if (type === undefined) return true;
    const page = await exportPage(ctx.source, { kind: 'posts', post_type: type, page: ctx.cursor.page, per_page: 50, modified_after: ctx.cursor.modified_after });
    if (await importPage(ctx, 'posts', page) && advancePaged(ctx.cursor, page)) ctx.cursor.type++;
    return ctx.cursor.type >= ctx.cursor.types.length;
  },
  async comments(ctx) {
    const page = await exportPage(ctx.source, { kind: 'comments', page: ctx.cursor.page, per_page: 100, modified_after: ctx.cursor.modified_after });
    return await importPage(ctx, 'comments', page) && advancePaged(ctx.cursor, page);
  },
  async site(ctx) {
    const page = await exportPage(ctx.source, { kind: 'site' });
    if (page.skipped_options?.length) ctx.report.warnings.push('Site options with non-portable values were skipped: ' + page.skipped_options.join(', '));
    await importPage(ctx, 'site', page);
    return true;
  },
  finalize: finalizeAll,
};

function importOptions(args, status = 'preserve') {
  return {
    status: args.status ?? status, on_existing: args.on_existing ?? 'update', match_slugs: args.match_slugs ?? false,
    author_map: args.author_map ?? {}, ...(args.default_author ? { default_author: args.default_author } : {}),
  };
}

function summary(ctx, extra) {
  return { ...extra, stats: ctx.cursor.stats, errors: ctx.report.errors, unresolved_references: ctx.report.unresolved, warnings: ctx.report.warnings, authors: ctx.report.authors, skipped_meta_keys: [...ctx.report.skipped_meta_keys] };
}

/** Run a whole-site migration in resumable, time-bounded steps. */
export async function run(source, target, args = {}) {
  const deadline = Date.now() + (args.max_seconds ?? 50) * 1000;
  const sm = await manifest(source);
  let cursor = args.cursor;
  const warnings = [];
  if (cursor) {
    const valid = cursor.v === 1 && cursor.source === sm.site_uuid && Array.isArray(cursor.phases) && cursor.phases.every(phase => PHASES.includes(phase))
      && Array.isArray(cursor.types) && Array.isArray(cursor.failed_files) && Number.isInteger(cursor.step) && Number.isInteger(cursor.type) && Number.isInteger(cursor.page) && cursor.stats && typeof cursor.stats === 'object';
    if (!valid) throw new ApiError('This cursor is invalid or belongs to another source site. Start again without a cursor; imports are idempotent.', 0, 'aism_invalid_cursor');
  } else {
    const tm = await manifest(target);
    await begin(target, sm, tm);
    const wanted = args.post_types;
    let types = Object.keys(sm.post_types).filter(type => type !== 'attachment' && sm.post_types[type].total > 0 && (!wanted || wanted.includes(type)));
    for (const type of types.filter(t => !tm.post_types[t])) warnings.push(`Skipped post type ${type}: not registered on the target. Install/activate its plugin, then run again.`);
    for (const [taxonomy, total] of Object.entries(sm.taxonomies)) if (total && !(taxonomy in tm.taxonomies)) warnings.push(`Terms in taxonomy ${taxonomy} will be skipped: not registered on the target.`);
    types = types.filter(t => tm.post_types[t]).sort((a, b) => (a === 'nav_menu_item') - (b === 'nav_menu_item'));
    let phases = (args.phases ?? PHASES).filter(phase => PHASES.includes(phase));
    if (wanted && !wanted.includes('attachment')) phases = phases.filter(phase => phase !== 'media');
    if (!phases.includes('finalize')) phases.push('finalize');
    phases.sort((a, b) => PHASES.indexOf(a) - PHASES.indexOf(b));
    cursor = {
      v: 1, source: sm.site_uuid, phases, step: 0, types, type: 0, page: 1, options: importOptions(args), modified_after: args.modified_after, failed_files: [],
      stats: { created: 0, updated: 0, matched: 0, skipped: 0, files: 0, finalized: 0, unresolved: 0, errors: 0 },
    };
  }
  const ctx = context(source, target, cursor, deadline);
  ctx.report.warnings.push(...warnings);
  while (cursor.step < cursor.phases.length && !ctx.expired()) {
    if (await UNITS[cursor.phases[cursor.step]](ctx)) { cursor.step++; cursor.page = 1; cursor.type = 0; }
  }
  const done = cursor.step >= cursor.phases.length;
  const phase = cursor.phases[cursor.step] ?? 'done';
  return summary(ctx, {
    done, phase, progress: { phase, post_type: phase === 'content' ? cursor.types[cursor.type] : undefined, page: cursor.page },
    cursor: done ? null : cursor,
    next: done ? 'Migration finished. Check aism_migration_status and review the target site.' : 'Call aism_migration_run again with this cursor (and the same source and target) to continue.',
  });
}

/** Copy selected posts with their terms, featured images, media and reusable blocks. */
export async function copy(source, target, args) {
  const [sm, tm] = await Promise.all([manifest(source), manifest(target)]);
  await begin(target, sm, tm);
  const cursor = { source: sm.site_uuid, options: importOptions(args, 'draft'), failed_files: [], stats: { created: 0, updated: 0, matched: 0, skipped: 0, files: 0, finalized: 0, unresolved: 0, errors: 0 } };
  const ctx = context(source, target, cursor, Date.now() + (args.max_seconds ?? 300) * 1000);
  const items = new Map();
  const authors = {};
  let pending = [...new Set(args.ids)];
  for (let depth = 0; depth < 4 && pending.length; depth++) {
    const page = await exportPage(source, { kind: 'posts', ids: pending.join(','), per_page: 100 });
    Object.assign(authors, page.authors);
    for (const item of page.items) items.set(item.id, item);
    if (depth === 0) for (const id of pending.filter(id => !items.has(id))) ctx.fail('posts', id, new ApiError('Not found or not exportable on the source.', 404, 'aism_post_missing'));
    if (args.include_dependencies === false) break;
    pending = [...new Set(page.items.flatMap(item => item.dependencies?.posts ?? []))].filter(id => !items.has(id)).slice(0, 100);
  }
  const terms = new Set();
  for (const item of items.values()) {
    for (const ids of Object.values(item.terms ?? {})) for (const id of ids) terms.add(id);
    for (const id of item.dependencies?.terms ?? []) terms.add(id);
  }
  const termIds = [...terms];
  for (let i = 0; i < termIds.length; i += 100) {
    await importPage(ctx, 'terms', await exportPage(source, { kind: 'terms', ids: termIds.slice(i, i + 100).join(','), per_page: 100 }));
  }
  const requested = new Set(args.ids);
  const ordered = [...items.values()].sort((a, b) => (b.type === 'attachment') - (a.type === 'attachment') || requested.has(a.id) - requested.has(b.id));
  for (let i = 0; i < ordered.length; i += 50) {
    if (!await importPage(ctx, 'posts', { items: ordered.slice(i, i + 50), authors })) break;
  }
  const finalized = await finalizeAll(ctx);
  return summary(ctx, {
    done: finalized && !ctx.expired(), copied: [...items.values()].map(item => ({ source_id: item.id, type: item.type, title: item.title, requested: requested.has(item.id) })),
    next: finalized ? 'Review the copied posts on the target.' : 'Time ran out; call aism_migration_copy again with the same ids. Already copied objects are updated, not duplicated.',
  });
}

/** Install a source plugin/theme on the target from the source's exact files or from WordPress.org. */
export async function transferPackage(source, target, args) {
  const { type, id, activate = false, from = 'source' } = args;
  const [sm, tm] = await Promise.all([manifest(source), manifest(target)]);
  const steps = [];
  if (type === 'plugin') {
    const src = sm.plugins.find(p => p.plugin === id || p.plugin === id + '.php' || p.slug === id);
    if (!src) throw new ApiError(`Plugin ${id} is not installed on the source.`, 404, 'aism_package_missing');
    if (src.self) throw new ApiError('Install AI Site Manager on the target from its release ZIP.', 403, 'aism_self_management');
    const existing = tm.plugins.find(p => p.plugin === src.plugin);
    if (from === 'wordpress.org') {
      if (existing) steps.push({ action: 'present', version: existing.version });
      else steps.push({ action: 'installed_from_wordpress_org', result: (await target.request('POST', '/plugins/install', { body: { slug: src.slug } })).data });
    } else if (existing && compareVersions(existing.version, src.version) >= 0) {
      steps.push({ action: 'present', version: existing.version });
    } else {
      const pkg = await source.download('/migration/package', { type: 'plugin', id: src.plugin });
      const result = existing
        ? await target.uploadBuffer('/plugins/update', 'pluginzip', pkg.data, pkg.filename, { plugin: src.plugin })
        : await target.uploadBuffer('/plugins/upload', 'pluginzip', pkg.data, pkg.filename);
      steps.push({ action: existing ? 'updated' : 'installed', bytes: pkg.data.length, result: result.data });
    }
    if (activate && !existing?.active) steps.push({ action: 'activated', result: (await target.request('POST', '/plugins/action', { body: { plugin: src.plugin, action: 'activate' } })).data });
    return { type, plugin: src.plugin, source_version: src.version, steps };
  }
  if (type !== 'theme') throw new ApiError('type must be plugin or theme.', 400, 'aism_invalid_package');
  const src = sm.themes.find(t => t.theme === id);
  if (!src) throw new ApiError(`Theme ${id} is not installed on the source.`, 404, 'aism_package_missing');
  const existing = tm.themes.find(t => t.theme === src.theme);
  if (src.template !== src.theme && !tm.themes.find(t => t.theme === src.template)) {
    steps.push({ action: 'parent', result: await transferPackage(source, target, { type: 'theme', id: src.template, from }) });
  }
  if (existing) {
    steps.push(compareVersions(existing.version, src.version) >= 0
      ? { action: 'present', version: existing.version }
      : { action: 'version_differs', version: existing.version, message: 'Theme ZIPs cannot replace an installed theme. Use aism_theme_action update, or delete the inactive target theme and transfer again.' });
  } else if (from === 'wordpress.org') {
    steps.push({ action: 'installed_from_wordpress_org', result: (await target.request('POST', '/themes/install', { body: { slug: src.theme } })).data });
  } else {
    const pkg = await source.download('/migration/package', { type: 'theme', id: src.theme });
    steps.push({ action: 'installed', bytes: pkg.data.length, result: (await target.uploadBuffer('/themes/upload', 'themezip', pkg.data, pkg.filename)).data });
  }
  if (activate && !existing?.active) steps.push({ action: 'activated', result: (await target.request('POST', '/themes/action', { body: { theme: src.theme, action: 'activate' } })).data });
  return { type, theme: src.theme, source_version: src.version, steps };
}

export async function status(source, target) {
  const sm = await manifest(source);
  let data;
  try { data = (await target.request('GET', '/migration/status', { query: { source: sm.site_uuid } })).data; }
  catch (error) {
    if (error.code === 'aism_migration_not_started') return { started: false, source: sourceRecord(sm) };
    throw error;
  }
  const migrated = data.counts.post_types ?? {};
  const post_types = Object.entries(sm.post_types).filter(([, info]) => info.total).map(([name, info]) => ({ post_type: name, source: info.total, on_target: migrated[name] ?? 0 }));
  const terms = Object.values(sm.taxonomies).reduce((sum, n) => sum + n, 0);
  const pending = Object.values(data.counts).reduce((sum, c) => sum + (c.pending_finalize ?? 0), 0);
  return {
    started: true, record: data.source, finalized: Boolean(data.source.finalized_at) && pending === 0, pending_finalize: pending,
    post_types, terms: { source: terms, on_target: data.counts.term?.mapped ?? 0 }, comments: { source: sm.comments, on_target: data.counts.comment?.mapped ?? 0 },
    note: 'Source counts exclude trash; target counts include objects matched to existing target content. Differences usually mean filtered post types, unregistered types/taxonomies, failed files or a run still in progress.',
  };
}

export async function rollback(target, sourceUuid, { max_seconds = 50 } = {}) {
  const deadline = Date.now() + max_seconds * 1000;
  const total = { processed: 0, deleted: 0, restored_options: 0, errors: [] };
  let remaining = null;
  while (Date.now() < deadline) {
    const data = (await target.request('POST', '/migration/rollback', { body: { source: sourceUuid, limit: 100 } })).data;
    total.processed += data.processed;
    total.deleted += data.deleted;
    total.restored_options += data.restored_options.length;
    total.errors.push(...data.errors.slice(0, MAX_ERRORS - total.errors.length));
    remaining = data.remaining;
    if (!remaining) break;
  }
  return { ...total, remaining, done: remaining === 0, next: remaining ? 'Call aism_migration_rollback again to continue.' : 'Rollback finished. Objects matched to pre-existing target content were left in place with their imported changes.' };
}
