"""Safety regression tests: simulate Docker APIs; real Compose rendering uses no daemon."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('dcms_smoke', ROOT/'docker/smoke.py')
smoke = importlib.util.module_from_spec(spec)
spec.loader.exec_module(smoke)
IMAGE = 'sha256:'+'a'*64
OPT_IN = {'DCMS_DISPOSABLE_SMOKE': '1', 'DCMS_SMOKE_CLEANUP': '1'}


class DockerSmokeSafetyTest(unittest.TestCase):
    def make_run(self):
        run = smoke.SmokeRun(IMAGE, os.environ)
        self.addCleanup(shutil.rmtree, run.directory)
        return run

    def test_explicit_test_and_cleanup_consents_are_required(self):
        for environment in ({}, {'DCMS_DISPOSABLE_SMOKE': '1'}):
            with self.subTest(environment=environment), self.assertRaises(RuntimeError):
                smoke.validate_caller(environment, IMAGE, False)
        smoke.validate_caller(OPT_IN, IMAGE, False)
        smoke.validate_caller({'DCMS_DISPOSABLE_SMOKE': '1'}, IMAGE, True)

    def test_remote_context_config_and_tls_overrides_are_rejected(self):
        for key, value in [('DOCKER_HOST', 'tcp://example.invalid:2376'), ('DOCKER_HOST', 'unix:///tmp/other.sock'),
                           ('DOCKER_CONTEXT', 'remote'), ('DOCKER_CONFIG', '/tmp/caller-config'),
                           ('DOCKER_TLS_VERIFY', '1'), ('DOCKER_CERT_PATH', '/tmp/certs'), ('DOCKER_TLS', '1')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                smoke.validate_caller({**OPT_IN, key: value}, IMAGE, False)

    def test_mutable_registry_option_and_newline_images_are_refused(self):
        for image in ('dcms:latest', 'ghcr.io/example/dcms:1', '--help', IMAGE+'\nDCMS_DB_HOST=other'):
            with self.subTest(image=image), self.assertRaises(RuntimeError):
                smoke.validate_caller(OPT_IN, image, False)

    def test_security_selectors_are_controlled_and_normal_environment_is_preserved(self):
        source = {key: 'unsafe' for key in ('DOCKER_CUSTOM_HEADERS', 'DOCKER_API_VERSION', 'DOCKER_DEFAULT_PLATFORM',
                  'COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES', 'COMPOSE_REMOVE_ORPHANS',
                  'COMPOSE_FUTURE_SELECTOR', 'DCMS_APP_ENV_FILE', 'DCMS_BIND_ADDRESS', 'DCMS_HTTP_PORT', 'DB_HOST', 'MYSQL_DATABASE')}
        source.update(PATH='/usr/bin', HOME='/legitimate', LANG='C.UTF-8', HTTPS_PROXY='http://proxy.invalid')
        environment = smoke.docker_environment(source, Path('/private/config'))
        for key in source:
            if key not in ('PATH', 'HOME', 'LANG', 'HTTPS_PROXY'):
                self.assertNotIn(key, environment)
        self.assertEqual(environment['HOME'], '/legitimate')
        self.assertEqual(environment['HTTPS_PROXY'], 'http://proxy.invalid')
        self.assertEqual(environment['DOCKER_HOST'], smoke.LOCAL_HOST)
        self.assertEqual(environment['DOCKER_CONFIG'], '/private/config')

    def test_project_names_are_unique_and_cannot_be_supplied_by_the_caller(self):
        with patch.dict(os.environ, {'COMPOSE_PROJECT_NAME': 'dcms-mysql84-tests'}):
            first, second = self.make_run(), self.make_run()
        self.assertNotEqual(first.project, second.project)
        self.assertRegex(first.project, r'^dcms-smoke-[a-f0-9]{32}$')
        self.assertEqual(first.directory.stat().st_mode & 0o777, 0o700)

    def test_cli_always_has_explicit_host_config_and_sanitized_environment(self):
        run = self.make_run()
        with patch.object(smoke.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'ok', '')) as call:
            self.assertEqual(run.docker('version'), 'ok')
        command = call.call_args.args[0]
        self.assertEqual(command[1:5], ['--host', smoke.LOCAL_HOST, '--config', str(run.config_directory)])
        self.assertEqual(call.call_args.kwargs['env']['DOCKER_HOST'], smoke.LOCAL_HOST)

    def fake_inventory(self, run, foreign=False, existing=False):
        run.baseline = {kind: set() for kind in smoke.KINDS}
        if existing:
            run.baseline['container'].add('created-container')
        def docker(*args, **kwargs):
            kind, operation = args[:2]
            if operation == 'ls':
                return 'created-container' if kind == 'container' else ''
            if operation == 'inspect':
                labels = {smoke.PROJECT_LABEL: run.project, smoke.RUN_LABEL: 'foreign' if foreign else run.token,
                          'com.docker.compose.service': 'app'}
                return json.dumps([{'Id': 'created-container', 'Name': '/'+run.project+'-app-1', 'Config': {'Labels': labels}}])
            return ''
        return docker

    def test_cleanup_refuses_foreign_labels_before_any_deletion(self):
        run = self.make_run()
        with patch.object(run, 'docker', side_effect=self.fake_inventory(run, foreign=True)) as call:
            with self.assertRaisesRegex(RuntimeError, 'Foreign/unverified'):
                run.cleanup()
        self.assertFalse(any('rm' in invocation.args for invocation in call.call_args_list))

    def test_cleanup_refuses_preexisting_resources_even_with_matching_labels(self):
        run = self.make_run()
        with patch.object(run, 'docker', side_effect=self.fake_inventory(run, existing=True)) as call:
            with self.assertRaisesRegex(RuntimeError, 'Pre-existing'):
                run.cleanup()
        self.assertFalse(any('rm' in invocation.args for invocation in call.call_args_list))

    def test_cleanup_deletes_only_verified_new_ids_and_checks_for_leftovers(self):
        run = self.make_run()
        inventory = self.fake_inventory(run)
        removed = False
        def docker(*args, **kwargs):
            nonlocal removed
            if args[:2] == ('container', 'rm'):
                self.assertEqual(args, ('container', 'rm', '--force', 'created-container'))
                removed = True
                return ''
            if removed and args[1] == 'ls':
                return ''
            return inventory(*args, **kwargs)
        with patch.object(run, 'docker', side_effect=docker):
            run.cleanup()
        self.assertTrue(removed)
        self.assertEqual(run.summary['cleanup'], 'verified-empty')

    def test_foreign_network_or_volume_prevents_all_cleanup_mutations(self):
        for foreign_kind in ('network', 'volume'):
            with self.subTest(kind=foreign_kind):
                run = self.make_run()
                inventory = self.fake_inventory(run)
                def docker(*args, **kwargs):
                    if args[0] == foreign_kind:
                        if args[1] == 'ls':
                            return 'foreign-resource'
                        if args[1] == 'inspect':
                            return json.dumps([{'Labels': {smoke.PROJECT_LABEL: 'another-project'}}])
                    return inventory(*args, **kwargs)
                with patch.object(run, 'docker', side_effect=docker) as call:
                    with self.assertRaisesRegex(RuntimeError, 'Foreign/unverified'):
                        run.cleanup()
                self.assertFalse(any('rm' in invocation.args for invocation in call.call_args_list))

    def test_failed_docker_command_does_not_retry_another_endpoint(self):
        run = self.make_run()
        with patch.object(smoke.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '', 'permission denied')) as call:
            with contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'Docker step failed'):
                    run.docker('version')
        self.assertEqual(call.call_count, 1)
        self.assertIn('permission denied', (run.directory/'runtime.log').read_text())

    def test_failed_compose_reports_exact_operation_and_redacts_bounded_output(self):
        run = self.make_run()
        run.redactions = ['synthetic-password', 'base64:synthetic-key', 'synthetic-key']
        output = 'x'*7000+' synthetic-password base64:synthetic-key synthetic-key\n::warning::untrusted-output'
        result = subprocess.CompletedProcess([], 17, '', output)
        errors = io.StringIO()
        with patch.object(smoke.subprocess, 'run', return_value=result):
            with contextlib.redirect_stderr(errors):
                with self.assertRaisesRegex(RuntimeError, 'migrate disposable database \\(exit 17;'):
                    run.compose('run', '--rm', 'app', operation='migrate disposable database')
        self.assertEqual(run.summary['failure'], {'operation': 'migrate disposable database', 'exit_code': 17})
        console = errors.getvalue()
        self.assertEqual(len(console.splitlines()), 1)
        excerpt = json.loads(console.removeprefix('Smoke failure detail: ').strip())
        self.assertLessEqual(len(excerpt), 6000)
        self.assertIn('[REDACTED]', excerpt)
        for text in (console, (run.directory/'runtime.log').read_text(), json.dumps(run.summary)):
            self.assertNotIn('synthetic-password', text)
            self.assertNotIn('synthetic-key', text)
        self.assertFalse(any(line.startswith('::') for line in console.splitlines()))

    def test_later_cleanup_error_does_not_replace_primary_failure_identity(self):
        run = self.make_run()
        with patch.object(smoke.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '', 'failure')):
            with contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(RuntimeError):
                    run.compose('up', 'app', operation='start application and await health')
                with self.assertRaises(RuntimeError):
                    run.docker('volume', 'rm', 'disposable-volume')
        self.assertEqual(run.summary['failure']['operation'], 'start application and await health')

    def test_operation_progress_does_not_print_command_payload_or_successful_config(self):
        run = self.make_run()
        output = io.StringIO()
        with patch.object(smoke.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, 'private-rendered-config', '')):
            with contextlib.redirect_stdout(output):
                run.compose('config', '--format', 'json')
                run.compose('exec', 'app', 'sh', '-c', 'private-command-payload', log=True, operation='verify storage')
        self.assertEqual(output.getvalue(), 'Smoke operation: verify storage\n')
        self.assertNotIn('private-command-payload', (run.directory/'runtime.log').read_text())

    def test_failed_smoke_returns_nonzero_even_after_successful_cleanup(self):
        run = self.make_run()
        run.changed = True
        run.write('password', 'synthetic-only')
        def cleanup():
            run.summary['cleanup'] = 'verified-empty'
        with patch.object(run, 'compose', return_value=''), patch.object(run, 'cleanup', side_effect=cleanup):
            with contextlib.redirect_stdout(io.StringIO()):
                status = run.finish(True)
        self.assertEqual(status, 1)
        self.assertFalse((run.directory/'password').exists())
        self.assertEqual(json.loads((run.directory/'summary.json').read_text())['cleanup'], 'verified-empty')

    def test_failure_keeps_diagnostics_and_returns_nonzero_when_cleanup_is_refused(self):
        run = self.make_run()
        run.changed = True
        run.redactions = ['synthetic-secret']
        run.write('password', 'synthetic-secret')
        run.record('error synthetic-secret')
        with patch.object(run, 'compose', return_value=''), patch.object(run, 'cleanup', side_effect=RuntimeError('unverified resource')):
            with contextlib.redirect_stdout(io.StringIO()):
                status = run.finish(True)
        self.assertEqual(status, 1)
        self.assertIn('[REDACTED]', (run.directory/'runtime.log').read_text())
        self.assertNotIn('synthetic-secret', (run.directory/'runtime.log').read_text())
        self.assertTrue((run.directory/'password').is_file())
        self.assertEqual(json.loads((run.directory/'summary.json').read_text())['result'], 'failed')
        self.assertEqual((run.directory/'summary.json').stat().st_mode & 0o777, 0o600)

    def test_real_compose_render_ignores_caller_project_database_and_mount_overrides(self):
        environment = dict(os.environ, COMPOSE_PROJECT_NAME='dcms-mysql84-tests', COMPOSE_FILE='/missing/foreign.yaml',
                           COMPOSE_PROFILES='foreign', COMPOSE_ENV_FILES='/missing/foreign.env', COMPOSE_REMOVE_ORPHANS='1',
                           DCMS_DB_DATABASE='existing_database', DCMS_DB_USERNAME='root', DCMS_APP_ENV_FILE='/missing/dev.env',
                           DCMS_BIND_ADDRESS='0.0.0.0', DCMS_HTTP_PORT='33084', DCMS_IMAGE='foreign:latest', DB_HOST='other-server')
        run = smoke.SmokeRun(IMAGE, environment)
        self.addCleanup(shutil.rmtree, run.directory)
        run.prepare()  # Only `docker compose config`, never a daemon command.
        config = json.loads(run.compose('config', '--format', 'json'))
        self.assertEqual(config['name'], run.project)
        self.assertEqual(config['services']['app']['environment']['DB_DATABASE'], 'dcms_smoke')
        self.assertEqual(config['services']['app']['environment']['DB_HOST'], 'db')
        self.assertEqual(config['services']['db']['environment']['MYSQL_DATABASE'], 'dcms_smoke')
        self.assertEqual(config['services']['app']['ports'][0]['host_ip'], '127.0.0.1')
        self.assertEqual(str(config['services']['app']['ports'][0]['published']), '0')
        self.assertEqual(config['services']['app']['image'], IMAGE)
        self.assertEqual(set(config['volumes']), {'database', 'documents', 'private', 'framework'})
        for volume in config['volumes'].values():
            self.assertTrue(volume['name'].startswith(run.project+'_'))
        for secret in config['secrets'].values():
            self.assertEqual(Path(secret['file']).parent, run.directory)

    def test_missing_opt_in_refuses_before_docker_execution(self):
        environment = {key: value for key, value in os.environ.items() if not key.startswith('DCMS_')}
        result = subprocess.run(['sh', str(ROOT/'docker/smoke.sh'), IMAGE], env=environment, text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Requires DCMS_DISPOSABLE_SMOKE=1', result.stderr)
        self.assertNotIn('Smoke evidence:', result.stdout)


if __name__ == '__main__':
    unittest.main()
