#!/usr/bin/env python3
import base64
import hashlib
import http.server
import json
import secrets
import ssl
import subprocess
import threading
import time
import urllib.parse
from pathlib import Path

ROOT = Path('/fixture')
STATE = {'codes': {}, 'token_calls': 0, 'jwks_calls': 0, 'mode': ''}
LOCK = threading.Lock()
TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad'


def encoded(value):
    return base64.urlsafe_b64encode(value).rstrip(b'=').decode()


def token(flow):
    now = int(time.time())
    provider = flow['provider']
    tenant = flow.get('tenant', TENANT)
    claims = {
        'iss': 'https://accounts.google.com' if provider == 'google' else f'https://login.microsoftonline.com/{tenant}/v2.0',
        'sub': flow['subject'], 'aud': f'fixture-{provider}-client', 'iat': now,
        'exp': now + 300, 'nonce': flow['nonce'], 'name': 'Fixture account',
        'email': flow.get('email', 'same@example.invalid'),
    }
    if provider == 'microsoft':
        claims['tid'] = tenant
    signing_input = '.'.join(encoded(json.dumps(part, separators=(',', ':')).encode()) for part in [
        {'alg': 'RS256', 'typ': 'JWT', 'kid': 'fixture-key'}, claims,
    ]).encode()
    signature = subprocess.run(['openssl', 'dgst', '-sha256', '-sign', str(ROOT / 'signing.pem')],
                               input=signing_input, capture_output=True, check=True).stdout
    return signing_input.decode() + '.' + encoded(signature)


def issue_code(data):
    query = urllib.parse.parse_qs(urllib.parse.urlsplit(data['authorize']).query)
    provider = data['provider']
    expected_host = 'accounts.google.com' if provider == 'google' else 'login.microsoftonline.com'
    required = {'response_type': 'code', 'client_id': f'fixture-{provider}-client', 'code_challenge_method': 'S256'}
    if urllib.parse.urlsplit(data['authorize']).hostname != expected_host or any(query.get(k) != [v] for k, v in required.items()) or set(query.get('scope', [''])[0].split()) != {'openid', 'profile'}:
        raise ValueError('Authorization contract failed')
    code = secrets.token_urlsafe(32)
    flow = {'provider': provider, 'subject': data.get('subject', 'fixture-one'), 'email': data.get('email', 'same@example.invalid'),
            'tenant': data.get('tenant', TENANT), 'nonce': query['nonce'][0], 'challenge': query['code_challenge'][0], 'redirect_uri': query['redirect_uri'][0]}
    with LOCK:
        STATE['codes'][code] = flow
        STATE['mode'] = data.get('mode', '')
    if data.get('mode') == 'bad_tls':
        TLS.load_cert_chain(ROOT / 'wrong-cert.pem', ROOT / 'tls-key.pem')
    return {'code': code, 'state': query['state'][0], 'redirect_uri': flow['redirect_uri']}


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def answer(self, status, value, content_type='application/json', headers=None):
        raw = value if isinstance(value, bytes) else json.dumps(value).encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(raw)))
        for key, val in (headers or {}).items():
            self.send_header(key, val)
        self.end_headers()
        try:
            self.wfile.write(raw)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def do_GET(self):
        if self.server.server_port == 8000:
            self.answer(200, {key: STATE[key] for key in ['token_calls', 'jwks_calls']})
            return
        host = self.headers.get('Host')
        authorization = {('accounts.google.com', '/o/oauth2/v2/auth'): 'google', ('login.microsoftonline.com', '/common/oauth2/v2.0/authorize'): 'microsoft'}.get((host, urllib.parse.urlsplit(self.path).path))
        if authorization:
            flow = issue_code({'authorize': 'https://' + host + self.path, 'provider': authorization, 'subject': 'browser-' + authorization})
            target = flow['redirect_uri'] + '?' + urllib.parse.urlencode({'code': flow['code'], 'state': flow['state']})
            self.answer(303, {}, headers={'Location': target})
            return
        allowed = {
            ('www.googleapis.com', '/oauth2/v3/certs'): 'google',
            ('login.microsoftonline.com', '/common/discovery/v2.0/keys'): 'microsoft',
        }
        provider = allowed.get((host, self.path))
        if provider is None:
            self.answer(404, {'error': 'Unknown fixture endpoint'})
            return
        with LOCK:
            STATE['jwks_calls'] += 1
            mode = STATE['mode']
        if mode == 'jwks_redirect':
            self.answer(302, {}, headers={'Location': 'https://www.googleapis.com/oauth2/v3/certs'})
        elif mode == 'jwks_nonjson':
            self.answer(200, b'<html>not keys</html>', 'text/html')
        else:
            key = json.loads((ROOT / 'jwk.json').read_text())
            if provider == 'microsoft':
                key['issuer'] = 'https://login.microsoftonline.com/{tenantid}/v2.0'
            value = {'keys': [key]}
            if mode == 'jwks_oversize':
                value['padding'] = 'x' * 2097152
            self.answer(200, value)

    def do_POST(self):
        size = int(self.headers.get('Content-Length', '0'))
        if size > 16384:
            self.answer(413, {})
            return
        raw = self.rfile.read(size)
        if self.server.server_port == 8000:
            data = json.loads(raw or b'{}')
            if self.path == '/reset':
                STATE['mode'] = ''
                TLS.load_cert_chain(ROOT / 'cert.pem', ROOT / 'tls-key.pem')
                self.answer(200, {})
                return
            if self.path != '/codes':
                self.answer(404, {})
                return
            self.answer(200, issue_code(data))
            return
        params = urllib.parse.parse_qs(raw.decode())
        host = self.headers.get('Host')
        provider = {('oauth2.googleapis.com', '/token'): 'google', ('login.microsoftonline.com', '/common/oauth2/v2.0/token'): 'microsoft'}.get((host, self.path))
        with LOCK:
            STATE['token_calls'] += 1
            flow = STATE['codes'].pop(params.get('code', [''])[0], None)
            mode = STATE['mode']
        if provider is None or flow is None or flow['provider'] != provider:
            self.answer(400, {'error': 'invalid_grant'})
            return
        expected = {'grant_type': 'authorization_code', 'client_id': f'fixture-{provider}-client',
                    'client_secret': f'fixture-{provider}-secret', 'redirect_uri': flow['redirect_uri']}
        verifier = params.get('code_verifier', [''])[0]
        if any(params.get(k) != [v] for k, v in expected.items()) or encoded(hashlib.sha256(verifier.encode()).digest()) != flow['challenge']:
            self.answer(400, {'error': 'invalid_grant'})
            return
        if mode == 'token_redirect':
            self.answer(302, {}, headers={'Location': 'https://oauth2.googleapis.com/token'})
        elif mode == 'token_nonjson':
            self.answer(200, b'<html>not a token</html>', 'text/html')
        else:
            value = {'token_type': 'Bearer', 'id_token': token(flow), 'access_token': 'fixture-unused-token'}
            if mode == 'token_oversize':
                value['padding'] = 'x' * 2097152
            self.answer(200, value)


TLS = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
TLS.load_cert_chain(ROOT / 'cert.pem', ROOT / 'tls-key.pem')
provider = http.server.ThreadingHTTPServer(('0.0.0.0', 443), Handler)
provider.socket = TLS.wrap_socket(provider.socket, server_side=True)
control = http.server.ThreadingHTTPServer(('0.0.0.0', 8000), Handler)
threading.Thread(target=provider.serve_forever, daemon=True).start()
control.serve_forever()
