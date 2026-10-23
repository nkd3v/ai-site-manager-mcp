#!/usr/bin/env -S uv run --system-certs
"""Destructive HTTP tests for the isolated Compose installation, never production."""
import base64
import http.cookiejar
import importlib.util
import io
import json
import re
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('aism_client', ROOT / 'examples/client.py')
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)
BASE = sys.argv[1].rstrip('/')
CONTAINER = sys.argv[2] if len(sys.argv) > 2 else 'aism-test-wordpress-1'
assert urllib.parse.urlsplit(BASE).hostname in ('localhost', '127.0.0.1'), 'Local disposable sites only'
COUNT = 0


def check(condition, message):
    global COUNT
    assert condition, message
    COUNT += 1


def php(code):
    result = subprocess.run(['docker', 'exec', CONTAINER, 'php', '-r', "require '/var/www/html/wp-load.php'; " + code], check=True, text=True, capture_output=True)
    return result.stdout


def request(opener, method, url, payload=None, headers=None):
    req = urllib.request.Request(url, data=payload, headers=headers or {}, method=method)
    try:
        with opener.open(req, timeout=180) as res:
            return res.status, res.headers, res.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.headers, exc.read()


plain = urllib.request.build_opener(client.NoRedirect)
cookies = http.cookiejar.CookieJar()
admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
request(admin, 'GET', BASE + '/wp-login.php')
login = urllib.parse.urlencode({'log': 'aism-admin', 'pwd': 'isolated-test-password', 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': '1'}).encode()
status, _, _ = request(admin, 'POST', BASE + '/wp-login.php', login)
check(status == 200 and any('wordpress_logged_in' in c.name for c in cookies), 'Admin login failed')
PAGE = BASE + '/wp-admin/options-general.php?page=ai-site-manager'
status, _, html = request(admin, 'GET', PAGE)
check(status == 200 and b'Create API key' in html, 'Admin panel failed')
nonce = re.search(rb'name="_wpnonce" value="([^"]+)"', html).group(1).decode()
user_id = json.loads(php("echo json_encode(get_user_by('login','aism-admin')->ID);"))
scopes = [f'{group}:{action}' for group in ('content', 'media', 'taxonomies', 'comments', 'plugins', 'themes') for action in ('read', 'write', 'delete')] + ['settings:read', 'settings:write']
fields = [('_wpnonce', nonce), ('aism_action', 'create'), ('key_name', 'HTTP <b>assistant</b> & test'), ('user_id', str(user_id)), ('expiry_days', '1'), ('option_names', 'blogname\nblogdescription')] + [('scopes[]', s) for s in scopes]
status, headers, html = request(admin, 'POST', PAGE, urllib.parse.urlencode(fields).encode())
check(status == 200, 'Key creation UI failed')
match = re.search(rb'value="(aism_[a-f0-9]{64})"', html)
check(match is not None, 'Created secret not displayed')
TOKEN = match.group(1).decode()
check('no-cache' in headers.get('Cache-Control', '') or 'no-store' in headers.get('Cache-Control', ''), 'Admin page lacks cache control')
_, _, html = request(admin, 'GET', PAGE)
check(TOKEN.encode() not in html, 'Secret shown again after creation')
check(b'HTTP assistant &amp; test' in html, 'Key name not sanitized/escaped')
# Bad nonce may not create a key.
bad_fields = [(k, 'invalid' if k == '_wpnonce' else v) for k, v in fields]
status, _, _ = request(admin, 'POST', PAGE, urllib.parse.urlencode(bad_fields).encode())
check(status == 403, 'Missing nonce did not block key creation')


def api(method, path, body=None, token=TOKEN, raw=None, extra=None):
    headers = {'Authorization': 'Bearer ' + token}
    headers.update(extra or {})
    if body is not None:
        headers['Content-Type'] = 'application/json'
        raw = json.dumps(body).encode()
    result = request(plain, method, client.endpoint_url(BASE + '/?rest_route=/ai-site-manager/v1', path), raw, headers)
    status, response_headers, data = result
    try:
        decoded = json.loads(data)
    except ValueError:
        raise AssertionError(f'Non-JSON API response ({status}): {data[:400]!r}')
    return status, response_headers, decoded


status, headers, discovery = api('GET', '/discovery')
check(status == 200 and 'plugins:write' in discovery['key']['scopes'], 'Authenticated discovery failed')
check('themes:write' in discovery['key']['scopes'], 'Admin theme scopes missing')
check('no-store' in headers.get('Cache-Control', ''), 'API cache control missing')
KEY_ID = discovery['key']['id']
status, _, _ = api('GET', '/discovery', token='invalid')
check(status == 401, 'Invalid key accepted')
# Credential fallback header, query credentials rejected, no authentication leakage to core.
url = client.endpoint_url(BASE + '/?rest_route=/ai-site-manager/v1', '/discovery')
status, _, _ = request(plain, 'GET', url, headers={'X-AISM-Key': TOKEN})
check(status == 200, 'X-AISM-Key failed')
status, _, _ = request(plain, 'GET', url + '&api_key=' + urllib.parse.quote(TOKEN))
check(status == 401, 'Query key accepted')
status, _, data = request(plain, 'GET', BASE + '/?rest_route=/wp/v2/users/me', headers={'Authorization': 'Bearer ' + TOKEN})
check(status == 401, 'Key authenticated outside plugin namespace')
status, _, draft = api('POST', '/wp/posts', {'title': 'HTTP draft', 'content': '<p>Original</p>', 'status': 'draft'})
check(status == 201, 'HTTP draft create failed: ' + str(draft))
POST_ID = draft['id']
status, headers, posts = api('GET', '/wp/posts?status=draft&context=edit&per_page=1&page=1')
check(status == 200 and headers.get('X-WP-TotalPages') is not None, 'Pagination headers lost')
status, _, saved = api('PATCH', f'/wp/posts/{POST_ID}', {'title': 'Changed'})
check(status == 200 and saved['content']['raw'] == '<p>Original</p>', 'HTTP partial update failed')
status, _, _ = api('PUT', '/settings/blogdescription', {'value': 'HTTP integration description'})
check(status == 200, 'HTTP setting update failed')
status, _, option = api('GET', '/settings/blogdescription')
check(status == 200 and option['value'] == 'HTTP integration description', 'HTTP setting verification failed')
# A real multipart image upload, plus a raw upload.
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR4nGPQSFlAU8QwasGoBaMWjFowasGoBaMWjFowasGoBaMWDBULANZWsD0ESLo9AAAAAElFTkSuQmCC')


def multipart(field, name, mime, data, fields=None):
    boundary = 'aismtest' + uuid.uuid4().hex
    payload = (f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + f'\r\n--{boundary}--\r\n'.encode())
    if fields:
        prefix = b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in fields.items())
        payload = prefix + payload
    return payload, {'Content-Type': 'multipart/form-data; boundary=' + boundary}


raw, extra = multipart('file', 'test-image.png', 'image/png', PNG)
status, _, media = api('POST', '/wp/media', raw=raw, extra=extra)
check(status == 201 and media.get('mime_type') == 'image/png', 'Image multipart upload failed: ' + str(media))
MEDIA_ID = media['id']
status, _, media = api('POST', f'/wp/media/{MEDIA_ID}', {'alt_text': 'Test pixel'})
check(status == 200 and media['alt_text'] == 'Test pixel', 'Image alt text update failed')
status, _, saved = api('POST', f'/wp/posts/{POST_ID}', {'featured_media': MEDIA_ID})
check(status == 200 and saved['featured_media'] == MEDIA_ID, 'Featured image assignment failed')
status, _, raw_media = api('POST', '/wp/media', raw=PNG, extra={'Content-Type': 'image/png', 'Content-Disposition': 'attachment; filename="raw-image.png"'})
check(status == 201, 'Raw image upload failed: ' + str(raw_media))
# An executable fixture contains only a plugin header. Upload does not activate it.
plugin_slug = 'aism-fixture-' + uuid.uuid4().hex[:8]
plugin_file = f'{plugin_slug}/{plugin_slug}.php'


def plugin_zip(version):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w') as archive:
        archive.writestr(plugin_file, f'<?php\n/*\nPlugin Name: AISM test fixture\nVersion: {version}\n*/\n')
    return stream.getvalue()


raw, extra = multipart('pluginzip', plugin_slug + '.zip', 'application/zip', plugin_zip('1.0.0'))
status, _, plugin = api('POST', '/plugins/upload', raw=raw, extra=extra)
check(status == 201 and plugin.get('status') == 'inactive', 'Plugin upload failed: ' + str(plugin))
status, _, plugins = api('GET', '/plugins')
check(status == 200 and any(p['plugin'] == plugin_file[:-4] and p['status'] == 'inactive' for p in plugins), 'Uploaded plugin not listed inactive')
status, _, plugin = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'activate'})
check(status == 200 and plugin['status'] == 'active', 'Plugin activation failed: ' + str(plugin))
status, _, result = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'delete'})
check(status >= 400, 'Active plugin deleted')
# Explicit ZIP replacement checks the target before removing installed files.
def update_zip(package, target=plugin_file):
    raw, extra = multipart('pluginzip', 'update.zip', 'application/zip', package, {'plugin': target})
    return api('POST', '/plugins/update', raw=raw, extra=extra)


