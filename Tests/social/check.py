#!/usr/bin/env python3
import argparse
import base64
import copy
import hashlib
import http.cookiejar
import http.client
import http.server
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import socket
import select
import socketserver
import threading
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
DB_IMAGE = 'mariadb:11.8.9@sha256:f16d7a344413e519dff518f27bdb221388f84b0d274c97c4fb5f2cb0dc1b34fa'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self, origin):
        self.origin = origin
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = ''

    def request(self, path, method='GET', fields=None, payload=None, csrf=True):
        headers = {}
        if fields is not None:
            payload = urllib.parse.urlencode(fields).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        if csrf and self.csrf:
            headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(self.origin + path, data=payload, headers=headers, method=method)
        try:
            response = self.client.open(request, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read(4000000)
            return response.status, response.headers, body

    def page(self, path='/login'):
        status, headers, body = self.request(path)
        require(status == 200, 'Expected a successful page')
        text = body.decode()
        match = re.search(r'name="_csrf" value="([a-f0-9]{64})"', text) or re.search(r'"csrfToken":"([a-f0-9]{64})"', text)
        require(match is not None, 'Page exposes CSRF token')
        self.csrf = match.group(1)
        return text, headers

    def session_id(self):
        return next(cookie.value for cookie in self.cookies if cookie.name == 'simplemappr_session')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def process(command, *, data=None):
    result = subprocess.run(command, input=data, capture_output=True)
    if result.returncode:
        raise RuntimeError(f'Fixture command failed ({Path(command[0]).name}); sensitive output withheld')
    return result.stdout.decode().strip()


def docker(*args, data=None):
    return process(['docker', *map(str, args)], data=data)


class Forwarder(socketserver.ThreadingTCPServer):
    daemon_threads = True
    allow_reuse_address = True


class ForwardConnection(socketserver.BaseRequestHandler):
    def handle(self):
        try:
            with socket.create_connection(self.server.fixture.app_address, timeout=5) as remote:
                peers = [self.request, remote]
                while True:
                    readable, _, _ = select.select(peers, [], [], 20)
                    if not readable:
                        return
                    for source in readable:
                        data = source.recv(65536)
                        if not data:
                            return
                        (remote if source is self.request else self.request).sendall(data)
        except OSError:
            return


class PrefixProxy(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def forward(self):
        prefix = '/simplemappr'
        if not self.path.startswith(prefix + '/'):
            self.send_error(404)
            return
        size = int(self.headers.get('Content-Length', '0'))
        body = self.rfile.read(size) if size else None
        upstream = http.client.HTTPConnection(*self.server.fixture.app_address, timeout=25)
        try:
            upstream.request(self.command, self.path[len(prefix):], body=body,
                             headers={key: value for key, value in self.headers.items() if key.lower() != 'connection'})
            response = upstream.getresponse()
            raw = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ['connection', 'transfer-encoding', 'content-length']:
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
        finally:
            upstream.close()


def retry(call, message, seconds=100):
    deadline = time.monotonic() + seconds
    while True:
        try:
            return call()
        except (RuntimeError, OSError, urllib.error.URLError):
            if time.monotonic() > deadline:
                raise RuntimeError(message) from None
            time.sleep(1)


def certificates(directory):
    def openssl(*args):
        return process(['openssl', *map(str, args)])
    openssl('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=Disposable social fixture CA', '-keyout', directory / 'ca-key.pem', '-out', directory / 'ca.pem')
    openssl('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=oauth2.googleapis.com', '-keyout', directory / 'tls-key.pem', '-out', directory / 'server.csr')
    (directory / 'extensions').write_text('subjectAltName=DNS:oauth2.googleapis.com,DNS:www.googleapis.com,DNS:github.com,DNS:api.github.com,DNS:accounts.google.com\nextendedKeyUsage=serverAuth\n')
    (directory / 'wrong-extensions').write_text('subjectAltName=DNS:wrong.example.invalid\nextendedKeyUsage=serverAuth\n')
    for output, extensions in [('cert.pem', 'extensions'), ('wrong-cert.pem', 'wrong-extensions')]:
        openssl('x509', '-req', '-in', directory / 'server.csr', '-CA', directory / 'ca.pem', '-CAkey', directory / 'ca-key.pem', '-CAcreateserial', '-days', '1', '-extfile', directory / extensions, '-out', directory / output)
    openssl('genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', directory / 'signing.pem')
    modulus = openssl('rsa', '-in', directory / 'signing.pem', '-noout', '-modulus').split('=', 1)[1]
    n = base64.urlsafe_b64encode(bytes.fromhex(modulus)).rstrip(b'=').decode()
    (directory / 'jwk.json').write_text(json.dumps({'kty': 'RSA', 'use': 'sig', 'alg': 'RS256', 'kid': 'fixture-key', 'n': n, 'e': 'AQAB'}))
    (directory / 'php.ini').write_text('curl.cainfo=/fixture/ca.pem\n')
    for name in ['ca-key.pem', 'tls-key.pem', 'signing.pem']:
        (directory / name).chmod(0o600)
    for name in ['ca.pem', 'php.ini']:
        (directory / name).chmod(0o644)
    shutil.copyfile(ROOT / 'Tests/social/provider.py', directory / 'provider.py')


class Fixture:
    def __init__(self, image, directory):
        self.image = image
        self.directory = directory
        self.name = 'simplemappr-social-' + secrets.token_hex(6)
        self.network = self.name + '-network'
        self.app = self.name + '-app'
        self.db = self.name + '-db'
        self.provider = self.name + '-provider'
        self.volumes = [self.name + '-' + kind for kind in ['assets', 'database', 'sessions', 'output', 'tmp']]
        self.containers = []
        self.created_volumes = []
        self.network_created = False
        self.app_address = ('127.0.0.1', 1)
        self.forwarder = Forwarder(('127.0.0.1', 0), ForwardConnection)
        self.forwarder.fixture = self
        threading.Thread(target=self.forwarder.serve_forever, daemon=True).start()
        self.origin = 'http://127.0.0.1:' + str(self.forwarder.server_address[1])
        self.owner_password = secrets.token_urlsafe(32)
        self.env = directory / 'application.env'
        self.count = 0

    def passed(self, message):
        self.count += 1
        print('PASS ' + message, flush=True)

    def start(self):
        docker('image', 'inspect', self.image)
        fixture_dir = self.directory / 'provider'
        fixture_dir.mkdir(mode=0o755)
        fixture_dir.chmod(0o755)
        certificates(fixture_dir)
        password_hash = docker('run', '--rm', '-i', '--network', 'none', '--entrypoint', 'php', self.image, '-r', '$p=stream_get_contents(STDIN); echo password_hash($p,PASSWORD_DEFAULT);', data=self.owner_password.encode())
        db_password = secrets.token_hex(32)
        env = {
            'SIMPLEMAPPR_ENV': 'testing', 'SIMPLEMAPPR_URL': self.origin,
            'SIMPLEMAPPR_DB_HOST': 'db', 'SIMPLEMAPPR_DB_NAME': 'simplemappr', 'SIMPLEMAPPR_DB_USER': 'simplemappr', 'SIMPLEMAPPR_DB_PASSWORD': db_password,
            'SIMPLEMAPPR_OWNER_USERNAME': 'fixture-owner', 'SIMPLEMAPPR_OWNER_PASSWORD_HASH': password_hash,
            'SIMPLEMAPPR_GOOGLE_CLIENT_ID': 'fixture-google-client', 'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET': 'fixture-google-secret',
            'SIMPLEMAPPR_GITHUB_CLIENT_ID': 'fixture-github-client', 'SIMPLEMAPPR_GITHUB_CLIENT_SECRET': 'fixture-github-secret',
            'MARIADB_DATABASE': 'simplemappr', 'MARIADB_USER': 'simplemappr', 'MARIADB_PASSWORD': db_password, 'MARIADB_ROOT_PASSWORD': secrets.token_hex(32),
        }
        self.env.write_text(''.join(key + '=' + value + '\n' for key, value in env.items()))
        self.env.chmod(0o600)
        docker('network', 'create', '--internal', self.network)
        self.network_created = True
        for volume in self.volumes:
            docker('volume', 'create', volume)
            self.created_volumes.append(volume)
        docker('run', '--rm', '--network', 'none', '-v', self.volumes[0] + ':/srv/simplemappr/mapserver/maps', self.image, 'scripts/bootstrap-assets')
        self.containers.append(self.db)
        docker('run', '-d', '--name', self.db, '--network', self.network, '--network-alias', 'db', '--env-file', self.env, '-v', self.volumes[1] + ':/var/lib/mysql', DB_IMAGE)
        retry(lambda: docker('exec', self.db, 'healthcheck.sh', '--connect', '--innodb_initialized'), 'Disposable database did not start')
        docker('run', '--rm', '--network', self.network, '--env-file', self.env, '-v', self.volumes[0] + ':/srv/simplemappr/mapserver/maps:ro', self.image, 'scripts/initialize-database')
        self.containers.append(self.provider)
        docker('run', '-d', '--name', self.provider, '--network', self.network,
               '--network-alias', 'oauth2.googleapis.com', '--network-alias', 'www.googleapis.com', '--network-alias', 'github.com', '--network-alias', 'api.github.com', '--network-alias', 'accounts.google.com',
               '-v', str(fixture_dir) + ':/fixture:ro', '--entrypoint', 'python3', self.image, '/fixture/provider.py')
        address = json.loads(docker('inspect', self.provider))[0]['NetworkSettings']['Networks'][self.network]['IPAddress']
        self.provider_address = address
        self.control_url = 'http://' + address + ':8000'
        retry(lambda: self.control('/'), 'Disposable provider did not start')
        self.start_app()
        self.passed('isolated application, database and HTTPS providers started')

    def start_app(self, overrides=None):
        if self.app in self.containers:
            docker('rm', '-f', self.app)
        else:
            self.containers.append(self.app)
        arguments = ['run', '-d', '--name', self.app, '--network', self.network, '--env-file', self.env]
        for key, value in (overrides or {}).items():
            arguments += ['--env', key + '=' + value]
        for volume, target in zip([self.volumes[0], self.volumes[2], self.volumes[3], self.volumes[4]], ['/srv/simplemappr/mapserver/maps:ro', '/var/lib/php/sessions', '/var/lib/simplemappr', '/srv/simplemappr/public/tmp']):
            arguments += ['-v', volume + ':' + target]
        fixture_dir = self.directory / 'provider'
        arguments += ['-v', str(fixture_dir) + ':/fixture:ro', '-v', str(fixture_dir / 'php.ini') + ':/etc/php/8.4/apache2/conf.d/99-social-fixture.ini:ro',
                      self.image]
        docker(*arguments)
        address = json.loads(docker('inspect', self.app))[0]['NetworkSettings']['Networks'][self.network]['IPAddress']
        self.app_address = (address, 80)
        def ready():
            require(Browser(self.origin).request('/_runtime')[0] == 200, 'Waiting for application')
        retry(ready, 'Disposable application did not start')

    def control(self, path, data=None):
        request = urllib.request.Request(self.control_url + path, data=json.dumps(data).encode() if data is not None else None,
                                         headers={'Content-Type': 'application/json'})
        client = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with client.open(request, timeout=10) as response:
            return json.load(response)

    def begin(self, browser, provider='google'):
        browser.page('/login')
        status, headers, _ = browser.request('/auth/' + provider + '/start', 'POST', fields={'_csrf': browser.csrf})
        require(status == 303, 'Sign-in initiation redirects')
        return headers['Location']

    def issue(self, authorize, provider='google', **values):
        return self.control('/codes', {'authorize': authorize, 'provider': provider, **values})

    def callback(self, browser, flow, provider='google', **values):
        parameters = {'state': flow['state'], **({} if 'error' in values else {'code': flow['code']}), **values}
        return browser.request('/auth/' + provider + '/callback?' + urllib.parse.urlencode(parameters))

    def login(self, browser, provider='google', subject='fixture-one', **values):
        authorize = self.begin(browser, provider)
        original_session, original_csrf = browser.session_id(), browser.csrf
        flow = self.issue(authorize, provider, subject=subject, **values)
        status, headers, _ = self.callback(browser, flow, provider)
        require(status == 303 and headers['Location'] == '/', 'Social callback succeeds')
        require(browser.session_id() != original_session, 'Social login rotates session cookie')
        _, page_headers = browser.page('/')
        require(browser.csrf != original_csrf, 'Social login rotates CSRF token')
        require(browser.request('/usermap')[0] == 200, 'Social user reaches private maps')
        cookie = next(cookie for cookie in browser.cookies if cookie.name == 'simplemappr_session')
        require(cookie.has_nonstandard_attr('HttpOnly') and cookie.get_nonstandard_attr('SameSite') == 'Lax', 'Session cookie flags')
        return flow

    def local_login(self, browser):
        browser.page('/admin')
        status, headers, _ = browser.request('/session', 'POST', fields={'_csrf': browser.csrf, 'username': 'fixture-owner', 'password': self.owner_password})
        require(status == 303 and headers['Location'] == '/', 'Local owner login')
        browser.page('/')

    def sql(self, query):
        return docker('exec', '-i', self.db, 'sh', '-c', 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u simplemappr -N -B simplemappr', data=query.encode())

    def save(self, browser, title):
        document = copy.deepcopy(json.loads((ROOT / 'Tests/fixtures/editor-map-response.json').read_text())['map'])
        document['save']['title'] = title
        status, _, body = browser.request('/usermap/import', 'POST', fields={'settings': json.dumps(document)})
        require(status == 200, 'Private map import succeeds')
        return json.loads(body)['mid']

    def expire(self, browser, field):
        require(re.fullmatch(r'[A-Za-z0-9,-]{20,128}', browser.session_id()) is not None, 'Session identifier format')
        script = '$i=json_decode(stream_get_contents(STDIN),true);session_id($i["id"]);session_start();if($i["field"]==="activity"){$_SESSION["activity"]=time()-7201;}else{foreach($_SESSION["social_flows"] as &$f){$f["created"]=time()-301;}}session_write_close();'
        docker('exec', '-i', '--user', 'www-data', self.app, 'php', '-r', script, data=json.dumps({'id': browser.session_id(), 'field': field}).encode())

    def browser_checks(self, image, diagnostics=None):
        docker('image', 'inspect', image)
        arguments = ['docker', 'run', '--rm', '--network', 'host', '--shm-size=1g',
                     '-e', 'SIMPLEMAPPR_TEST_URL=' + self.origin,
                     '-e', 'SIMPLEMAPPR_FIXTURE_ADDRESS=' + self.provider_address,
                     '-v', str(ROOT / 'Tests/social/browser.mjs') + ':/contract/social.mjs:ro']
        if diagnostics is not None:
            (diagnostics / 'browser-error.json').touch(mode=0o600)
            arguments += ['-e', 'SIMPLEMAPPR_BROWSER_DIAGNOSTICS=1', '-v', str(diagnostics) + ':/diagnostics']
        result = subprocess.run([*arguments, image, 'node', 'social.mjs'], capture_output=True)
        if result.returncode:
            description = 'Chromium social check failed'
            for line in result.stderr.decode(errors='replace').splitlines():
                try:
                    failure = json.loads(line)
                except ValueError:
                    continue
                if isinstance(failure, dict) and failure.get('provider') in ['', 'Google', 'GitHub'] and failure.get('stage') in [
                    'launch', 'open-login', 'click-provider', 'return-to-editor', 'signed-in-control',
                    'editor-ready', 'open-my-maps', 'private-maps-request', 'sign-out', 'signed-out-control',
                ] and failure.get('category') in ['TimeoutError', 'Error', 'AssertionError', 'TypeError']:
                    description += ' during ' + failure['provider'] + '/' + failure['stage'] + ' (' + failure['category'] + ')'
                    break
            if diagnostics is not None:
                (diagnostics / 'browser-process.stderr').write_bytes(result.stderr)
                (diagnostics / 'browser-process.stderr').chmod(0o600)
                description += '; private diagnostics in ' + str(diagnostics)
            raise RuntimeError(description)
        self.passed('Chromium clicks Google and GitHub buttons through HTTPS callbacks, My Maps and sign-out')

    def cleanup(self):
        self.forwarder.shutdown()
        self.forwarder.server_close()
        for container in reversed(self.containers):
            subprocess.run(['docker', 'rm', '-f', container], capture_output=True)
        for volume in self.created_volumes:
            subprocess.run(['docker', 'volume', 'rm', volume], capture_output=True)
        if self.network_created:
            subprocess.run(['docker', 'network', 'rm', self.network], capture_output=True)


def checks(fixture):
    origin = fixture.origin
    guest = Browser(origin)
    page, headers = guest.page()
    require('Continue with Google' in page and 'Continue with GitHub' in page and 'Apple' not in page, 'Configured provider buttons')
    require('no-store' in headers['Cache-Control'] and headers['Referrer-Policy'] == 'no-referrer', 'Login response privacy headers')
    require(guest.request('/auth/google/start', 'POST', fields={}, csrf=False)[0] == 403, 'Initiation requires CSRF')
    require(guest.request('/auth/google/start')[0] != 303, 'GET cannot initiate sign-in')
    fixture.passed('provider buttons, login privacy and initiation CSRF')

    owner = Browser(origin)
    fixture.local_login(owner)
    owner_map = fixture.save(owner, 'owner-map')
    owner_bytes = owner.request(f'/usermap/{owner_map}/settings.json')[2]
    fixture.passed('existing local owner signs in and saves a private map')

    google = Browser(origin)
    flow = fixture.login(google)
    google_map = fixture.save(google, 'shared-title')
    google_uid = fixture.sql(f'SELECT uid FROM maps WHERE mid={google_map};')
    require(fixture.sql(f'SELECT role FROM users WHERE uid={google_uid};') == '1', 'Social users have ordinary role')
    current_csrf = google.csrf
    google.csrf = 'invalid-csrf'
    require(google.request('/share', 'POST', fields={'mid': google_map})[0] == 403, 'Private mutations require current session CSRF')
    google.csrf = current_csrf
    require(google.request('/auth/google/start', 'POST', fields={'_csrf': google.csrf})[0] == 409, 'Authenticated account switching is refused')
    require(google.request('/citation.json')[0] == 403 and google.request('/user')[0] == 403, 'Social users cannot administer')
    fixture.passed('Google HTTPS code exchange, RS256 validation, session rotation and ordinary role')

    github = Browser(origin)
    fixture.login(github, 'github', 'fixture-one')
    github_map = fixture.save(github, 'shared-title')
    github_uid = fixture.sql(f'SELECT uid FROM maps WHERE mid={github_map};')
    require(google_uid != github_uid, 'Same-email profiles and same subject remain separate across providers')
    require(fixture.sql(f'SELECT role FROM users WHERE uid={github_uid};') == '1', 'GitHub role stays ordinary')
    fixture.passed('GitHub HTTPS PKCE exchange, authenticated public profile and separate private account')

    for browser, own_map, foreign_maps in [(google, google_map, [owner_map, github_map]), (github, github_map, [owner_map, google_map]), (owner, owner_map, [google_map, github_map])]:
        require(browser.request(f'/usermap/{own_map}.json')[0] == 200, 'Owner reads own map')
        require(browser.request(f'/map/{own_map}.png')[2].startswith(b'\x89PNG\r\n\x1a\n'), 'Owner exports own map')
        for foreign in foreign_maps:
            for path in [f'/usermap/{foreign}.json', f'/usermap/{foreign}/settings.json', f'/map/{foreign}.png']:
                require(browser.request(path)[0] == 404, 'Cross-account read and export denied')
            require(browser.request(f'/usermap/{foreign}', 'DELETE')[0] == 404, 'Cross-account deletion denied')
            require(browser.request('/share', 'POST', fields={'mid': foreign})[0] == 404, 'Cross-account share creation denied')
    fixture.passed('Google, GitHub and local owner map read, export, delete and share isolation')

    status, _, body = google.request('/share', 'POST', fields={'mid': google_map})
    require(status == 200, 'Owner creates explicit sharing link')
    share = json.loads(body)
    require(github.request('/share/' + str(share['sid']), 'DELETE')[0] == 404, 'Foreign share revocation denied')
    require(guest.request(urllib.parse.urlsplit(share['url']).path + '.json')[0] == 200, 'Explicit share is readable by guest')
    require(google.request('/share/' + str(share['sid']), 'DELETE')[0] == 200, 'Owner revokes share')
    require(guest.request(urllib.parse.urlsplit(share['url']).path + '.json')[0] == 404, 'Revoked share stops working')
    fixture.passed('explicit share creation, foreign revocation denial and owner revocation')

    repeated = Browser(origin)
    fixture.login(repeated)
    require(repeated.request(f'/usermap/{google_map}.json')[0] == 200, 'Repeat identity reaches original private maps')
    require(fixture.sql("SELECT COUNT(*) FROM users WHERE identifier LIKE 'oidc:%' OR identifier LIKE 'oauth:%';") == '2', 'Repeat login does not duplicate identity')
    fixture.passed('repeated provider identity retains original account')

    expected_id = 'oidc:google:' + hashlib.sha256(b'https://accounts.google.com\x00fixture-one').hexdigest()
    require(fixture.sql(f"SELECT uid FROM users WHERE identifier='{expected_id}';") == google_uid, 'Identifier derives from verified issuer and subject')
    case_variant = Browser(origin)
    fixture.login(case_variant, subject='Fixture-one')
    require(case_variant.request(f'/usermap/{google_map}.json')[0] == 404, 'Case-sensitive subjects stay separate')
    renamed = Browser(origin)
    fixture.login(renamed, 'github', 'fixture-one', name='Renamed account', login='renamed-login')
    require(renamed.request(f'/usermap/{github_map}.json')[0] == 200, 'GitHub numeric ID survives account rename')
    github_id = int.from_bytes(hashlib.sha256(b'fixture-one').digest()[:6], 'big') + 1
    expected_github = 'oauth:github:' + hashlib.sha256(('https://github.com\x00' + str(github_id)).encode()).hexdigest()
    require(fixture.sql(f"SELECT uid FROM users WHERE identifier='{expected_github}';") == github_uid, 'GitHub identity uses fixed issuer and numeric profile ID')
    fixture.passed('case-sensitive Google subjects and stable GitHub numeric IDs retain ownership')

    fixture.expire(repeated, 'activity')
    require(repeated.request('/usermap')[0] == 403, 'Two-hour inactive session expires')
    expired_flow_browser = Browser(origin)
    expired_flow = fixture.issue(fixture.begin(expired_flow_browser))
    fixture.expire(expired_flow_browser, 'flows')
    before = fixture.control('/')['token_calls']
    require(fixture.callback(expired_flow_browser, expired_flow)[1]['Location'].startswith('/login'), 'Five-minute pending sign-in expires')
    require(fixture.control('/')['token_calls'] == before, 'Expired pending sign-in never exchanges code')
    fixture.passed('two-hour sessions and five-minute sign-in requests expire')

    cases = [
        ('wrong browser', lambda b, f: fixture.callback(Browser(origin), f)),
        ('wrong state', lambda b, f: fixture.callback(b, f, state='invalid-state')),
        ('wrong provider', lambda b, f: fixture.callback(b, f, 'github')),
        ('cancellation', lambda b, f: fixture.callback(b, f, error='access_denied')),
    ]
    for label, action in cases:
        browser = Browser(origin)
        candidate = fixture.issue(fixture.begin(browser))
        before = fixture.control('/')['token_calls']
        status, response_headers, _ = action(browser, candidate)
        require(status == 303 and response_headers['Location'].startswith('/login'), label + ' fails closed')
        require(fixture.control('/')['token_calls'] == before, label + ' never exchanges authorization code')
        require(browser.request('/usermap')[0] == 403, label + ' leaves browser signed out')
    fixture.passed('wrong browser, state, provider and cancellation fail before token exchange')

    replay = Browser(origin)
    success = fixture.login(replay, subject='replay-account')
    before = fixture.control('/')['token_calls']
    require(fixture.callback(replay, success)[1]['Location'].startswith('/login'), 'Replayed state rejected')
    require(fixture.control('/')['token_calls'] == before, 'Replayed state never reaches provider')
    fixture.passed('callback state is one-use')

    parallel = Browser(origin)
    first = fixture.issue(fixture.begin(parallel), subject='parallel-account')
    second = fixture.issue(fixture.begin(parallel), subject='other-parallel-account')
    require(fixture.callback(parallel, first)[1]['Location'] == '/', 'Earlier browser tab can complete sign-in')
    before = fixture.control('/')['token_calls']
    require(fixture.callback(parallel, second)[1]['Location'].startswith('/login'), 'Successful login clears other pending tabs')
    require(fixture.control('/')['token_calls'] == before, 'Other pending tab cannot exchange code')
    fixture.passed('parallel pending flows work until successful login clears them')

    accounts_before_failure = fixture.sql('SELECT COUNT(*) FROM users;')
    for mode in ['token_redirect', 'token_oversize', 'token_nonjson', 'jwks_redirect', 'jwks_oversize', 'jwks_nonjson', 'bad_tls']:
        browser = Browser(origin)
        candidate = fixture.issue(fixture.begin(browser), mode=mode, subject='must-not-be-created')
        counts_before = fixture.control('/')
        status, response_headers, _ = fixture.callback(browser, candidate)
        counts_after = fixture.control('/')
        if mode == 'token_redirect':
            require(counts_after['token_calls'] == counts_before['token_calls'] + 1, 'Token redirect is not followed')
        if mode == 'jwks_redirect':
            require(counts_after['jwks_calls'] == counts_before['jwks_calls'] + 1, 'JWKS redirect is not followed')
        fixture.control('/reset', {})
        require(status == 303 and response_headers['Location'].startswith('/login'), mode + ' rejected')
        require(browser.request('/usermap')[0] == 403, mode + ' does not create authenticated session')
    require(fixture.sql('SELECT COUNT(*) FROM users;') == accounts_before_failure, 'Failed transport does not create accounts')
    for mode in ['token_redirect', 'token_oversize', 'token_nonjson', 'token_scope', 'profile_redirect', 'profile_oversize', 'profile_nonjson', 'profile_error', 'profile_bad_id', 'bad_tls']:
        browser = Browser(origin)
        candidate = fixture.issue(fixture.begin(browser, 'github'), 'github', mode=mode, subject='must-not-be-created')
        counts_before = fixture.control('/')
        status, response_headers, _ = fixture.callback(browser, candidate, 'github')
        counts_after = fixture.control('/')
        if mode == 'profile_redirect':
            require(counts_after['profile_calls'] == counts_before['profile_calls'] + 1, 'Profile redirect is not followed')
        require(counts_after['jwks_calls'] == counts_before['jwks_calls'], 'GitHub never requests signing keys')
        fixture.control('/reset', {})
        require(status == 303 and response_headers['Location'].startswith('/login'), mode + ' rejected for GitHub')
        require(browser.request('/usermap')[0] == 403, mode + ' leaves GitHub session unauthenticated')
    require(fixture.sql('SELECT COUNT(*) FROM users;') == accounts_before_failure, 'Failed GitHub transport creates no accounts')
    fixture.passed('real cURL rejects token/JWKS/profile redirects, oversized bodies, non-JSON, invalid scope/profile and TLS hostname mismatch')

    pending_browser = Browser(origin)
    pending = fixture.issue(fixture.begin(pending_browser))
    before = fixture.control('/')['token_calls']
    fixture.start_app({'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET': 'fixture-rotated-secret'})
    require(google.request('/usermap')[0] == 403, 'Credential rotation revokes existing provider session')
    require(github.request('/usermap')[0] == 200, 'Credential rotation preserves other provider session')
    require(owner.request('/usermap')[0] == 200, 'Credential rotation preserves local owner session')
    require(fixture.callback(pending_browser, pending)[1]['Location'].startswith('/login'), 'Credential rotation invalidates pending flows')
    require(fixture.control('/')['token_calls'] == before, 'Rotated pending flow fails before token exchange')
    fixture.passed('credential rotation revokes affected sessions and pending flows only')

    fixture.start_app()
    reenabled_google = Browser(origin)
    fixture.login(reenabled_google)
    fixture.start_app({'SIMPLEMAPPR_GOOGLE_CLIENT_ID': '', 'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET': ''})
    require(reenabled_google.request('/usermap')[0] == 403, 'Disabled provider revokes an existing valid session')
    page, _ = Browser(origin).page()
    require('Continue with Google' not in page and 'Continue with GitHub' in page, 'Disabled provider button hidden')
    require(owner.request('/usermap')[0] == 200 and github.request('/usermap')[0] == 200, 'Other login methods survive provider disablement')
    fixture.passed('disabled provider disappears while local owner and other provider remain usable')

    fixture.start_app()
    recovered = Browser(origin)
    fixture.local_login(recovered)
    require(recovered.request(f'/usermap/{owner_map}/settings.json')[2] == owner_bytes, 'Original owner document bytes unchanged')
    require(fixture.control('/')['token_calls'] > 0 and fixture.control('/')['jwks_calls'] > 0 and fixture.control('/')['profile_calls'] > 0, 'Integration exercised actual provider transport')
    fixture.passed('local owner recovery and original private map bytes survive provider configuration changes')


def prefix_checks(fixture):
    proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 0), PrefixProxy)
    proxy.fixture = fixture
    threading.Thread(target=proxy.serve_forever, daemon=True).start()
    base = 'http://127.0.0.1:' + str(proxy.server_address[1]) + '/simplemappr'
    try:
        fixture.start_app({'SIMPLEMAPPR_URL': base})
        browser = Browser(base)
        page, _ = browser.page('/admin')
        require('action="/simplemappr/session"' in page, 'Prefixed local owner form')
        status, headers, _ = browser.request('/session', 'POST', fields={
            '_csrf': browser.csrf, 'username': 'fixture-owner', 'password': fixture.owner_password})
        require(status == 303 and headers['Location'] == '/simplemappr/', 'Prefixed local owner callback')
        require(browser.request('/usermap')[0] == 200, 'Prefixed local owner reaches private maps')
        browser.page('/')
        require(browser.request('/logout', 'POST')[1]['Location'] == '/simplemappr/', 'Prefixed owner sign-out')
        for provider in ['google', 'github']:
            require('action="/simplemappr/auth/' + provider + '/start"' in page, 'Prefixed provider form')
            browser = Browser(base)
            authorize = fixture.begin(browser, provider)
            flow = fixture.issue(authorize, provider, subject='fixture-one')
            require(flow['redirect_uri'] == base + '/auth/' + provider + '/callback', 'Prefixed callback address')
            status, headers, _ = fixture.callback(browser, flow, provider)
            require(status == 303 and headers['Location'] == '/simplemappr/', 'Successful callback stays under prefix')
            require(browser.request('/usermap')[0] == 200, 'Prefixed social session reaches private maps')
        browser = Browser(base)
        denied = fixture.issue(fixture.begin(browser, 'github'), 'github')
        status, headers, _ = fixture.callback(browser, denied, 'github', error='access_denied')
        require(status == 303 and headers['Location'].startswith('/simplemappr/login'), 'Failed callback stays under prefix')
        page, _ = browser.page()
        require('Social sign-in could not be completed' in page, 'Prefixed callback exposes safe retry message')
        fixture.passed('prefix-stripping proxy preserves provider forms, exact callback URL, private sessions and success/error redirects')
    finally:
        proxy.shutdown()
        proxy.server_close()
        fixture.start_app()


def main():
    parser = argparse.ArgumentParser(description='Exercise social sign-in against disposable HTTPS providers and database using an HTTP cookie client.')
    parser.add_argument('--diagnostics-dir', type=Path, help='New protected directory for private browser failure details; never use public receipts')
    parser.add_argument('--browser-image', help='Optional prebuilt Tests/browser image; runs actual Chromium social sign-in controls without auth traces')
    parser.add_argument('--image', default='simplemappr-runtime:local', help='Already-built application image')
    arguments = parser.parse_args()
    os.umask(0o077)
    def interrupted(number, frame):
        raise SystemExit(128 + number)
    signal.signal(signal.SIGTERM, interrupted)
    if arguments.diagnostics_dir is not None:
        arguments.diagnostics_dir = arguments.diagnostics_dir.resolve()
        arguments.diagnostics_dir.mkdir(mode=0o700)
    with tempfile.TemporaryDirectory(prefix='simplemappr-social-') as private:
        fixture = Fixture(arguments.image, Path(private))
        try:
            fixture.start()
            checks(fixture)
            prefix_checks(fixture)
            if arguments.browser_image:
                fixture.browser_checks(arguments.browser_image, arguments.diagnostics_dir)
            print(f'Social sign-in HTTP integration passed ({fixture.count} assertion groups). Real provider console setup remains a separate deployment check.', flush=True)
        finally:
            fixture.cleanup()


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError) as error:
        print('FAIL ' + str(error), flush=True)
        raise SystemExit(1)
