#!/usr/bin/env -S uv run --system-certs
"""Small AI Site Manager client (Python 3.10+, standard library only)."""
import argparse
import json
import mimetypes
import os
from pathlib import Path
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request


def endpoint_url(base, endpoint):
    base_parts = urllib.parse.urlsplit(base.rstrip('/'))
    endpoint_parts = urllib.parse.urlsplit(endpoint)
    if endpoint_parts.scheme or endpoint_parts.netloc or not endpoint_parts.path.startswith('/'):
        raise ValueError('Endpoint must be a relative path beginning with /.')
    query = urllib.parse.parse_qsl(base_parts.query, keep_blank_values=True)
    if any(name == 'rest_route' for name, _ in query):
        query = [(name, value.rstrip('/') + endpoint_parts.path if name == 'rest_route' else value) for name, value in query]
        path = base_parts.path
    else:
        path = base_parts.path.rstrip('/') + endpoint_parts.path
    query.extend(urllib.parse.parse_qsl(endpoint_parts.query, keep_blank_values=True))
    return urllib.parse.urlunsplit((base_parts.scheme, base_parts.netloc, path, urllib.parse.urlencode(query), ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward credentials through redirects.


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('method', choices=['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])
    parser.add_argument('endpoint', help='For example /wp/posts?context=edit')
    body = parser.add_mutually_exclusive_group()
    body.add_argument('--json', dest='json_body', help='A JSON request body')
    body.add_argument('--file', type=Path, help='A local file for multipart upload')
    parser.add_argument('--field', default='file', help='Multipart field: file for media; pluginzip for plugins')
    parser.add_argument('--timeout', type=int, default=120)
    args = parser.parse_args()
    base = os.environ.get('WP_AI_BASE', '')
    token = os.environ.get('WP_AI_KEY', '')
    if not base or not token:
        parser.error('Set WP_AI_BASE and WP_AI_KEY in the environment.')
    parts = urllib.parse.urlsplit(base)
    local_http = parts.scheme == 'http' and parts.hostname in ('localhost', '127.0.0.1', '::1') and os.environ.get('WP_AI_ALLOW_LOCAL_HTTP') == '1'
    if parts.username or parts.password or (parts.scheme != 'https' and not local_http):
        parser.error('Use an HTTPS base URL without embedded credentials. Local tests require WP_AI_ALLOW_LOCAL_HTTP=1.')
    headers = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'User-Agent': 'AI-Site-Manager-Client/1.0'}
    payload = None
    try:
        url = endpoint_url(base, args.endpoint)
        if args.json_body is not None:
            payload = json.dumps(json.loads(args.json_body)).encode()
            headers['Content-Type'] = 'application/json'
        elif args.file:
            boundary = 'aism-' + secrets.token_hex(16)
            filename = args.file.name.replace('"', '_').replace('\r', '_').replace('\n', '_')
            field = args.field.replace('"', '_').replace('\r', '_').replace('\n', '_')
            mime = mimetypes.guess_type(filename)[0] or 'application/octet-stream'
            payload = (f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + args.file.read_bytes() + f'\r\n--{boundary}--\r\n'.encode())
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        request = urllib.request.Request(url, data=payload, headers=headers, method=args.method)
        with urllib.request.build_opener(NoRedirect).open(request, timeout=args.timeout) as response:
            result = response.read().decode()
            for name in ('X-WP-Total', 'X-WP-TotalPages'):
                if response.headers.get(name):
                    print(f'{name}: {response.headers[name]}', file=sys.stderr)
            print(json.dumps(json.loads(result), indent=2, ensure_ascii=False))
    except urllib.error.HTTPError as exc:
        print(f'HTTP {exc.code}: {exc.read().decode(errors="replace")}', file=sys.stderr)
        return 1
    except (ValueError, OSError, urllib.error.URLError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