php("update_option('aism_zip_fixture_setting', 'preserved');")
for package, target, expected_code in [
    (plugin_zip('1.1.0'), '../wp-config.php', 'aism_plugin_missing'),
    (plugin_zip('1.1.0'), 'ai-site-manager/ai-site-manager', 'aism_self_management'),
    (plugin_zip('1.0.0'), plugin_file, 'aism_plugin_zip_version'),
    (plugin_zip('0.9.0'), plugin_file, 'aism_plugin_zip_version'),
    (b'not a ZIP', plugin_file, None),
]:
    status, _, result = update_zip(package, target)
    check(status >= 400 and (expected_code is None or result.get('code') == expected_code), 'Unsafe ZIP accepted: ' + str(result))
# A ZIP for a different folder, or one missing the selected main file, cannot replace the target.
for archive_file, contents in [
    ('another-plugin/another.php', '<?php /* Plugin Name: Wrong target\nVersion: 9.0.0\n*/'),
    (plugin_slug + '/other.php', '<?php /* Plugin Name: Wrong main\nVersion: 9.0.0\n*/'),
    (plugin_file, '<?php /* Plugin Name: Incompatible\nVersion: 9.0.0\nRequires PHP: 99.0\n*/'),
]:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w') as archive:
        archive.writestr(archive_file, contents)
    status, _, result = update_zip(stream.getvalue())
    check(status >= 400, 'Mismatched or incompatible package accepted: ' + str(result))
