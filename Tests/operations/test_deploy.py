#!/usr/bin/env python3
"""Exercise scripts/deploy against a throwaway git origin and a fake docker that only logs its calls."""
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

FAKE_DOCKER = r'''#!/bin/sh
echo "$*" >> "$FAKE_LOG"
case "$1 $2" in
  "compose ps") echo fakeapp ;;
  "compose exec")
    case "$*" in
      *"-w"*) [ "$SIMPLEMAPPR_IMAGE" = "$FAKE_BAD_IMAGE" ] && echo 500 || echo 200 ;;
      *) [ "$SIMPLEMAPPR_IMAGE" = "$FAKE_BAD_IMAGE" ] && exit 22; echo '<h1 id="site-title">SimpleMappr</h1>' ;;
    esac ;;
  "image inspect") [ -n "$FAKE_NO_PREVIOUS" ] && exit 1; exit 0 ;;
  "compose up") [ "$SIMPLEMAPPR_IMAGE" = "$FAKE_UNHEALTHY_IMAGE" ] && exit 1; exit 0 ;;
  "compose run") echo "retired 3 compiled template directories, left 0 unverified" ;;
esac
case "$1" in
  inspect) echo true ;;
esac
exit 0
'''


def run(*args, cwd, env=None, check=True):
    return subprocess.run(args, cwd=cwd, env=env, check=check, text=True, capture_output=True)


class DeployTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.git_env = {**os.environ, 'GIT_AUTHOR_NAME': 't', 'GIT_AUTHOR_EMAIL': 't@t', 'GIT_COMMITTER_NAME': 't', 'GIT_COMMITTER_EMAIL': 't@t'}
        origin = self.tmp / 'origin.git'
        run('git', 'init', '-q', '--bare', '-b', 'master', str(origin), cwd=self.tmp)
        seed = self.tmp / 'seed'
        run('git', 'clone', '-q', str(origin), str(seed), cwd=self.tmp, env=self.git_env)
        (seed / 'scripts').mkdir()
        shutil.copy(ROOT / 'scripts/deploy', seed / 'scripts/deploy')
        (seed / 'views').mkdir()
        (seed / 'views/a.html').write_text('one')
        (seed / 'src.txt').write_text('one')
        self.commit(seed, 'first')
        run('git', 'push', '-q', 'origin', 'HEAD:master', cwd=seed, env=self.git_env)
        self.seed = seed
        self.work = self.tmp / 'work'
        run('git', 'clone', '-q', str(origin), str(self.work), cwd=self.tmp, env=self.git_env)
        self.first = self.sha(self.work)
        self.env_file = self.work / '.env'
        self.env_file.write_text("SIMPLEMAPPR_DB_PASSWORD='secret-value'\nSIMPLEMAPPR_IMAGE='simplemappr-detailed:%s'\n" % self.first)
        self.env_file.chmod(0o600)
        self.bin = self.tmp / 'bin'
        self.bin.mkdir()
        docker = self.bin / 'docker'
        docker.write_text(FAKE_DOCKER)
        docker.chmod(docker.stat().st_mode | stat.S_IXUSR)
        self.log = self.tmp / 'docker.log'
        self.log.write_text('')
        self.state = self.tmp / 'state'
        self.env = {**self.git_env, 'PATH': '%s:%s' % (self.bin, os.environ['PATH']), 'FAKE_LOG': str(self.log), 'SIMPLEMAPPR_DEPLOY_STATE': str(self.state),
                    'HOME': str(self.tmp), 'FAKE_BAD_IMAGE': '', 'FAKE_UNHEALTHY_IMAGE': '', 'FAKE_NO_PREVIOUS': ''}

    def commit(self, repo, message):
        run('git', 'add', '-A', cwd=repo, env=self.git_env)
        run('git', 'commit', '-q', '-m', message, cwd=repo, env=self.git_env)

    def sha(self, repo):
        return run('git', 'rev-parse', '--short=7', 'HEAD', cwd=repo).stdout.strip()

    def push(self, message, template=False):
        (self.seed / 'src.txt').write_text(message)
        if template:
            (self.seed / 'views/a.html').write_text(message)
        self.commit(self.seed, message)
        run('git', 'push', '-q', 'origin', 'HEAD:master', cwd=self.seed, env=self.git_env)
        return self.sha(self.seed)

    def deploy(self, *args, check=True, **overrides):
        env = {**self.env, **overrides}
        return run('scripts/deploy', *args, cwd=self.work, env=env, check=check)

    def calls(self):
        return [line for line in self.log.read_text().splitlines() if line]

    def image(self):
        return [line for line in self.env_file.read_text().splitlines() if line.startswith('SIMPLEMAPPR_IMAGE=')][0]

    def test_template_change_stops_retires_then_starts_and_keeps_the_env_private(self):
        new = self.push('two', template=True)
        result = self.deploy()
        self.assertIn('Deployed simplemappr-detailed:%s' % new, result.stdout)
        self.assertEqual(self.image(), "SIMPLEMAPPR_IMAGE='simplemappr-detailed:%s'" % new)
        self.assertEqual(stat.S_IMODE(self.env_file.stat().st_mode), 0o600)
        self.assertIn("SIMPLEMAPPR_DB_PASSWORD='secret-value'", self.env_file.read_text())
        self.assertNotIn('secret-value', result.stdout + result.stderr)
        kinds = [' '.join(call.split()[:2]) for call in self.calls() if call.startswith('compose') and call.split()[1] in ('build', 'stop', 'run', 'up')]
        self.assertEqual(kinds, ['compose build', 'compose stop', 'compose run', 'compose up'])
        backups = list(self.state.glob('env.pre-%s-*' % new))
        self.assertEqual(len(backups), 1)
        self.assertEqual(stat.S_IMODE(backups[0].stat().st_mode), 0o600)
        self.assertIn(self.first, backups[0].read_text())

    def test_change_without_templates_recreates_the_app_without_a_stop(self):
        self.push('two')
        self.deploy()
        self.assertFalse([call for call in self.calls() if call.startswith('compose stop') or call.startswith('compose run')])
        self.assertTrue([call for call in self.calls() if call.startswith('compose up')])

    def test_running_commit_is_a_no_op_unless_forced(self):
        result = self.deploy()
        self.assertIn('Already deployed', result.stdout)
        self.assertFalse([call for call in self.calls() if call.startswith('compose build')])
        self.deploy('--force')
        self.assertTrue([call for call in self.calls() if call.startswith('compose build')])
        self.assertTrue([call for call in self.calls() if call.startswith('compose stop')], '--force always retires templates')

    def test_failed_checks_roll_back_to_the_previous_image(self):
        new = self.push('two')
        result = self.deploy(check=False, FAKE_BAD_IMAGE='simplemappr-detailed:%s' % new)
        self.assertEqual(result.returncode, 1)
        self.assertIn('Rolling back', result.stdout)
        self.assertEqual(self.image(), "SIMPLEMAPPR_IMAGE='simplemappr-detailed:%s'" % self.first)
        self.assertEqual(len([call for call in self.calls() if call.startswith('compose up')]), 2)

    def test_unhealthy_start_also_rolls_back(self):
        new = self.push('two')
        result = self.deploy(check=False, FAKE_UNHEALTHY_IMAGE='simplemappr-detailed:%s' % new)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.image(), "SIMPLEMAPPR_IMAGE='simplemappr-detailed:%s'" % self.first)

    def test_failed_checks_with_no_previous_image_say_so(self):
        new = self.push('two')
        result = self.deploy(check=False, FAKE_BAD_IMAGE='simplemappr-detailed:%s' % new, FAKE_NO_PREVIOUS='1')
        self.assertEqual(result.returncode, 1)
        self.assertIn('no previous image', result.stderr)

    def test_dry_run_builds_and_verifies_but_never_touches_the_site_or_env(self):
        self.push('two', template=True)
        before = self.env_file.read_text()
        result = self.deploy('--dry-run')
        self.assertIn('Dry run complete', result.stdout)
        self.assertEqual(self.env_file.read_text(), before)
        self.assertFalse([call for call in self.calls() if call.split()[:2] in (['compose', 'stop'], ['compose', 'up'], ['compose', 'run'])])
        self.assertTrue([call for call in self.calls() if call.startswith('compose build')])

    def test_uncommitted_changes_and_unknown_options_are_refused(self):
        (self.work / 'src.txt').write_text('local edit')
        result = self.deploy(check=False)
        self.assertEqual(result.returncode, 1)
        self.assertIn('uncommitted changes', result.stderr)
        self.assertEqual(self.calls(), [])
        self.assertEqual(self.deploy('--bogus', check=False).returncode, 2)

    def test_diverged_checkout_is_not_overwritten(self):
        self.push('two')
        (self.work / 'extra.txt').write_text('local commit')
        self.commit(self.work, 'local only')
        result = self.deploy(check=False)
        self.assertEqual(result.returncode, 1)
        self.assertIn('cannot be fast-forwarded', result.stderr)

    def test_a_changed_deploy_script_restarts_itself_once(self):
        script = (self.seed / 'scripts/deploy')
        script.write_text(script.read_text() + '\n# changed upstream\n')
        self.commit(self.seed, 'update deploy')
        run('git', 'push', '-q', 'origin', 'HEAD:master', cwd=self.seed, env=self.git_env)
        result = self.deploy()
        self.assertEqual(result.stdout.count('scripts/deploy changed'), 1)
        self.assertIn('Deployed', result.stdout)


