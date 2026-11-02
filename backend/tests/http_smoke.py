"""Loopback-only HTTP regression checks through the real front controller and dev router.

Phase 1 runs with no configuration (safe 503/404 errors, private files hidden).
Phase 2 rebuilds the disposable spicemama_test database and drives a full admin
session with real cookies, multipart upload and logout. Never touches hosting.
"""
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[2]
backend = root / 'backend'
php = os.environ.get('PHP_BINARY', 'php')
test_db = os.environ.get('TEST_DB_DATABASE', 'spicemama_test')
assert test_db.endswith('_test'), 'TEST_DB_DATABASE must name a disposable *_test database'
cases = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


opener = urllib.request.build_opener(NoRedirect)


def start(env):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen(
        # display_errors must be off at startup: PHP reports oversized POSTs before any script runs.
        [php, '-d', 'display_errors=0', '-d', 'expose_php=0', '-S', f'127.0.0.1:{port}', 'backend/bin/dev-router.php'],
        cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env={**os.environ, **env},
    )
    for attempt in range(50):
        if server.poll() is not None:
            raise RuntimeError('Local PHP server failed to start')
        try:
            request(port, 'GET', '/api/v1/not-found')
            return server, port
        except urllib.error.URLError:
            time.sleep(.05)
    server.terminate()
    raise RuntimeError('Local server startup timed out')


def request(port, method, path, body=None, headers=None):
    req = urllib.request.Request(f'http://127.0.0.1:{port}{path}', data=body, method=method, headers=headers or {})
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def check(condition, label):
    global cases
    cases += 1
    assert condition, label


def safe_error(status, headers, raw, expected_status, expected_code, label):
    body = raw.decode()
    check(status == expected_status, f'{label}: {status} {body}')
    check(json.loads(body)['error']['code'] == expected_code, label)
    check(headers['Cache-Control'] == 'no-store' and headers['X-Content-Type-Options'] == 'nosniff', label + ' headers')
    check('/Users/' not in body and 'password' not in body.lower() and 'SQLSTATE' not in body, label + ' leak')


# --- Phase 1: unconfigured ---------------------------------------------------
server, port = start({'SPICEMAMA_CONFIG': '/nonexistent/spicemama-config.php'})
try:
    for path, status, code in [
        ('/api/v1/products', 503, 'unavailable'),
        ('/api/v1/categories', 503, 'unavailable'),
        ('/api/v1/products?page=0', 422, 'validation_failed'),
        ('/api/v1/products?page=1&page=2', 400, 'bad_request'),
        ('/api/v1/products?q=%FF', 400, 'bad_request'),
        ('/api/v1/admin/me', 503, 'unavailable'),
        ('/api/v1/admin/csrf', 503, 'unavailable'),
        ('/api/v1/admin/nope', 404, 'not_found'),
    ]:
        safe_error(*request(port, 'GET', path), status, code, path)
    for path in ['/backend/config/local.example.php', '/backend/config/local.php', '/backend/.env.example',
                 '/API_CONTRACT.yaml', '/Images/', '/%2e%2e/README.md', '/../backend/bin/migrate.php']:
        check(request(port, 'GET', path)[0] == 404, path)
finally:
    server.terminate()
    server.wait(timeout=5)

# --- Phase 2: configured admin flow -----------------------------------------
work = Path(tempfile.mkdtemp(prefix='spicemama-smoke-'))
uploads = root / 'frontend' / 'uploads' / 'products'
config = work / 'config.php'
config.write_text(f"""<?php
$config = require {json.dumps(str(backend / 'config' / 'local.php'))};
$config['database']['database'] = {json.dumps(test_db)};
$config['storage_path'] = {json.dumps(str(work / 'storage'))};
$config['upload_path'] = {json.dumps(str(uploads))};
return $config;
""")
env = {'SPICEMAMA_CONFIG': str(config)}
subprocess.run([php, 'backend/tests/reset_test_db.php'], cwd=root, env={**os.environ, **env}, check=True, stdout=subprocess.DEVNULL)
password = secrets.token_urlsafe(18)
subprocess.run([php, 'backend/bin/create-admin.php', '--email=smoke@example.test', '--name=MOCK Smoke'],
               cwd=root, env={**os.environ, **env}, input=password + '\n', text=True, check=True, stdout=subprocess.DEVNULL)
image = work / 'mock.jpg'
subprocess.run([php, '-r', f'$i=imagecreatetruecolor(64,48);imagejpeg($i,{json.dumps(str(image))});'], check=True)

server, port = start(env)
cookie = ''