status, _, plugins = api('GET', '/plugins')
check(any(p['plugin'] == plugin_file[:-4] and p['version'] == '1.0.0' and p['status'] == 'active' for p in plugins), 'Rejected ZIP changed installed version/status')
status, _, updated = update_zip(plugin_zip('1.1.0'), plugin_file[:-4])
check(status == 200 and updated.get('updated') and updated.get('version') == '1.1.0' and updated.get('active') is True, 'Active ZIP update failed: ' + str(updated))
check(php("echo get_option('aism_zip_fixture_setting');") == 'preserved', 'ZIP update lost settings')
status, _, plugins = api('GET', '/plugins')
check(any(p['plugin'] == plugin_file[:-4] and p['version'] == '1.1.0' and p['status'] == 'active' for p in plugins), 'ZIP update did not preserve activation on subsequent request')
api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'deactivate'})
status, _, updated = update_zip(plugin_zip('1.2.0'))
check(status == 200 and updated.get('version') == '1.2.0' and updated.get('active') is False, 'Inactive ZIP update failed: ' + str(updated))
raw, extra = multipart('pluginzip', plugin_slug + '.zip', 'application/zip', plugin_zip('9.0.0'))
status, _, result = api('POST', '/plugins/upload', raw=raw, extra=extra)
check(status >= 400, 'New-install upload overwrote existing plugin')
api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'activate'})
php("delete_option('aism_zip_fixture_setting');")

