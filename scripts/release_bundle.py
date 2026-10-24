#!/usr/bin/env python3
"""Protected deployment bundles and the shared operations boundary."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent
SCHEMA = '20261020000000'
FORMAT = 'simplemappr-deployment-v1'
DATABASE_IMAGE = 'mariadb:11.8.9@sha256:f16d7a344413e519dff518f27bdb221388f84b0d274c97c4fb5f2cb0dc1b34fa'
PROFILES = ('minimal-vector-v1', 'extended-vector-v1', 'gray-relief-v1', 'full-map-v1')
OVERRIDES = {'config/conf.php': 'config-conf.php', 'config/shapefiles.yml': 'config-shapefiles.yml'}
SOCIAL_PROVIDERS = ('GOOGLE', 'GITHUB')
SOCIAL_ENV_KEYS = tuple('SIMPLEMAPPR_' + provider + suffix
                        for provider in SOCIAL_PROVIDERS for suffix in ('_CLIENT_ID', '_CLIENT_SECRET'))
ENV_KEYS = {'SIMPLEMAPPR_OWNER_USERNAME', 'SIMPLEMAPPR_OWNER_PASSWORD_HASH', 'SIMPLEMAPPR_DB_PASSWORD',
            'SIMPLEMAPPR_DB_ROOT_PASSWORD', 'SIMPLEMAPPR_PORT', 'SIMPLEMAPPR_ASSET_PROFILE',
            'SIMPLEMAPPR_IMAGE', 'SIMPLEMAPPR_ENV', 'SIMPLEMAPPR_URL', 'SIMPLEMAPPR_ASSET_CACHE', *SOCIAL_ENV_KEYS}
REQUIRED_ENV = {'SIMPLEMAPPR_OWNER_USERNAME', 'SIMPLEMAPPR_OWNER_PASSWORD_HASH',
                'SIMPLEMAPPR_DB_PASSWORD', 'SIMPLEMAPPR_DB_ROOT_PASSWORD'}
DUMP = ('export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump --user=root '
        '--single-transaction --quick --hex-blob --order-by-primary --skip-comments '
        '--skip-add-locks --skip-lock-tables "$MARIADB_DATABASE"')
QUERY = ('export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb --user=root '
         '--binary-mode --default-character-set=utf8mb4 --batch --raw --skip-column-names "$MARIADB_DATABASE"')


class OperationError(Exception):
    pass


def fail(message):
    raise OperationError(message)


def safe_path(path):
    path = Path(os.path.abspath(path))
    for parent in (path, *path.parents):
        if parent.is_symlink():
            fail('Symlink paths are not supported.')
    return path


def protected_file(path, limit=None):
    path = safe_path(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.getuid():
            fail('Sensitive files must be owned regular files with mode 0600.')
        if limit is not None and info.st_size > limit:
            fail('Configuration or manifest exceeds its size limit.')
        return handle.read() if limit is not None else None


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def write_new(path, data):
    path = safe_path(path)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    sync_directory(path.parent)


def new_directory(path):
    path = safe_path(path)
    path.mkdir(mode=0o700)
    sync_directory(path.parent)
    return path


def fingerprint(path):
    digest = hashlib.sha256()
    size = 0
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as handle:
        while data := handle.read(1024 * 1024):
            digest.update(data)
            size += len(data)
    return {'bytes': size, 'sha256': digest.hexdigest()}


def parse_env(data):
    try:
        source = data.decode('utf-8')
    except UnicodeDecodeError:
        fail('Deployment configuration must be UTF-8.')
    values = {}
    for line in source.splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        match = re.fullmatch(r'([A-Z][A-Z0-9_]*)=(.*)', line)
        if not match or match[1] not in ENV_KEYS or match[1] in values:
            fail('Deployment configuration has an unsupported, duplicate, or malformed key.')
        key, value = match.groups()
        if value.startswith("'") and value.endswith("'") and len(value) >= 2:
            value = value[1:-1]
            if "'" in value or '\\' in value:
                fail('Deployment configuration contains unsupported quoting.')
        elif not re.fullmatch(r'[A-Za-z0-9_./:@,+%=-]*', value):
            fail('Deployment values must use literal single quotes or unquoted safe characters.')
        if any(ord(char) < 32 or ord(char) == 127 for char in value):
            fail('Deployment configuration contains a control character.')
        values[key] = value
    if not REQUIRED_ENV <= values.keys() or any(not values[key] for key in REQUIRED_ENV):
        fail('Deployment configuration is missing required credentials.')
    if not valid_username(values['SIMPLEMAPPR_OWNER_USERNAME']):
        fail('Deployment owner username is invalid.')
    if not re.fullmatch(r'\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}', values['SIMPLEMAPPR_OWNER_PASSWORD_HASH']):
        fail('Deployment owner hash must be bcrypt.')
    if values.get('SIMPLEMAPPR_ASSET_PROFILE', PROFILES[0]) not in PROFILES:
        fail('Deployment asset profile is unsupported.')
    if values.get('SIMPLEMAPPR_ENV', 'development') not in ('development', 'production', 'testing'):
        fail('Deployment environment is unsupported.')
    if 'SIMPLEMAPPR_PORT' in values:
        validate_port(values['SIMPLEMAPPR_PORT'])
    if 'SIMPLEMAPPR_URL' in values and values['SIMPLEMAPPR_URL']:
        url = values['SIMPLEMAPPR_URL']
        if url.startswith('/'):
            if not re.fullmatch(r'(?:/[A-Za-z0-9._~-]+)*', url):
                fail('Deployment URL path is invalid.')
        else:
            from urllib.parse import urlsplit
            parsed = urlsplit(url)
            if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username is not None or parsed.password is not None or '?' in url or '#' in url or any(char.isspace() or char == '\\' for char in url):
                fail('Deployment URL must be HTTP or HTTPS without credentials, whitespace, query, or fragment.')
    social_enabled = False
    for provider in SOCIAL_PROVIDERS:
        client = values.get('SIMPLEMAPPR_' + provider + '_CLIENT_ID', '')
        secret = values.get('SIMPLEMAPPR_' + provider + '_CLIENT_SECRET', '')
        if not client and not secret:
            continue
        if not client or not secret or len(client.encode('utf-8')) > 512 or len(secret.encode('utf-8')) > 4096 or any(ord(char) <= 32 or ord(char) == 127 for char in client + secret):
            fail('Social sign-in requires a complete client ID and secret pair within the supported limits.')
        social_enabled = True
    if social_enabled:
        from urllib.parse import urlsplit
        url = values.get('SIMPLEMAPPR_URL', '')
        try:
            parsed = urlsplit(url)
            valid_port = parsed.port is None or 1 <= parsed.port <= 65535
        except ValueError:
            fail('Social sign-in requires a valid absolute base URL.')
        if (not url.startswith(('http://', 'https://')) or not parsed.hostname
                or not re.fullmatch(r'(?:/[A-Za-z0-9._~-]+)*/?', parsed.path)
                or set(parsed.path.split('/')) & {'.', '..'} or not valid_port):
            fail('Social sign-in requires an absolute base URL with a safe path.')
        if parsed.scheme != 'https' and parsed.hostname.lower() not in ('localhost', '127.0.0.1', '::1'):
            fail('Social sign-in requires HTTPS outside localhost.')
    if 'SIMPLEMAPPR_ASSET_CACHE' in values:
        cache = values['SIMPLEMAPPR_ASSET_CACHE']
        if not cache or any(char in cache for char in "'\\\n\r"):
            fail('Deployment asset cache path is invalid.')
        if not Path(cache).is_absolute():
            fail('An explicitly configured asset cache must be an absolute host directory.')
        values['SIMPLEMAPPR_ASSET_CACHE'] = str(safe_path(cache))
    if 'SIMPLEMAPPR_IMAGE' in values and not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/@:+-]*', values['SIMPLEMAPPR_IMAGE']):
        fail('Deployment image reference is invalid.')
    return values


def replace_values(original, replacements):
    lines = original.decode('utf-8').splitlines(keepends=True)
    remaining = dict(replacements)
    for index, line in enumerate(lines):
        match = re.match(r'^([A-Z][A-Z0-9_]*)=', line)
        if match and match[1] in replacements:
            ending = '\r\n' if line.endswith('\r\n') else ('\n' if line.endswith('\n') else '')
            lines[index] = f"{match[1]}='{replacements[match[1]]}'{ending}"
            remaining.pop(match[1], None)
    result = ''.join(lines)
    if remaining:
        if result and not result.endswith('\n'):
            result += '\n'
        result += ''.join(f"{key}='{value}'\n" for key, value in remaining.items())
    return result.encode('utf-8')


def valid_username(username):
    return 1 <= len(username) <= 50 and not any(char in username for char in "\n\r'\\") and all(32 <= ord(char) < 127 or ord(char) > 127 for char in username)


def validate_project(project):
    if not isinstance(project, str) or not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', project):
        fail('Use a Compose project name of 1 to 63 lowercase letters, numbers, underscores, or hyphens.')
    return project


def validate_port(value):
    if not re.fullmatch(r'[0-9]+', str(value)) or not 1024 <= int(value) <= 65535:
        fail('Use a localhost port from 1024 to 65535.')
    return int(value)


def command(arguments, *, data=None, output=None, input_file=None, environment=None):
    result = subprocess.run(arguments, input=data, stdin=input_file if input_file is not None else (None if data is not None else subprocess.DEVNULL),
                            stdout=output if output is not None else subprocess.PIPE,
                            stderr=subprocess.PIPE, env=environment)
    if result.returncode:
        fail('Docker or deployment operation failed. Inspect the private deployment logs.')
    return result.stdout


def docker_json(*arguments):
    try:
        return json.loads(command(['docker', *arguments]))
    except (json.JSONDecodeError, TypeError):
        fail('Docker returned invalid inspection data.')


def compose(project, env_file, extra_file=None, image=None, port=None, profile=None):
    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith('SIMPLEMAPPR_') and not key.startswith('COMPOSE_')}
    if image is not None:
        environment['SIMPLEMAPPR_IMAGE'] = image
    if port is not None:
        environment['SIMPLEMAPPR_PORT'] = str(port)
    if profile is not None:
        environment['SIMPLEMAPPR_ASSET_PROFILE'] = profile
    arguments = ['docker', 'compose', '--project-directory', str(ROOT), '-p', validate_project(project),
                 '--env-file', str(env_file), '-f', str(ROOT / 'compose.yaml')]
    if extra_file:
        arguments += ['-f', str(extra_file)]
    return arguments, environment


def run_compose(context, arguments, **kwargs):
    base, environment = context
    return command(base + arguments, environment=environment, **kwargs)


def query(context, sql):
    return run_compose(context, ['exec', '-T', 'db', 'sh', '-ec', QUERY], data=sql)


def current_schema(context):
    if query(context, b'SELECT version FROM schema_migrations ORDER BY version;\n').strip() != SCHEMA.encode():
        fail('Unsupported database schema.')


def app_identity(context, values):
    container = run_compose(context, ['ps', '-q', 'app']).decode().strip()
    if not re.fullmatch(r'[0-9a-f]{12,64}', container):
        fail('Backup requires exactly one running application container.')
    info = docker_json('inspect', container)[0]
    if not info['State']['Running']:
        fail('Backup requires a running source application.')
    app_env = dict(item.split('=', 1) for item in info['Config']['Env'] if '=' in item)
    for key in ('SIMPLEMAPPR_DB_PASSWORD', 'SIMPLEMAPPR_OWNER_USERNAME', 'SIMPLEMAPPR_OWNER_PASSWORD_HASH'):
        if app_env.get(key) != values[key]:
            fail('Supplied deployment credentials do not match the running source application.')
    for key in (*SOCIAL_ENV_KEYS, 'SIMPLEMAPPR_URL'):
        if app_env.get(key, '') != values.get(key, ''):
            fail('Supplied social sign-in settings or canonical URL do not match the running source application.')
    profile = app_env.get('SIMPLEMAPPR_ASSET_PROFILE', PROFILES[0])
    if profile not in PROFILES:
        fail('Running source application has an unsupported asset profile.')
    image_id = info['Image']
    image = docker_json('image', 'inspect', image_id)[0]
    labels = image['Config'].get('Labels') or {}
    revision = labels.get('org.opencontainers.image.revision')
    if revision is not None and not re.fullmatch(r'[0-9a-f]{40}', revision):
        fail('Source image has an invalid revision label.')
    db_id = run_compose(context, ['ps', '-q', 'db']).decode().strip()
    db_info = docker_json('inspect', db_id)[0]
    expected_db = docker_json('image', 'inspect', DATABASE_IMAGE)[0]['Id']
    if db_info['Image'] != expected_db:
        fail('The source database is not the supported pinned image.')
    db_env = dict(item.split('=', 1) for item in db_info['Config']['Env'] if '=' in item)
    if db_env.get('MARIADB_ROOT_PASSWORD') != values['SIMPLEMAPPR_DB_ROOT_PASSWORD']:
        fail('Supplied database root credentials do not match the running source database.')
    return image_id, revision, profile, info


def runtime_override(app_info, name):
    destination = '/srv/simplemappr/' + name
    mounts = [mount for mount in app_info['Mounts'] if mount['Destination'] == destination]
    if len(mounts) != 1 or mounts[0]['Type'] != 'bind' or mounts[0]['RW']:
        fail('A declared override must be a read-only file bind mount in the running application.')
    source = safe_path(mounts[0]['Source'])
    descriptor = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= 1024 * 1024:
            fail('Runtime overrides must be regular nonempty files of at most 1 MiB.')
        return handle.read()


def runtime_asset_cache(context, profile, values):
    container = run_compose(context, ['ps', '-aq', 'assets-init']).decode().strip()
    if not re.fullmatch(r'[0-9a-f]{12,64}', container):
        fail('Backup requires the source assets-init container metadata.')
    info = docker_json('inspect', container)[0]
    mounts = [mount for mount in info['Mounts'] if mount['Destination'] == '/var/cache/simplemappr-assets']
    if not mounts and profile == PROFILES[0] and not values.get('SIMPLEMAPPR_ASSET_CACHE'):
        return None
    if len(mounts) != 1 or mounts[0]['RW']:
        fail('Source asset cache must be one read-only mount.')
    mount = mounts[0]
    if mount['Type'] == 'volume':
        if profile != PROFILES[0] or values.get('SIMPLEMAPPR_ASSET_CACHE'):
            fail('Supplied asset cache differs from the running source cache.')
        return None
    if mount['Type'] != 'bind':
        fail('Unsupported source asset cache mount.')
    cache = safe_path(mount['Source'])
    if not cache.is_dir():
        fail('The source asset cache directory does not exist.')
    if values.get('SIMPLEMAPPR_ASSET_CACHE') and safe_path(values['SIMPLEMAPPR_ASSET_CACHE']) != cache:
        fail('Supplied asset cache differs from the running source cache.')
    return str(cache)


def backup(args):
    env_file = safe_path(args.env_file)
    env_bytes = protected_file(env_file, 65536)
    values = parse_env(env_bytes)
    context = compose(args.project, env_file)
    image_id, revision, profile, app_info = app_identity(context, values)
    retention_tag = 'simplemappr-backup:sha256-' + image_id.removeprefix('sha256:')
    command(['docker', 'image', 'tag', image_id, retention_tag])
    effective = {'SIMPLEMAPPR_IMAGE': image_id, 'SIMPLEMAPPR_ASSET_PROFILE': profile}
    cache = runtime_asset_cache(context, profile, values)
    if cache is not None:
        effective['SIMPLEMAPPR_ASSET_CACHE'] = cache
    env_bytes = replace_values(env_bytes, effective)
    current_schema(context)
    checkout = command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip()
    checkout_dirty = bool(command(['git', '-C', str(ROOT), 'status', '--porcelain', '--untracked-files=normal']).strip())
    if not re.fullmatch(r'[0-9a-f]{40}', checkout):
        fail('Cannot establish checkout provenance.')
    overrides = {OVERRIDES[name]: runtime_override(app_info, name) for name in args.include_override}
    destination = new_directory(args.bundle)
    write_new(destination / 'deployment.env', env_bytes)
    for name, data in overrides.items():
        write_new(destination / name, data)
    descriptor = os.open(destination / 'database.sql', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as handle:
        run_compose(context, ['exec', '-T', 'db', 'sh', '-ec', DUMP], output=handle)
        handle.flush()
        os.fsync(handle.fileno())
    members = {name: fingerprint(destination / name) for name in ['database.sql', 'deployment.env', *overrides]}
    if not members['database.sql']['bytes']:
        fail('Database dump was empty. No manifest published.')
    manifest = {'format': FORMAT, 'schema': SCHEMA, 'sourceProject': args.project,
                'appImageId': image_id, 'checkoutSha': checkout, 'checkoutDirty': checkout_dirty, 'imageRevision': revision,
                'databaseImage': DATABASE_IMAGE, 'assetProfile': profile,
                'members': members}
    write_new(destination / 'manifest.json', (json.dumps(manifest, indent=2, sort_keys=True) + '\n').encode())
    return {'operation': 'backup', 'status': 'PASS', 'databaseBytes': members['database.sql']['bytes'],
            'bundleBytes': sum(item['bytes'] for item in members.values()), 'appImageId': image_id, 'retainedImage': retention_tag}


def unique_json(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            fail('Manifest contains duplicate keys.')
        result[key] = value
    return result


def load_bundle(path):
    path = safe_path(path)
    info = path.stat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o700 or info.st_uid != os.getuid():
        fail('Bundle must be an owned directory with mode 0700.')
    try:
        manifest = json.loads(protected_file(path / 'manifest.json', 65536), object_pairs_hook=unique_json)
    except (UnicodeDecodeError, json.JSONDecodeError):
        fail('Malformed bundle manifest.')
    keys = {'format', 'schema', 'sourceProject', 'appImageId', 'checkoutSha', 'checkoutDirty', 'imageRevision', 'databaseImage', 'assetProfile', 'members'}
    if not isinstance(manifest, dict) or set(manifest) != keys:
        fail('Bundle manifest has unsupported fields.')
    if manifest['format'] != FORMAT or manifest['schema'] != SCHEMA or manifest['databaseImage'] != DATABASE_IMAGE:
        fail('Unsupported bundle format, schema, or database image.')
    validate_project(manifest['sourceProject'])
    if not isinstance(manifest['appImageId'], str) or not re.fullmatch(r'sha256:[0-9a-f]{64}', manifest['appImageId']):
        fail('Bundle must record an immutable application image ID.')
    for key in ('checkoutSha', 'imageRevision'):
        value = manifest[key]
        if key == 'imageRevision' and value is None:
            continue
        if not isinstance(value, str) or not re.fullmatch(r'[0-9a-f]{40}', value):
            fail('Bundle image or checkout provenance is invalid.')
    if type(manifest['checkoutDirty']) is not bool:
        fail('Bundle checkout provenance is invalid.')
    if manifest['assetProfile'] not in PROFILES:
        fail('Unsupported bundle asset profile.')
    members = manifest['members']
    if not isinstance(members, dict) or not {'database.sql', 'deployment.env'} <= members.keys() or not members.keys() <= {'database.sql', 'deployment.env', *OVERRIDES.values()}:
        fail('Bundle has missing or unsupported members.')
    if set(item.name for item in path.iterdir()) != {'manifest.json', *members}:
        fail('Bundle has undeclared or missing files.')
    for name, expected in members.items():
        if not isinstance(expected, dict) or set(expected) != {'bytes', 'sha256'} or type(expected['bytes']) is not int or expected['bytes'] <= 0 or not isinstance(expected['sha256'], str) or not re.fullmatch(r'[0-9a-f]{64}', expected['sha256']):
            fail('Invalid bundle member metadata.')
        protected_file(path / name)
        if fingerprint(path / name) != expected:
            fail('Bundle member checksum or byte count mismatch. No database contacted.')
    values = parse_env(protected_file(path / 'deployment.env', 65536))
    if values.get('SIMPLEMAPPR_ASSET_PROFILE', PROFILES[0]) != manifest['assetProfile']:
        fail('Bundle configuration and selected profile disagree.')
    return path, manifest, values


def refuse_existing_project(project):
    for arguments in (['ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project],
                      ['volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=' + project],
                      ['network', 'ls', '-q', '--filter', 'label=com.docker.compose.project=' + project]):
        if command(['docker', *arguments]).strip():
            fail('Restore requires a new Compose project without existing containers, volumes, or networks. There is no overwrite option.')
    if command(['docker', 'volume', 'ls', '-q', '--filter', 'name=^' + project + '_']).strip():
        fail('Restore target has existing volumes. There is no overwrite option.')


def restore(args):
    path, manifest, values = load_bundle(args.bundle)
    project = validate_project(args.project)
    if project == manifest['sourceProject']:
        fail('Restore requires a different Compose project.')
    port = validate_port(args.port)
    image_id = manifest['appImageId']
    image = docker_json('image', 'inspect', image_id)[0]
    if image['Id'] != image_id:
        fail('Recorded application image identity does not match. No database contacted.')
    if values.get('SIMPLEMAPPR_IMAGE') != image_id:
        fail('Bundle configuration differs from recorded image. No database contacted.')
    if manifest['imageRevision'] != (image['Config'].get('Labels') or {}).get('org.opencontainers.image.revision'):
        fail('Recorded image provenance differs from the available image.')
    refuse_existing_project(project)
    destination = new_directory(args.deployment_dir or path.parent / (project + '-deployment'))
    env_bytes = protected_file(path / 'deployment.env', 65536)
    updates = {'SIMPLEMAPPR_IMAGE': image_id, 'SIMPLEMAPPR_PORT': str(port)}
    social_disabled = False
    if values.get('SIMPLEMAPPR_URL', '').startswith(('http://', 'https://')):
        updates['SIMPLEMAPPR_URL'] = f'http://127.0.0.1:{port}'
        if values['SIMPLEMAPPR_URL'].rstrip('/') != updates['SIMPLEMAPPR_URL']:
            for key in SOCIAL_ENV_KEYS:
                if values.get(key):
                    updates[key] = ''
                    social_disabled = True
    cache = safe_path(values['SIMPLEMAPPR_ASSET_CACHE']) if values.get('SIMPLEMAPPR_ASSET_CACHE') else None
    if cache is not None and not cache.is_dir():
        fail('Recorded asset cache directory is missing. Preserve the verified archive cache before restore.')
    if manifest['assetProfile'] != PROFILES[0] and cache is None:
        cache = safe_path(ROOT / '.asset-cache')
        if not cache.is_dir():
            fail('Optional-profile restore requires its verified archive cache. No database contacted.')
        updates['SIMPLEMAPPR_ASSET_CACHE'] = str(cache)
    updated = replace_values(env_bytes, updates)
    write_new(destination / 'deployment.env', updated)
    override = {'services': {}}
    for source, name in OVERRIDES.items():
        if name in manifest['members']:
            write_new(destination / name, (path / name).read_bytes())
            (destination / name).chmod(0o644)
            for service in ('app', 'database-init'):
                override['services'].setdefault(service, {}).setdefault('volumes', []).append({
                    'type': 'bind', 'source': str(destination / name), 'target': '/srv/simplemappr/' + source, 'read_only': True})
    override_file = destination / 'restore-compose.json'
    write_new(override_file, (json.dumps(override, indent=2) + '\n').encode())
    context = compose(project, destination / 'deployment.env', override_file, image_id, port, manifest['assetProfile'])
    config = json.loads(run_compose(context, ['config', '--format', 'json']))
    if config['services']['db']['image'] != DATABASE_IMAGE or any(config['services'][name]['image'] != image_id for name in ('app', 'assets-init', 'database-init')):
        fail('Compose does not preserve recorded image identities. No database contacted.')
    run_compose(context, ['up', '-d', '--no-build', '--wait', 'db'])
    if query(context, b'SHOW TABLES;\n').strip():
        fail('Restore requires an empty database. No SQL imported.')
    descriptor = os.open(path / 'database.sql', os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as handle:
        run_compose(context, ['exec', '-T', 'db', 'sh', '-ec', QUERY], input_file=handle)
    current_schema(context)
    import tempfile
    with tempfile.TemporaryFile() as handle:
        run_compose(context, ['exec', '-T', 'db', 'sh', '-ec', DUMP], output=handle)
        handle.seek(0)
        digest = hashlib.file_digest(handle, 'sha256').hexdigest()
    if digest != manifest['members']['database.sql']['sha256']:
        fail('Restored SQL content differs from the source dump. Application was not started.')
    run_compose(context, ['up', '-d', '--no-build', '--wait'])
    result = {'operation': 'restore', 'status': 'PASS', 'project': project, 'port': port,
              'deploymentDirectory': str(destination), 'appImageId': image_id, 'newSessions': True}
    if social_disabled:
        result['socialLoginDisabled'] = True
        result['note'] = 'Social sign-in was disabled for the changed origin. Use local owner login and follow docs/social-login.md to register callbacks and re-enable providers.'
    return result


def hash_password(password, image='simplemappr-runtime:local'):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/@:+-]*', image):
        fail('Invalid application image reference.')
    hashed = command(['docker', 'run', '--rm', '-i', '--network', 'none', '--entrypoint', 'php', image, '-r',
                      'echo password_hash(stream_get_contents(STDIN),PASSWORD_BCRYPT);'], data=password.encode())
    if not re.fullmatch(rb'\$2y\$\d\d\$[./A-Za-z0-9]{53}', hashed):
        fail('Cannot create the owner password hash. Build the runtime first.')
    return hashed.decode()


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest='operation', required=True)
    for operation in ('backup', 'restore'):
        subparser = subparsers.add_parser(operation)
        subparser.add_argument('bundle', type=Path)
        subparser.add_argument('--project', required=True, type=validate_project)
        if operation == 'backup':
            subparser.add_argument('--env-file', type=Path, required=True)
            subparser.add_argument('--include-override', action='append', choices=OVERRIDES, default=[])
        else:
            subparser.add_argument('--port', required=True, type=validate_port)
            subparser.add_argument('--deployment-dir', type=Path)
    args = parser.parse_args()
    started = time.monotonic()
    result = backup(args) if args.operation == 'backup' else restore(args)
    result['seconds'] = round(time.monotonic() - started, 3)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (OperationError, OSError, ValueError, KeyError, TypeError):
        error = sys.exc_info()[1]
        print(str(error) if isinstance(error, OperationError) else 'Operation failed. Check paths, permissions, Docker access, and the private deployment logs.', file=sys.stderr)
        sys.exit(1)
