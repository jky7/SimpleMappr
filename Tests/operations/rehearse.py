#!/usr/bin/env python3
"""Exercise backup and restore only through owned disposable Compose projects."""
import argparse
import http.cookiejar
import json
import os
from pathlib import Path
import pty
import re
import secrets
import select
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import release_bundle as operations


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = None

    def request(self, path, data=None, method=None, csrf=False):
        headers = {'X-CSRF-Token': self.csrf} if csrf else {}
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        request = urllib.request.Request(path if path.startswith('http') else self.base + path,
                                         data=data, method=method, headers=headers)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read()

    def login(self, credentials, succeeds=True):
        status, body = self.request('/admin')
        assert status == 200, 'Login form failed'
        token = re.search(rb'name="_csrf" value="([a-f0-9]+)"', body)
        assert token, 'Login form CSRF field missing'
        self.csrf = token[1].decode()
        status, body = self.request('/session', {**credentials, '_csrf': self.csrf})
        if succeeds:
            token = re.search(rb'name="_csrf" value="([a-f0-9]+)"', body)
            assert status == 200 and token and b'logout' in body.lower(), 'Owner login failed'
            self.csrf = token[1].decode()
        else:
            assert status in (200, 401, 403) and b'name="password"' in body, 'Previous owner credentials still authenticate'


def cli(arguments, expected=True):
    result = subprocess.run(arguments, stdin=subprocess.DEVNULL, capture_output=True)
    if (result.returncode == 0) != expected:
        raise AssertionError('Operations CLI did not return the expected status. Inspect private fixture logs.')
    if expected:
        return result.stdout
    return result.stderr


def rotation_cli(env_file, credentials, image):
    master, slave = pty.openpty()
    process = subprocess.Popen([str(ROOT / 'scripts/rotate-local-owner'), '--env-file', str(env_file), '--image', image],
                               stdin=slave, stdout=slave, stderr=slave, close_fds=True)
    os.close(slave)
    prompts = [(b'Owner username:', credentials['username']), (b'New owner password:', credentials['password']),
               (b'Confirm new owner password:', credentials['password'])]
    transcript = b''
    deadline = time.monotonic() + 45
    try:
        for prompt, value in prompts:
            while prompt not in transcript:
                if time.monotonic() > deadline:
                    raise AssertionError('Interactive owner rotation timed out')
                ready, _, _ = select.select([master], [], [], 1)
                if ready:
                    transcript += os.read(master, 4096)
                elif process.poll() is not None:
                    raise AssertionError('Interactive owner rotation exited early')
            transcript = transcript.split(prompt, 1)[1]
            os.write(master, value.encode() + b'\n')
        process.wait(timeout=30)
        assert process.returncode == 0, 'Interactive owner rotation failed'
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()
        os.close(master)