# Inject an advertised update for the fixture, using a local test-only package.
package = plugin_zip('2.0.0')
encoded = base64.b64encode(package).decode()
php("file_put_contents(ABSPATH.'aism-test-update.zip',base64_decode('" + encoded + "')); ")
mu_path = '/var/www/html/wp-content/mu-plugins/aism-fixture-update.php'
mu_code = """<?php
add_filter('site_transient_update_plugins',function($updates){
 if(!is_object($updates)){$updates=new stdClass();}
 $updates->response['PLUGIN_FILE']=(object)['slug'=>'PLUGIN_SLUG','plugin'=>'PLUGIN_FILE','new_version'=>'2.0.0','package'=>'http://wordpress/aism-test-update.zip'];
 return $updates;
});
// Only the private Docker network hosts this disposable update package.
add_filter('http_request_host_is_external',function($external,$host){return $host==='wordpress' ? true : $external;},10,2);
""".replace('PLUGIN_FILE', plugin_file).replace('PLUGIN_SLUG', plugin_slug)
php("wp_mkdir_p(WPMU_PLUGIN_DIR); file_put_contents(WPMU_PLUGIN_DIR.'/aism-fixture-update.php', base64_decode('" + base64.b64encode(mu_code.encode()).decode() + "')); ")
status, _, updated = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'update'})
check(status == 200 and updated.get('updated') and updated.get('version') == '2.0.0', 'Real plugin update failed: ' + str(updated))
php("unlink(WPMU_PLUGIN_DIR.'/aism-fixture-update.php'); unlink(ABSPATH.'aism-test-update.zip');")
status, _, plugin = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'deactivate'})
check(status == 200 and plugin['status'] == 'inactive', 'Plugin deactivation failed')
status, _, result = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'update'})
check(status == 200 and result.get('updated') is False, 'No-update response incorrect')
status, _, result = api('POST', '/plugins/action', {'plugin': plugin_file, 'action': 'delete'})
check(status == 200, 'Plugin deletion failed: ' + str(result))
# Actual WordPress.org install path. Remove a preinstalled Hello Dolly fixture first.
api('POST', '/plugins/action', {'plugin': 'hello', 'action': 'deactivate'})
api('POST', '/plugins/action', {'plugin': 'hello', 'action': 'delete'})
status, _, installed = api('POST', '/plugins/install', {'slug': 'hello-dolly'})
check(status == 201 and installed.get('status') == 'inactive', 'Repository plugin installation failed: ' + str(installed))
api('POST', '/plugins/action', {'plugin': installed['plugin'], 'action': 'delete'})
# Exercise theme installation, replacement protection, switching, updates and child-theme safety.
status, _, themes = api('GET', '/themes')
check(status == 200, 'Theme listing failed')
original_theme = next(t['stylesheet'] for t in themes if t['status'] == 'active')
theme_slug = 'aism-theme-' + uuid.uuid4().hex[:8]
child_slug = theme_slug + '-child'


def theme_zip(slug, version, parent=None):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w') as archive:
        header = f'/*\nTheme Name: AISM fixture\nVersion: {version}\n'
        if parent:
            header += f'Template: {parent}\n'
        archive.writestr(f'{slug}/style.css', header + '*/\n')
        archive.writestr(f'{slug}/index.php', '<?php // Disposable fixture.\n')
    return stream.getvalue()


def upload_theme(slug, version='1.0.0', parent=None):
    raw, extra = multipart('themezip', slug + '.zip', 'application/zip', theme_zip(slug, version, parent))
    return api('POST', '/themes/upload', raw=raw, extra=extra)


status, _, theme = upload_theme(theme_slug)
check(status == 201 and theme.get('theme') == theme_slug and theme.get('status') == 'inactive', 'Theme upload failed: ' + str(theme))
status, _, result = upload_theme(theme_slug, '9.0.0')
check(status >= 400, 'Theme ZIP overwrote an installed theme')
status, _, themes = api('GET', '/themes')
check(any(t['stylesheet'] == theme_slug and t['version'] == '1.0.0' and t['status'] == 'inactive' for t in themes), 'Theme listing or replacement protection failed')
status, _, theme = api('POST', '/themes/action', {'theme': theme_slug, 'action': 'activate'})
check(status == 200 and theme.get('status') == 'active', 'Theme activation failed: ' + str(theme))
status, _, result = api('POST', '/themes/action', {'theme': theme_slug, 'action': 'delete'})
check(status == 409, 'Active theme deleted')
encoded = base64.b64encode(theme_zip(theme_slug, '2.0.0')).decode()
php("file_put_contents(ABSPATH.'aism-theme-update.zip',base64_decode('" + encoded + "'));")
mu_code = """<?php
add_filter('site_transient_update_themes',function($updates){
 if(!is_object($updates)){$updates=new stdClass();}
 $updates->response['THEME_SLUG']=['theme'=>'THEME_SLUG','new_version'=>'2.0.0','url'=>'https://example.test','package'=>'http://wordpress/aism-theme-update.zip'];
 return $updates;
});
add_filter('http_request_host_is_external',function($external,$host){return $host==='wordpress' ? true : $external;},10,2);
""".replace('THEME_SLUG', theme_slug)
php("file_put_contents(WPMU_PLUGIN_DIR.'/aism-fixture-update.php',base64_decode('" + base64.b64encode(mu_code.encode()).decode() + "'));")
status, _, updated = api('POST', '/themes/action', {'theme': theme_slug, 'action': 'update'})
check(status == 200 and updated.get('updated') and updated.get('version') == '2.0.0' and updated.get('status') == 'active', 'Real theme update failed: ' + str(updated))
php("unlink(WPMU_PLUGIN_DIR.'/aism-fixture-update.php'); unlink(ABSPATH.'aism-theme-update.zip');")
status, _, result = api('POST', '/themes/action', {'theme': theme_slug, 'action': 'update'})
check(status == 200 and result.get('updated') is False, 'Theme no-update response incorrect')
status, _, child = upload_theme(child_slug, parent=theme_slug)
check(status == 201 and child.get('status') == 'inactive', 'Child theme upload failed: ' + str(child))
status, _, child = api('POST', '/themes/action', {'theme': child_slug, 'action': 'activate'})
check(status == 200 and child.get('template') == theme_slug and child.get('status') == 'active', 'Child theme activation failed')
for slug in (theme_slug, child_slug):
    status, _, result = api('POST', '/themes/action', {'theme': slug, 'action': 'delete'})
    check(status == 409, 'Active child or parent theme deleted')
