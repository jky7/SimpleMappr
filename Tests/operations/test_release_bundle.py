#!/usr/bin/env python3
import argparse
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('release_bundle', str(ROOT / 'scripts/release_bundle.py'))
spec = importlib.util.spec_from_loader(loader.name, loader)
bundle = importlib.util.module_from_spec(spec)
loader.exec_module(bundle)
import sys
sys.modules['release_bundle'] = bundle
loader = importlib.machinery.SourceFileLoader('rotate', str(ROOT / 'scripts/rotate-local-owner'))
spec = importlib.util.spec_from_loader(loader.name, loader)
rotate = importlib.util.module_from_spec(spec)
loader.exec_module(rotate)
ENV = ("# fixture\r\nSIMPLEMAPPR_OWNER_USERNAME='before'\r\nSIMPLEMAPPR_OWNER_PASSWORD_HASH='$2y$10$" + 'a' * 53 + "'\r\n"
       "SIMPLEMAPPR_DB_PASSWORD='database-dollar$literal'\r\nSIMPLEMAPPR_DB_ROOT_PASSWORD='root-secret'\r\nSIMPLEMAPPR_PORT=18081").encode()
SOCIAL = {'SIMPLEMAPPR_URL': 'https://maps.example.org',
          'SIMPLEMAPPR_GOOGLE_CLIENT_ID': 'google-client', 'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET': 'google-secret',
          'SIMPLEMAPPR_GITHUB_CLIENT_ID': 'github-client', 'SIMPLEMAPPR_GITHUB_CLIENT_SECRET': 'github-secret'}


class BundleBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = Path(self.temporary.name)
        self.path = self.directory / 'bundle'
        self.path.mkdir(mode=0o700)
        for name, data in {'database.sql': b'SELECT 1;\n', 'deployment.env': ENV}.items():
            bundle.write_new(self.path / name, data)
        self.manifest = {'format': bundle.FORMAT, 'schema': bundle.SCHEMA, 'sourceProject': 'source',
                         'appImageId': 'sha256:' + 'a' * 64, 'checkoutSha': 'b' * 40, 'checkoutDirty': True,
                         'imageRevision': None, 'databaseImage': bundle.DATABASE_IMAGE,
                         'assetProfile': 'minimal-vector-v1',
                         'members': {name: bundle.fingerprint(self.path / name) for name in ('database.sql', 'deployment.env')}}
        self.save_manifest()

    def tearDown(self):
        self.temporary.cleanup()

    def save_manifest(self):
        path = self.path / 'manifest.json'
        path.unlink(missing_ok=True)
        bundle.write_new(path, json.dumps(self.manifest).encode())

    def test_valid_bundle_is_read_without_docker(self):
        with patch.object(bundle, 'command', side_effect=AssertionError('Docker was contacted')):
            self.assertEqual(bundle.load_bundle(self.path)[1], self.manifest)

    def test_corruption_is_rejected_before_docker(self):
        (self.path / 'database.sql').write_bytes(b'corrupt')
        with patch.object(bundle, 'command', side_effect=AssertionError('Docker was contacted')):
            with self.assertRaisesRegex(bundle.OperationError, 'checksum'):
                bundle.load_bundle(self.path)

    def test_manifest_cannot_select_member_paths(self):
        self.manifest['members']['../../outside.sql'] = self.manifest['members'].pop('database.sql')
        self.save_manifest()
        with self.assertRaisesRegex(bundle.OperationError, 'members'):
            bundle.load_bundle(self.path)

    def test_missing_and_undeclared_files_are_rejected(self):
        (self.path / 'database.sql').rename(self.path / 'unknown')
        with self.assertRaisesRegex(bundle.OperationError, 'undeclared or missing'):
            bundle.load_bundle(self.path)

    def test_symlink_member_is_rejected(self):
        (self.path / 'database.sql').rename(self.directory / 'sql')
        (self.path / 'database.sql').symlink_to(self.directory / 'sql')
        with self.assertRaisesRegex(bundle.OperationError, 'Symlink'):
            bundle.load_bundle(self.path)

    def test_symlink_parent_is_rejected(self):
        link = self.directory / 'linked'
        link.symlink_to(self.path, target_is_directory=True)
        with self.assertRaisesRegex(bundle.OperationError, 'Symlink'):
            bundle.load_bundle(link)

    def test_public_file_mode_is_rejected(self):
        os.chmod(self.path / 'database.sql', 0o644)
        with self.assertRaisesRegex(bundle.OperationError, '0600'):
            bundle.load_bundle(self.path)

    def test_public_directory_mode_is_rejected(self):
        os.chmod(self.path, 0o755)
        with self.assertRaisesRegex(bundle.OperationError, '0700'):
            bundle.load_bundle(self.path)

    def test_unknown_schema_and_image_tag_are_rejected(self):
        for key, value in [('schema', '1'), ('appImageId', 'mutable:tag'), ('checkoutDirty', 'false'), ('databaseImage', 'mariadb:latest')]:
            original = self.manifest[key]
            self.manifest[key] = value
            self.save_manifest()
            with self.assertRaises(bundle.OperationError):
                bundle.load_bundle(self.path)
            self.manifest[key] = original

    def test_duplicate_manifest_keys_are_rejected(self):
        path = self.path / 'manifest.json'
        path.write_bytes(b'{"format":"one","format":"two"}')
        with self.assertRaisesRegex(bundle.OperationError, 'duplicate'):
            bundle.load_bundle(self.path)

    def test_same_project_refuses_without_docker(self):
        args = argparse.Namespace(bundle=self.path, project='source', port=18082, deployment_dir=None)
        with patch.object(bundle, 'command', side_effect=AssertionError('Docker was contacted')):
            with self.assertRaisesRegex(bundle.OperationError, 'different Compose'):
                bundle.restore(args)

    def test_unknown_environment_key_and_duplicate_credential_refuse(self):
        for data in (ENV + b'\nARBITRARY_BIND=/etc\n', ENV + b'\nSIMPLEMAPPR_DB_PASSWORD=other\n'):
            with self.assertRaises(bundle.OperationError):
                bundle.parse_env(data)

    def test_interpolated_environment_refuses(self):
        with self.assertRaises(bundle.OperationError):
            bundle.parse_env(ENV + b'\nSIMPLEMAPPR_IMAGE=${INJECT}\n')

    def test_testing_environment_and_relative_base_url_are_preserved(self):
        values = bundle.parse_env(ENV + b"\nSIMPLEMAPPR_ENV=testing\nSIMPLEMAPPR_URL='/maps/private'\n")
        self.assertEqual(values['SIMPLEMAPPR_ENV'], 'testing')
        self.assertEqual(values['SIMPLEMAPPR_URL'], '/maps/private')
        self.assertNotIn('SIMPLEMAPPR_ASSET_CACHE', values)

    def test_invalid_url_paths_and_credentials_refuse(self):
        for url in ('//evil', '/bad path', '/bad?query', 'http://user@example.org', 'http://example.org?', 'http://example.org/#'):
            with self.subTest(url=url), self.assertRaises(bundle.OperationError):
                bundle.parse_env(ENV + ("\nSIMPLEMAPPR_URL='" + url + "'\n").encode())

    def test_social_credentials_are_optional_complete_literal_pairs(self):
        self.assertFalse(set(bundle.SOCIAL_ENV_KEYS) & bundle.parse_env(ENV).keys())
        self.assertEqual(bundle.parse_env(bundle.replace_values(ENV, SOCIAL)),
                         bundle.parse_env(ENV) | SOCIAL)
        disabled = dict.fromkeys(bundle.SOCIAL_ENV_KEYS, '')
        self.assertEqual(bundle.parse_env(bundle.replace_values(ENV, disabled)), bundle.parse_env(ENV) | disabled)
        for key in bundle.SOCIAL_ENV_KEYS:
            incomplete = SOCIAL | {key: ''}
            with self.subTest(key=key), self.assertRaisesRegex(bundle.OperationError, 'complete client ID'):
                bundle.parse_env(bundle.replace_values(ENV, incomplete))

    def test_social_credentials_bound_bytes_and_reject_spaces_without_disclosing_values(self):
        for suffix, limit in (('_CLIENT_ID', 512), ('_CLIENT_SECRET', 4096)):
            key = 'SIMPLEMAPPR_GOOGLE' + suffix
            valid = SOCIAL | {key: 'é' * (limit // 2)}
            self.assertEqual(bundle.parse_env(bundle.replace_values(ENV, valid))[key], valid[key])
            for value in ('é' * (limit // 2) + 'a', 'credential with-space'):
                with self.subTest(key=key, length=len(value)), self.assertRaises(bundle.OperationError) as error:
                    bundle.parse_env(bundle.replace_values(ENV, SOCIAL | {key: value}))
                self.assertNotIn(value, str(error.exception))

    def test_social_sign_in_accepts_safe_prefix_and_requires_https_or_literal_loopback(self):
        for url in ('https://maps.example.org', 'https://maps.example.org/',
                    'https://jky.io/simplemappr', 'https://jky.io/simplemappr/',
                    'http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080'):
            with self.subTest(url=url):
                self.assertEqual(bundle.parse_env(bundle.replace_values(ENV, SOCIAL | {'SIMPLEMAPPR_URL': url}))['SIMPLEMAPPR_URL'], url)
        for url in ('', '/maps', 'https://maps.example.org/a/../maps', 'https://maps.example.org/%2f/maps',
                    'https://maps.example.org//maps', 'http://maps.example.org',
                    'http://localhost.example.org', 'http://127.0.0.2:8080', 'http://localhost:0', 'http://localhost:65536',
                    'HTTP://localhost:8080'):
            with self.subTest(url=url), self.assertRaises(bundle.OperationError):
                bundle.parse_env(bundle.replace_values(ENV, SOCIAL | {'SIMPLEMAPPR_URL': url}))

    def inspect_source(self, supplied, running):
        app_image = 'sha256:' + 'a' * 64
        database_image = 'sha256:' + 'b' * 64
        app = {'State': {'Running': True}, 'Config': {'Env': [key + '=' + value for key, value in running.items()]},
               'Image': app_image}
        database = {'Config': {'Env': ['MARIADB_ROOT_PASSWORD=' + supplied['SIMPLEMAPPR_DB_ROOT_PASSWORD']]},
                    'Image': database_image}
        def inspect(*arguments):
            return [{('inspect', 'a' * 64): app,
                     ('image', 'inspect', app_image): {'Config': {'Labels': {}}},
                     ('inspect', 'b' * 64): database,
                     ('image', 'inspect', bundle.DATABASE_IMAGE): {'Id': database_image}}[arguments]]
        with patch.object(bundle, 'run_compose', side_effect=[b'a' * 64, b'b' * 64]), patch.object(bundle, 'docker_json', side_effect=inspect):
            return bundle.app_identity(None, supplied)

    def test_backup_requires_running_social_credentials_and_canonical_url(self):
        supplied = bundle.parse_env(bundle.replace_values(ENV, SOCIAL))
        self.assertEqual(self.inspect_source(supplied, supplied)[0], 'sha256:' + 'a' * 64)
        for key in (*bundle.SOCIAL_ENV_KEYS, 'SIMPLEMAPPR_URL'):
            for value in ('different-private-value', ''):
                with self.subTest(key=key, missing=not value), self.assertRaisesRegex(bundle.OperationError, 'do not match') as error:
                    self.inspect_source(supplied, supplied | {key: value})
                self.assertNotIn('different-private-value', str(error.exception))
        with self.assertRaisesRegex(bundle.OperationError, 'do not match'):
            self.inspect_source(bundle.parse_env(ENV), supplied)

    def test_backup_accepts_old_deployments_with_missing_or_empty_optional_keys(self):
        supplied = bundle.parse_env(ENV)
        self.inspect_source(supplied, supplied)
        self.inspect_source(supplied, supplied | dict.fromkeys((*bundle.SOCIAL_ENV_KEYS, 'SIMPLEMAPPR_URL'), ''))

    def restore_fixture(self, settings):
        original = bundle.replace_values(ENV, settings | {'SIMPLEMAPPR_IMAGE': self.manifest['appImageId']})
        (self.path / 'deployment.env').write_bytes(original)
        self.manifest['members']['deployment.env'] = bundle.fingerprint(self.path / 'deployment.env')
        self.save_manifest()
        destination = self.directory / 'restored'
        args = argparse.Namespace(bundle=self.path, project='restored', port=18082, deployment_dir=destination)
        config = {'services': {name: {'image': self.manifest['appImageId']} for name in ('app', 'assets-init', 'database-init')}}
        config['services']['db'] = {'image': bundle.DATABASE_IMAGE}
        def run(context, arguments, **kwargs):
            if arguments == ['config', '--format', 'json']:
                return json.dumps(config).encode()
            if 'output' in kwargs:
                kwargs['output'].write((self.path / 'database.sql').read_bytes())
            return b''
        image = {'Id': self.manifest['appImageId'], 'Config': {'Labels': {}}}
        with patch.object(bundle, 'docker_json', return_value=[image]), patch.object(bundle, 'refuse_existing_project'), \
             patch.object(bundle, 'run_compose', side_effect=run), patch.object(bundle, 'query', side_effect=[b'', bundle.SCHEMA.encode()]):
            result = bundle.restore(args)
        self.assertEqual((self.path / 'deployment.env').read_bytes(), original)
        self.assertEqual(bundle.load_bundle(self.path)[1], self.manifest)
        return result, bundle.parse_env((destination / 'deployment.env').read_bytes())

    def test_restore_changed_origin_disables_pairs_and_preserves_protected_source_secrets(self):
        result, active = self.restore_fixture(SOCIAL | {'SIMPLEMAPPR_URL': 'https://jky.io/simplemappr'})
        self.assertTrue(result['socialLoginDisabled'])
        self.assertIn('local owner login', result['note'])
        self.assertEqual(active['SIMPLEMAPPR_URL'], 'http://127.0.0.1:18082')
        for key in bundle.SOCIAL_ENV_KEYS:
            self.assertEqual(active[key], '')
            self.assertIn(SOCIAL[key].encode(), (self.path / 'deployment.env').read_bytes())
            self.assertNotIn(SOCIAL[key], json.dumps(result))

    def test_restore_same_origin_keeps_credentials(self):
        settings = SOCIAL | {'SIMPLEMAPPR_URL': 'http://127.0.0.1:18082/'}
        result, active = self.restore_fixture(settings)
        self.assertNotIn('socialLoginDisabled', result)
        for key in bundle.SOCIAL_ENV_KEYS:
            self.assertEqual(active[key], SOCIAL[key])

    def test_restore_old_deployment_does_not_add_provider_credentials(self):
        result, active = self.restore_fixture({})
        self.assertNotIn('socialLoginDisabled', result)
        self.assertFalse(set(bundle.SOCIAL_ENV_KEYS) & active.keys())

    def test_declared_override_reads_actual_running_bind_source(self):
        actual = self.directory / 'restored-conf.php'
        actual.write_bytes(b'<?php // actual restored override')
        app = {'Mounts': [{'Type': 'bind', 'RW': False, 'Destination': '/srv/simplemappr/config/conf.php', 'Source': str(actual)}]}
        self.assertEqual(bundle.runtime_override(app, 'config/conf.php'), actual.read_bytes())
        app['Mounts'][0]['RW'] = True
        with self.assertRaisesRegex(bundle.OperationError, 'read-only'):
            bundle.runtime_override(app, 'config/conf.php')

    def test_declared_override_refuses_checkout_only_file(self):
        with self.assertRaisesRegex(bundle.OperationError, 'read-only'):
            bundle.runtime_override({'Mounts': []}, 'config/conf.php')

    def test_restore_client_disables_local_metacommands(self):
        self.assertIn('--binary-mode', bundle.QUERY)

    def test_rotation_preserves_all_unrelated_bytes_and_line_endings(self):
        result = rotate.replace_owner(ENV, 'after', '$2y$10$' + 'b' * 53)
        self.assertEqual(result, ENV.replace(b"USERNAME='before'", b"USERNAME='after'").replace(('a' * 53).encode(), ('b' * 53).encode()))

    def test_rotation_duplicate_owner_refuses(self):
        with self.assertRaises(bundle.OperationError):
            rotate.replace_owner(ENV + b'\nSIMPLEMAPPR_OWNER_USERNAME=duplicate', 'after', 'hash')

    def test_rotation_detects_edit_during_hash_and_preserves_new_file(self):
        config = self.directory / 'owner.env'
        bundle.write_new(config, ENV)
        changed = ENV + b'\n# concurrent edit\n'
        def hash_and_edit(*args):
            config.write_bytes(changed)
            return '$2y$10$' + 'b' * 53
        with patch.object(rotate, 'hash_password', side_effect=hash_and_edit):
            with self.assertRaisesRegex(bundle.OperationError, 'changed'):
                rotate.rotate(config, 'after', 'a-long-password', 'image')
        self.assertEqual(config.read_bytes(), changed)
        self.assertEqual(list(self.directory.glob('.owner-*')), [])

    def test_rotation_keeps_database_passwords_and_0600_mode(self):
        config = self.directory / 'owner.env'
        bundle.write_new(config, ENV)
        with patch.object(rotate, 'hash_password', return_value='$2y$10$' + 'b' * 53):
            rotate.rotate(config, 'after', 'a-long-password', 'image')
        self.assertEqual(config.stat().st_mode & 0o777, 0o600)
        self.assertIn(b"SIMPLEMAPPR_DB_PASSWORD='database-dollar$literal'\r\n", config.read_bytes())
        self.assertTrue(config.read_bytes().endswith(b'SIMPLEMAPPR_PORT=18081'))

    def test_minimal_named_cache_does_not_create_a_host_bind(self):
        mounts=[{'Destination':'/var/cache/simplemappr-assets','Type':'volume','RW':False}]
        with patch.object(bundle,'run_compose',return_value=b'a'*64), patch.object(bundle,'docker_json',return_value=[{'Mounts':mounts}]):
            self.assertIsNone(bundle.runtime_asset_cache(None,'minimal-vector-v1',{}))
            with self.assertRaises(bundle.OperationError):
                bundle.runtime_asset_cache(None,'gray-relief-v1',{})

    def test_optional_cache_comes_from_the_actual_readonly_bind(self):
        mounts=[{'Destination':'/var/cache/simplemappr-assets','Type':'bind','RW':False,'Source':str(self.directory)}]
        with patch.object(bundle,'run_compose',return_value=b'a'*64), patch.object(bundle,'docker_json',return_value=[{'Mounts':mounts}]):
            self.assertEqual(bundle.runtime_asset_cache(None,'gray-relief-v1',{}),str(self.directory))
            with self.assertRaises(bundle.OperationError):
                bundle.runtime_asset_cache(None,'gray-relief-v1',{'SIMPLEMAPPR_ASSET_CACHE':str(self.directory/'different')})
            mounts[0]['RW']=True
            with self.assertRaises(bundle.OperationError):
                bundle.runtime_asset_cache(None,'gray-relief-v1',{})

    def test_restore_forbids_existing_project_resources(self):
        with patch.object(bundle, 'command', return_value=b'existing-container'):
            with self.assertRaisesRegex(bundle.OperationError, 'new Compose project'):
                bundle.refuse_existing_project('new-target')


if __name__ == '__main__':
    unittest.main()