def check_expiry(context, client):
    original = operations.query(context, b"SELECT mid,HEX(map) FROM maps ORDER BY mid;")
    prepare = "$guest='/srv/simplemappr/public/tmp/';$private='/var/lib/simplemappr/previews/';@mkdir($private,0700,true);$expired=str_repeat('a',32);$fresh=str_repeat('b',32);foreach([$guest,$private] as $dir){file_put_contents($dir.'preview-'.$expired.'.png','expired');file_put_contents($dir.'preview-'.$fresh.'.png','fresh');file_put_contents($dir.'preview-unrelated.txt','sentinel');touch($dir.'preview-'.$expired.'.png',time()-7200);touch($dir.'preview-'.$fresh.'.png',time());touch($dir.'preview-unrelated.txt',time()-7200);}file_put_contents($private.$expired.'.json',json_encode(['uid'=>1,'expires'=>time()-7200,'filename'=>'preview-'.$expired.'.png']));file_put_contents($private.$fresh.'.json',json_encode(['uid'=>1,'expires'=>time()+7200,'filename'=>'preview-'.$fresh.'.png']));file_put_contents($private.'unrelated.json',json_encode(['uid'=>1,'expires'=>1,'filename'=>'preview-unrelated.txt']));file_put_contents('/var/lib/simplemappr/preview-cleanup','0');"
    verify = "$guest='/srv/simplemappr/public/tmp/';$private='/var/lib/simplemappr/previews/';$a=str_repeat('a',32);$b=str_repeat('b',32);$checks=['expiredGuestRemoved'=>!file_exists($guest.'preview-'.$a.'.png'),'expiredPrivateRemoved'=>!file_exists($private.'preview-'.$a.'.png')&&!file_exists($private.$a.'.json'),'freshGuestRetained'=>is_file($guest.'preview-'.$b.'.png'),'freshPrivateRetained'=>is_file($private.'preview-'.$b.'.png')&&is_file($private.$b.'.json'),'unrelatedGuestRetained'=>is_file($guest.'preview-unrelated.txt'),'unrelatedPrivateRetained'=>is_file($private.'preview-unrelated.txt')&&is_file($private.'unrelated.json')];echo json_encode($checks);"
    operations.run_compose(context, ["exec", "-T", "--user", "www-data", "app", "php", "-r", prepare])
    assert client.request("/application.json", {"width":"200", "height":"100", "projection":"epsg:4326", "output":"png"})[0] == 200
    checks = json.loads(operations.run_compose(context, ["exec", "-T", "app", "php", "-r", verify]))
    assert all(checks.values()), "Eligible preview cleanup failed"
    assert original == operations.query(context, b"SELECT mid,HEX(map) FROM maps ORDER BY mid;"), "Cleanup changed saved documents"


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', type=Path, required=True)
    parser.add_argument('--source-project', default='simplemappr-ops-source-' + secrets.token_hex(4))
    parser.add_argument('--target-project', default='simplemappr-ops-target-' + secrets.token_hex(4))
    parser.add_argument('--source-port', type=operations.validate_port, default=18081)
    parser.add_argument('--target-port', type=operations.validate_port, default=18082)
    parser.add_argument('--image', default='simplemappr-runtime:local')
    parser.add_argument('--keep', action='store_true', help='Keep the owned fixture projects for an operator browser check.')
    args = parser.parse_args()
    assert args.source_project != args.target_project, 'Source and target must differ'
    assert args.source_port != args.target_port, 'Ports must differ'
    for project in (args.source_project, args.target_project):
        operations.validate_project(project)
        operations.refuse_existing_project(project)
    private = operations.new_directory(args.private_dir)
    credentials_file = private / 'credentials.json'
    env_file = private / 'source.env'
    cli([str(ROOT / 'scripts/configure-ci'), '--env-file', str(env_file), '--credentials-file', str(credentials_file), '--image', args.image])
    credentials = json.loads(operations.protected_file(credentials_file, 65536))
    source = operations.compose(args.source_project, env_file, image=args.image, port=args.source_port)
    contexts = []
    receipts = {'status': 'RUNNING', 'checks': []}
    target = None
    try:
        contexts.append(source)
        operations.run_compose(source, ['up', '-d', '--no-build', '--wait'])
        source_client = Client(f'http://127.0.0.1:{args.source_port}')
        source_client.login(credentials)
        form = {'save[title]': 'Montréal 😀 bundle Café', 'coords[0][title]': 'Café',
                'coords[0][data]': '43.6532,-79.3832\n45.5019,-73.5674', 'coords[0][color]': '210 30 35',
                'coords[0][shape]': 'circle', 'coords[0][size]': '14', 'layers[countries]': 'on',
                'layers[lakesOutline]': 'on', 'layers[lakes]': 'on', 'projection': 'epsg:4326',
                'projection_map': 'epsg:4326', 'origin_map': '0', 'origin': '0', 'bbox_map': '-180,-90,180,90',
                'width': '900', 'height': '450', 'output': 'png'}
        maps = []
        shares = []
        exports = {}
        for index in range(2):
            form['save[title]'] = f'Montréal 😀 bundle Café {index}'
            status, body = source_client.request('/usermap', form, csrf=True)
            assert status == 200, 'Fixture map save failed'
            mid = json.loads(body)['mid']
            maps.append(mid)
            status, body = source_client.request('/share', {'mid': str(mid)}, csrf=True)
            assert status == 200, 'Fixture share failed'
            share = json.loads(body)
            shares.append(urllib.parse.urlsplit(share['url']).path)
            if index:
                assert source_client.request('/share/' + str(share['sid']), method='DELETE', csrf=True)[0] == 200, 'Share revocation failed'
        for extension in ('png', 'svg', 'json', 'kml'):
            status, body = source_client.request(f'/map/{maps[0]}.{extension}')
            assert status == 200 and body, 'Fixture export failed'
            exports[extension] = body
        status, tiff_before = source_client.request('/application.json', dict(form, output='tif'))
        assert status == 200 and tiff_before[:2] in (b'II', b'MM'), 'Source TIFF export failed'
        status, settings = source_client.request(f'/usermap/{maps[0]}/settings.json')
        assert status == 200 and 'Montréal' in settings.decode(), 'Saved fixture settings failed'
        citation = {'citation[year]': '2026', 'citation[reference]': 'Café & Montréal 😀 bundle citation',
                    'citation[first_author_surname]': "D'Arcy", 'citation[doi]': '10.1234/bundle', 'citation[link]': 'https://example.org'}
        assert source_client.request('/citation', citation, csrf=True)[0] == 200, 'Citation fixture failed'
        raw_before = operations.query(source, b'SELECT mid,HEX(map) FROM maps ORDER BY mid;\nSELECT mid,COALESCE(token,"revoked") FROM shares ORDER BY mid;\nSELECT id,HEX(reference),HEX(first_author_surname) FROM citations ORDER BY id;\n')
        session_cookie = [(cookie.name, cookie.value) for cookie in source_client.cookies]
        operations.write_new(private / 'fixture.json', json.dumps({'maps': maps, 'shares': shares}, ensure_ascii=False).encode())
        receipts['checks'].append('Unicode maps and citation, original JSON bytes, active and revoked shares created through HTTP')
        backup_path = private / 'bundle'
        receipts['backup'] = json.loads(cli([str(ROOT / 'scripts/backup'), str(backup_path), '--project', args.source_project, '--env-file', str(env_file)]))
        restore_arguments = [str(ROOT / 'scripts/restore'), str(backup_path), '--project', args.target_project, '--port', str(args.target_port)]
        cli([str(ROOT / 'scripts/restore'), str(backup_path), '--project', args.source_project, '--port', str(args.target_port)], expected=False)
        sql_path = backup_path / 'database.sql'
        original_sql = sql_path.read_bytes()
        sql_path.write_bytes(original_sql + b'corruption')
        cli(restore_arguments, expected=False)
        operations.refuse_existing_project(args.target_project)
        sql_path.write_bytes(original_sql)
        sql_path.rename(backup_path / 'undeclared.sql')
        cli(restore_arguments, expected=False)
        (backup_path / 'undeclared.sql').rename(sql_path)
        sql_path.rename(private / 'linked.sql')
        sql_path.symlink_to(private / 'linked.sql')
        cli(restore_arguments, expected=False)
        sql_path.unlink()
        (private / 'linked.sql').rename(sql_path)
        manifest_path = backup_path / 'manifest.json'
        original_manifest = manifest_path.read_bytes()
        manifest_path.write_bytes(b'{}')
        cli(restore_arguments, expected=False)
        manifest_path.write_bytes(original_manifest)
        operations.refuse_existing_project(args.target_project)
        receipts['checks'].append('Same-project, corrupt, missing, symlink, and malformed bundles refused before target creation')
        target_env = private / (args.target_project + '-deployment') / 'deployment.env'
        target = operations.compose(args.target_project, target_env,
                                    target_env.parent / 'restore-compose.json', port=args.target_port)
        contexts.append(target)
        receipts['restore'] = json.loads(cli(restore_arguments))
        cli(restore_arguments, expected=False)
        receipts['checks'].append('Existing restored target refused without an overwrite flag')
        raw_after = operations.query(target, b'SELECT mid,HEX(map) FROM maps ORDER BY mid;\nSELECT mid,COALESCE(token,"revoked") FROM shares ORDER BY mid;\nSELECT id,HEX(reference),HEX(first_author_surname) FROM citations ORDER BY id;\n')
        assert raw_before == raw_after, 'Restored settings, shares, or citation bytes differ'
        restored = Client(f'http://127.0.0.1:{args.target_port}')
        guest = Client(restored.base)
        assert guest.request(f'/usermap/{maps[0]}.json')[0] in (403, 404), 'Restored map became public'
        stale = Client(restored.base)
        stale.opener.addheaders = [('Cookie', '; '.join(name + '=' + value for name, value in session_cookie))]
        assert stale.request(f'/usermap/{maps[0]}.json')[0] in (403, 404), 'Old source session revived after restore'
        started = time.monotonic()
        restored.login(credentials)
        assert restored.request(f'/usermap/{maps[0]}/settings.json') == (200, settings), 'Settings HTTP export changed'
        receipts['reopenSeconds'] = round(time.monotonic() - started, 3)
        for extension, expected in exports.items():
            status, body = restored.request(f'/map/{maps[0]}.{extension}')
            assert status == 200, 'Restored export failed'
            if extension == 'svg':
                expected = re.sub(rb'(?<=surface)[0-9]+', b'N', expected)
                body = re.sub(rb'(?<=surface)[0-9]+', b'N', body)
            assert body == expected, f'Restored {extension} export bytes changed'
        assert restored.request('/application.json', dict(form, output='tif')) == (200, tiff_before), 'Restored TIFF bytes changed'
        assert guest.request(shares[0])[0] == 200, 'Active share was lost'
        assert guest.request(shares[0] + '.png')[1].startswith(b'\x89PNG'), 'Active shared render failed'
        assert guest.request(shares[1])[0] == 404 and guest.request(shares[1] + '.png')[0] == 404, 'Revoked share revived'
        assert b'Caf' in guest.request('/citation.rss')[1], 'Restored citation feed missing'
        receipts['checks'].append('Exact SQL document/share/citation bytes, settings HTTP export and PNG/SVG/TIFF/geometry exports preserved')
        receipts['checks'].append('Active shares work, revoked shares fail, guest isolation and fresh-session policy hold')
        check_expiry(target, guest)
        receipts['checks'].append('Actual generated-image cleanup removes only expired eligible files and preserves fresh/unrelated files and saved documents')
        old_env = env_file.read_bytes()
        old_values = operations.parse_env(old_env)
        rotated_credentials = {'username': 'rotated-fixture', 'password': secrets.token_urlsafe(24)}
        rotation_cli(env_file, rotated_credentials, args.image)
        new_values = operations.parse_env(operations.protected_file(env_file, 65536))
        for key in old_values.keys() - {'SIMPLEMAPPR_OWNER_USERNAME', 'SIMPLEMAPPR_OWNER_PASSWORD_HASH'}:
            assert old_values[key] == new_values[key], 'Rotation changed unrelated configuration'
        operations.run_compose(source, ['up', '-d', '--no-build', '--wait', '--force-recreate', 'database-init', 'app'])
        assert source_client.request(f'/usermap/{maps[0]}.json')[0] in (403, 404), 'Old authenticated session survived rotation'
        Client(source_client.base).login(credentials, succeeds=False)
        Client(source_client.base).login(rotated_credentials)
        operations.write_new(private / 'rotated-credentials.json', json.dumps(rotated_credentials).encode())
        receipts['checks'].append('Interactive owner rotation preserves database credentials and rejects old password and session')
        assert receipts['restore']['seconds'] <= 120 and receipts['reopenSeconds'] <= 30, 'Restore or reopen exceeded release budget'
        receipts['status'] = 'PASS'
    finally:
        if not args.keep:
            for context in reversed(contexts):
                operations.run_compose(context, ['down', '--volumes'])
        receipts['keptFixtures'] = args.keep
        operations.write_new(private / 'receipt.json', (json.dumps(receipts, indent=2) + '\n').encode())
    print(json.dumps(receipts, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (AssertionError, operations.OperationError, OSError, ValueError, KeyError):
        error = sys.exc_info()[1]
        print(str(error) if isinstance(error, (AssertionError, operations.OperationError)) else 'Operations rehearsal failed. Inspect protected fixture logs.', file=sys.stderr)
        sys.exit(1)
