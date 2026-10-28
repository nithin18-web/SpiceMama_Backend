"""Loopback-only HTTP regression checks; no database or hosting connection."""
import json
import os
from pathlib import Path
import socket
import subprocess
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[2]
php = os.environ.get('PHP_BINARY', 'php')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
server = subprocess.Popen(
    [php, '-S', f'127.0.0.1:{port}', 'backend/bin/dev-router.php'],
    cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
)

def fetch(path):
    try:
        return urllib.request.urlopen(f'http://127.0.0.1:{port}{path}', timeout=2)
    except urllib.error.HTTPError as error:
        return error

try:
    for attempt in range(50):
        if server.poll() is not None:
            raise RuntimeError('Local PHP server failed to start')
        try:
            with fetch('/api/v1/not-found') as response:
                assert response.status == 404
            break
        except urllib.error.URLError:
            time.sleep(.05)
    else:
        raise RuntimeError('Local server startup timed out')

    # Exercise public bootstrap, not a mock controller.
    for path, status, code in [
        ('/api/v1/products', 503, 'unavailable'),
        ('/api/v1/categories', 503, 'unavailable'),
        ('/api/v1/products?page=0', 422, 'validation_failed'),
        ('/api/v1/products?page=1&page=2', 400, 'bad_request'),
        ('/api/v1/products?q=%FF', 400, 'bad_request'),
        ('/api/v1/admin/me', 404, 'not_found'),
    ]:
        with fetch(path) as response:
            body = response.read().decode()
            assert response.status == status, (path, response.status, body)
            assert json.loads(body)['error']['code'] == code
            assert response.headers['Cache-Control'] == 'no-store'
            assert response.headers['X-Content-Type-Options'] == 'nosniff'
            assert '/Users/' not in body and 'password' not in body.lower()

    for path in ['/backend/config/local.example.php', '/backend/.env.example',
                 '/API_CONTRACT.yaml', '/Images/', '/%2e%2e/README.md']:
        with fetch(path) as response:
            assert response.status == 404, path
    print('PASS: 11 HTTP cases; safe errors and private-file restrictions. No database connected.')
finally:
    server.terminate()
    server.wait(timeout=5)