class RemoteWrapperTest(unittest.TestCase):
    def run_wrapper(self, *args, **env):
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        ssh = tmp / 'ssh'
        ssh.write_text('#!/bin/sh\nprintf "%s\\n" "$@" > "$FAKE_SSH_LOG"\n')
        ssh.chmod(0o755)
        log = tmp / 'ssh.log'
        environment = {**os.environ, 'PATH': '%s:%s' % (tmp, os.environ['PATH']), 'FAKE_SSH_LOG': str(log), **env}
        for name in ('SIMPLEMAPPR_DEPLOY_HOST', 'SIMPLEMAPPR_DEPLOY_DIR', 'SIMPLEMAPPR_DEPLOY_BRANCH'):
            if name not in env:
                environment.pop(name, None)
        subprocess.run([str(ROOT / 'scripts/deploy-remote'), *args], env=environment, check=True)
        return log.read_text().splitlines()

    def test_pulls_on_the_server_then_runs_the_freshly_pulled_script(self):
        host, command = self.run_wrapper('--force')
        self.assertEqual(host, 'jky.io')
        self.assertTrue(command.startswith('cd simplemappr && git fetch -q origin master'))
        self.assertIn('git merge -q --ff-only origin/master', command)
        self.assertTrue(command.endswith('exec scripts/deploy --no-pull --branch master --force'))

    def test_host_directory_and_branch_are_configurable_and_arguments_stay_quoted(self):
        host, command = self.run_wrapper('--dry-run', SIMPLEMAPPR_DEPLOY_HOST='other', SIMPLEMAPPR_DEPLOY_DIR='my maps', SIMPLEMAPPR_DEPLOY_BRANCH='release')
        self.assertEqual(host, 'other')
        self.assertTrue(command.startswith('cd my\\ maps && git fetch -q origin release'))
        self.assertTrue(command.endswith('--branch release --dry-run'))


if __name__ == '__main__':
    unittest.main()
