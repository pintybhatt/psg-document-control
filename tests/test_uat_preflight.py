"""UAT plan validation using real Compose rendering, never the Docker daemon."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


REPOSITORY = Path(__file__).resolve().parent.parent


@unittest.skipUnless(shutil.which('docker'), 'Docker CLI/Compose required for configuration rendering')
class UatPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-uat-plan-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.repository = self.directory/'repository'
        (self.repository/'docker').mkdir(parents=True)
        for filename in ('compose.release.yaml', 'compose.uat.yaml', 'docker/preflight.py', 'docker/apache-uat.conf'):
            shutil.copyfile(REPOSITORY/filename, self.repository/filename)
        self.image = 'ghcr.io/pintybhatt/psg-document-control@sha256:'+'a'*64
        self.write('password', 'synthetic-test-password')
        self.write('root-password', 'synthetic-root-password')
        application = (REPOSITORY/'docker/app.env.example').read_text().replace('APP_KEY=\n', 'APP_KEY=base64:synthetic-test-key\n')
        application = application.replace('DB_PASSWORD=\n', 'DB_PASSWORD=synthetic-test-password\n')
        application = application.replace('https://REPLACE_WITH_APPROVED_HOST', 'https://uat.example.invalid:8444')
        application = application.replace('TRUSTED_PROXIES=\n', 'TRUSTED_PROXIES=172.30.1.1\n')
        self.write('app.env', application)
        self.write('compose.env', '\n'.join([
            'DCMS_ENVIRONMENT=uat', 'DCMS_IMAGE='+self.image, 'DCMS_HTTP_PORT=18084',
            'DCMS_APP_ENV_FILE='+str(self.directory/'app.env'), 'DCMS_DB_DATABASE=dcms_uat',
            'DCMS_DB_USERNAME=dcms_uat', 'DCMS_DB_PASSWORD_FILE='+str(self.directory/'password'),
            'DCMS_DB_ROOT_PASSWORD_FILE='+str(self.directory/'root-password'),
        ])+'\n')
        self.write('manifest.json', json.dumps({'image': self.image, 'source_commit': 'b'*40,
                                              'migration_inventory': ['synthetic-migration']}))

    def write(self, filename, content):
        path = self.directory/filename
        path.write_text(content)
        path.chmod(0o600)

    def run_plan(self, target='uat', project='dcms-uat-xppl', technical=False, profile=True):
        command = ['python3', '-B', str(self.repository/'docker/preflight.py'), '--target', target,
                   '--project', project, '--compose-env', str(self.directory/'compose.env'),
                   '--manifest', str(self.directory/'manifest.json')]
        if profile:
            command.append('--uat-profile')
        if technical:
            command.append('--localhost-verification')
        environment = dict(os.environ, DCMS_IMAGE='untrusted', DCMS_HTTP_PORT='9444',
                           COMPOSE_FILE='/not-an-approved-file', COMPOSE_PROJECT_NAME='netbox',
                           DOCKER_HOST='tcp://untrusted.invalid:2375')
        return subprocess.run(command, env=environment, text=True, capture_output=True, timeout=30)

    def assert_refused(self, **kwargs):
        result = self.run_plan(**kwargs)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('STOP:', result.stderr)
        self.assertNotIn('synthetic-test-password', result.stdout+result.stderr)

    def test_valid_plan_ignores_caller_resource_overrides_and_prints_no_credentials(self):
        result = self.run_plan()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('No deployment performed', result.stdout)
        self.assertIn('Daemon probes not requested', result.stdout)
        self.assertNotIn('synthetic-test-password', result.stdout+result.stderr)

    def test_production_and_unrelated_projects_are_refused(self):
        for arguments in ({'target': 'production'}, {'project': 'netbox'}, {'project': 'dcms-uat-other'}):
            with self.subTest(arguments=arguments):
                self.assert_refused(**arguments)

    def test_existing_database_identities_are_refused(self):
        for name in ('DB_DATABASE', 'DB_USERNAME'):
            with self.subTest(name=name):
                original = (self.directory/'app.env').read_text()
                self.write('app.env', original.replace(name+'=dcms_uat', name+'=development'))
                self.assert_refused()
                self.write('app.env', original)

    def test_generic_release_plan_still_works_and_cannot_enable_technical_http(self):
        result = self.run_plan(profile=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assert_refused(profile=False, technical=True)

    def test_production_selection_in_configuration_is_refused(self):
        self.write('compose.env', (self.directory/'compose.env').read_text().replace('DCMS_ENVIRONMENT=uat', 'DCMS_ENVIRONMENT=production'))
        self.assert_refused()

    def test_public_database_and_privileged_runtime_are_refused(self):
        path = self.repository/'compose.uat.yaml'
        original = path.read_text()
        for change in (original.replace('  db:\n', '  db:\n    ports: ["3306:3306"]\n'),
                       original.replace('  app:\n', '  app:\n    privileged: true\n'),
                       original.replace('  app:\n', '  app:\n    read_only: false\n')):
            with self.subTest(configuration=change[:30]):
                path.write_text(change)
                self.assert_refused()
        path.write_text(original)

    def test_non_loopback_port_and_missing_memory_limit_are_refused(self):
        path = self.repository/'compose.uat.yaml'
        original = path.read_text()
        for change in (original.replace('127.0.0.1:', '0.0.0.0:'), original.replace('mem_limit: 640m', 'mem_limit: 1024m')):
            with self.subTest(configuration=change[:20]):
                path.write_text(change)
                self.assert_refused()
        path.write_text(original)

    def test_unapproved_image_and_db_url_are_refused(self):
        original = (self.directory/'manifest.json').read_text()
        changed_image = self.image.replace('pintybhatt/psg-document-control', 'other/application')
        self.write('manifest.json', original.replace(self.image, changed_image))
        self.write('compose.env', (self.directory/'compose.env').read_text().replace(self.image, changed_image))
        self.assert_refused()
        self.write('manifest.json', original)
        self.write('compose.env', (self.directory/'compose.env').read_text().replace(changed_image, self.image))
        self.write('app.env', (self.directory/'app.env').read_text().replace('DB_URL=\n', 'DB_URL=mysql://external.invalid/db\n'))
        self.assert_refused()

    def test_world_readable_secrets_and_insecure_public_cookies_are_refused(self):
        (self.directory/'password').chmod(0o644)
        self.assert_refused()
        (self.directory/'password').chmod(0o600)
        self.write('app.env', (self.directory/'app.env').read_text().replace('SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE=false'))
        self.assert_refused()

    def test_localhost_http_requires_explicit_technical_mode_and_cannot_accept_public_url(self):
        self.assert_refused(technical=True)
        self.write('app.env', (self.directory/'app.env').read_text()
                   .replace('https://uat.example.invalid:8444', 'http://localhost:18084')
                   .replace('SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE=false'))
        self.assert_refused()
        result = self.run_plan(technical=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_foreign_external_storage_is_refused(self):
        path = self.repository/'compose.release.yaml'
        path.write_text(path.read_text().replace('  database:\n', '  database:\n    external: true\n    name: unrelated-data\n'))
        self.assert_refused()

    def test_broad_host_mount_and_external_network_are_refused(self):
        path = self.repository/'compose.uat.yaml'
        original = path.read_text()
        for change in (original.replace('source: ./docker/apache-uat.conf', 'source: /'),
                       original+'\nnetworks:\n  backend:\n    internal: false\n'):
            with self.subTest(configuration=change[:25]):
                path.write_text(change)
                self.assert_refused()
        path.write_text(original)


if __name__ == '__main__':
    unittest.main()