def call(method, path, payload=None, token=None, raw=None, content_type=None):
    """Browser-like call: sends the session cookie and records any replacement."""
    global cookie
    headers = {'Cookie': cookie} if cookie else {}
    if token:
        headers['X-CSRF-Token'] = token
    body = raw
    if payload is not None:
        body = json.dumps(payload).encode()
        headers['Content-Type'] = 'application/json'
    if content_type:
        headers['Content-Type'] = content_type
    status, response_headers, data = request(port, method, path, body, headers)
    for header in response_headers.get_all('Set-Cookie') or []:
        name_value = header.split(';', 1)[0]
        cookie = '' if 'expires=Thu, 01 Jan 1970' in header or name_value.endswith('=deleted') or name_value.endswith('=') else name_value
    return status, response_headers, json.loads(data) if data else None


def multipart(fields, file_bytes):
    boundary = 'spicemama' + secrets.token_hex(8)
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="mock.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode() + file_bytes + b'\r\n')
    return b''.join(parts) + f'--{boundary}--\r\n'.encode(), f'multipart/form-data; boundary={boundary}'


try:
    status, _, body = call('GET', '/api/v1/products')
    check(status == 200 and body['meta']['total'] == 0, 'empty public catalogue')
    status, headers, body = call('GET', '/api/v1/admin/csrf')
    set_cookie = headers['Set-Cookie'] or ''
    check(status == 200 and 'HttpOnly' in set_cookie and 'SameSite=Lax' in set_cookie and 'path=/' in set_cookie, 'pre-login cookie flags')
    pre_token, pre_cookie = body['data']['csrf_token'], cookie
    status, _, _ = call('POST', '/api/v1/admin/login', {'email': 'smoke@example.test', 'password': password})
    check(status == 403, 'login without CSRF')
    status, _, _ = call('POST', '/api/v1/admin/login', {'email': 'smoke@example.test', 'password': 'wrong'}, pre_token)
    check(status == 401, 'wrong password')
    status, _, body = call('POST', '/api/v1/admin/login', {'email': 'smoke@example.test', 'password': password}, pre_token)
    check(status == 200 and body['data']['display_name'] == 'MOCK Smoke', 'login')
    check(cookie and cookie != pre_cookie, 'session ID regenerated')
    token = call('GET', '/api/v1/admin/csrf')[2]['data']['csrf_token']
    check(token != pre_token, 'token rotated')
    check(call('GET', '/api/v1/admin/me')[0] == 200, 'me')

    category = call('POST', '/api/v1/admin/categories', {'name': 'MOCK Spices', 'slug': 'mock-spices', 'is_active': True}, token)[2]['data']
    status, _, body = call('POST', '/api/v1/admin/products', {
        'name': 'MOCK Chilli', 'slug': 'mock-chilli', 'category_id': category['id'],
        'short_description': 'MOCK short', 'description': 'MOCK description'}, token)
    check(status == 201, 'create product')
    product_id = body['data']['id']
    data, content_type = multipart({'product_id': product_id, 'alt_text': 'MOCK alt'}, image.read_bytes())
    status, _, body = call('POST', '/api/v1/admin/images', token=token, raw=data, content_type=content_type)
    check(status == 201, f'multipart upload {status} {body}')
    image_url, image_id = body['data']['url'], body['data']['id']
    status, headers, served = request(port, 'GET', image_url)
    check(status == 200 and headers['Content-Type'] == 'image/jpeg' and served[:2] == b'\xff\xd8', 'uploaded image served')
    data, content_type = multipart({'product_id': product_id}, b'\0' * (9 * 1024 * 1024))
    status, _, body = call('POST', '/api/v1/admin/images', token=token, raw=data, content_type=content_type)
    check(status == 413, f'over post_max_size {status}')
    check(call('POST', f'/api/v1/admin/products/{product_id}/status', {'status': 'published'}, token)[0] == 200, 'publish')
    status, _, body = call('GET', '/api/v1/products/mock-chilli')
    check(status == 200 and body['data']['images'][0]['url'] == image_url, 'public detail')

    # Clean up the generated file through the API (unpublish first: published products keep an image).
    check(call('POST', f'/api/v1/admin/products/{product_id}/status', {'status': 'draft'}, token)[0] == 200, 'unpublish')
    status, _, body = call('DELETE', f'/api/v1/admin/images/{image_id}', token=token)
    check(status == 204 and body is None, 'delete image')
    check(not (uploads / image_url.rsplit('/', 1)[1]).exists(), 'file removed')
    status, headers, body = call('POST', '/api/v1/admin/logout', token=token)
    check(status == 204 and body is None and 'Content-Type' not in headers, 'logout 204 without body')
    check(call('GET', '/api/v1/admin/me')[0] == 401, 'session ended')
    print(f'PASS: {cases} HTTP cases; unconfigured safety and full admin session on {test_db}.')
finally:
    server.terminate()
    server.wait(timeout=5)
    for path in sorted(work.rglob('*'), reverse=True):
        path.rmdir() if path.is_dir() else path.unlink()
    work.rmdir()