status, _, result = api('POST', '/themes/action', {'theme': original_theme, 'action': 'activate'})
check(status == 200, 'Could not restore original theme')
for slug in (child_slug, theme_slug):
    status, _, result = api('POST', '/themes/action', {'theme': slug, 'action': 'delete'})
    check(status == 200 and result.get('deleted'), 'Inactive theme deletion failed')
status, _, installed = api('POST', '/themes/install', {'slug': 'twentytwentyone'})
check(status == 201 and installed.get('status') == 'inactive', 'WordPress.org theme install failed: ' + str(installed))
api('POST', '/themes/action', {'theme': installed['theme'], 'action': 'delete'})
# Cleanup and revoke through the actual admin form.
for media_id in (MEDIA_ID, raw_media['id']):
    api('DELETE', f'/wp/media/{media_id}?force=true')
api('DELETE', f'/wp/posts/{POST_ID}?force=true')
_, _, html = request(admin, 'GET', PAGE)
nonce = re.search(rb'name="_wpnonce" value="([^"]+)"', html).group(1).decode()
status, _, _ = request(admin, 'POST', PAGE, urllib.parse.urlencode({'_wpnonce': nonce, 'aism_action': 'revoke', 'key_id': KEY_ID}).encode())
check(status == 200, 'Admin revocation failed')
status, _, _ = api('GET', '/discovery')
check(status == 401, 'Revoked key still works over HTTP')
# Stronger check: a settings-only administrator may not issue a key as a full administrator.
result = php("$u=wp_create_user('aism-settings-user','isolated-test-password','settings@example.test'); $user=new WP_User($u); $user->set_role('subscriber'); $user->add_cap('manage_options'); echo $u;")
restricted_cookies = http.cookiejar.CookieJar()
restricted = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(restricted_cookies))
request(restricted, 'GET', BASE + '/wp-login.php')
request(restricted, 'POST', BASE + '/wp-login.php', urllib.parse.urlencode({'log': 'aism-settings-user', 'pwd': 'isolated-test-password', 'testcookie': '1', 'redirect_to': PAGE}).encode())
_, _, html = request(restricted, 'GET', PAGE)
nonce = re.search(rb'name="_wpnonce" value="([^"]+)"', html).group(1).decode()
fields = [('_wpnonce', nonce), ('aism_action', 'create'), ('key_name', 'Escalation attempt'), ('user_id', str(user_id)), ('expiry_days', '1'), ('scopes[]', 'plugins:write')]
status, _, html = request(restricted, 'POST', PAGE, urllib.parse.urlencode(fields).encode())
check(status == 200 and b'You may issue keys only for yourself' in html and re.search(rb'value="aism_[a-f0-9]{64}"', html) is None, 'Key owner privilege escalation')
php("require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user(" + str(int(result)) + ");")
print(f'PASS: {COUNT} HTTP assertions (including image uploads, plugin/theme upload/install/update/lifecycle, child-theme safety, admin nonce and revocation)')
