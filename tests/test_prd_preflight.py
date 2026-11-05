"""Production rendering and isolation checks; no daemon, /srv writes or real secrets."""
import base64
import contextlib
import copy
import io
import json
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


REPOSITORY = Path(__file__).resolve().parent.parent
PRODUCTION_CONFIG = Path('/srv/dcms-prd/config')


@unittest.skipUnless(shutil.which('docker'), 'Docker CLI/Compose required for configuration rendering')
class ProductionPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-prd-plan-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.image = 'ghcr.io/pintybhatt/psg-document-control@sha256:'+'a'*64
        self.write('db-password', 'synthetic-prd-password')
        self.write('db-root-password', 'synthetic-prd-root-password')
        application = (REPOSITORY/'docker/prd.app.env.example').read_text()
        application = application.replace('APP_KEY=\n', 'APP_KEY=base64:'+base64.b64encode(bytes(range(32))).decode()+'\n')
        application = application.replace('DB_PASSWORD=\n', 'DB_PASSWORD=synthetic-prd-password\n')
        application = application.replace('TRUSTED_PROXIES=\n', 'TRUSTED_PROXIES=172.30.2.1\n')
        self.write('app.env', application)
        self.variables = (REPOSITORY/'docker/prd.compose.env.example').read_text().replace('REPLACE_WITH_APPROVED_DIGEST', 'a'*64)
        self.write('compose.env', self.variables.replace(str(PRODUCTION_CONFIG), str(self.directory)))
        self.write('manifest.json', json.dumps({'image': self.image, 'source_commit': 'b'*40,
                                              'migration_inventory': ['synthetic-migration']}))
        self.environment = {key: value for key, value in os.environ.items() if not key.startswith(('DCMS_', 'COMPOSE_', 'DOCKER_'))}
        self.environment['DOCKER_HOST'] = 'unix:///var/run/docker.sock'
        command = ['docker', '--host', 'unix:///var/run/docker.sock', 'compose', '--project-name', 'dcms-prd-xppl',
                   '--env-file', str(self.directory/'compose.env'), '-f', str(REPOSITORY/'compose.release.yaml'),
                   '-f', str(REPOSITORY/'compose.prd.yaml'), 'config', '--format', 'json']
        result = subprocess.run(command, env=self.environment, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, 'Local Compose rendering failed; inspect synthetic fixtures privately.')
        self.config = json.loads(result.stdout)
        # The CLI boundary is virtualized only for fixed /srv files. Compose above is real.
        for secret in self.config['secrets'].values():
            secret['file'] = secret['file'].replace(str(self.directory), str(PRODUCTION_CONFIG))
        self.write('compose.env', self.variables)

    def write(self, filename, content):
        path = self.directory/filename
        path.write_text(content)
        path.chmod(0o600)

    def run_plan(self, target='production', project='dcms-prd-xppl', extra=(), profile=True,
                 compose_env=PRODUCTION_CONFIG/'compose.env'):
        command = ['preflight.py', '--target', target, '--project', project, '--compose-env', str(compose_env),
                   '--manifest', str(self.directory/'manifest.json')]
        if profile:
            command.append('--prd-profile')
        command += list(extra)
        original_read, original_stat, original_is_file = Path.read_text, Path.stat, Path.is_file
        virtual = {PRODUCTION_CONFIG/name: self.directory/name for name in ('compose.env', 'app.env', 'db-password', 'db-root-password')}
        output, errors = io.StringIO(), io.StringIO()
        environment = dict(self.environment, DCMS_IMAGE='untrusted', DCMS_HTTP_PORT='18084',
                           COMPOSE_FILE='/untrusted.yaml', COMPOSE_PROJECT_NAME='dcms-uat-xppl',
                           DOCKER_HOST='tcp://untrusted.invalid:2375')
        with patch.object(sys, 'argv', command), patch.dict(os.environ, environment, clear=True), \
                patch.object(Path, 'read_text', lambda path, *a, **kw: original_read(virtual.get(path, path), *a, **kw)), \
                patch.object(Path, 'stat', lambda path, *a, **kw: original_stat(virtual.get(path, path), *a, **kw)), \
                patch.object(Path, 'is_file', lambda path, *a, **kw: original_is_file(virtual.get(path, path), *a, **kw)), \
                patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0, json.dumps(self.config), '')) as docker, \
                contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
            with self.assertRaises(SystemExit) as stopped:
                runpy.run_path(str(REPOSITORY/'docker/preflight.py'), run_name='__main__')
        return stopped.exception.code, output.getvalue()+errors.getvalue(), docker

    def assert_refused(self, message=None, **kwargs):
        code, output, _ = self.run_plan(**kwargs)
        self.assertNotEqual(code, 0)
        self.assertIn('STOP:', str(code))
        if message:
            self.assertIn(message, str(code))
        for secret in ('synthetic-prd-password', 'synthetic-prd-root-password'):
            self.assertNotIn(secret, str(code)+output)

    def test_rendered_production_plan_passes_using_only_explicit_local_config(self):
        originals = {path: path.read_bytes() for path in self.directory.iterdir()}

        code, output, docker = self.run_plan()

        self.assertEqual(code, 0)
        self.assertIn('No deployment performed', output)
        self.assertIn('Daemon probes not requested', output)
        docker.assert_called_once()
        command = docker.call_args.args[0]
        self.assertEqual(command[:4], ['docker', '--host', 'unix:///var/run/docker.sock', 'compose'])
        self.assertEqual(command[-3:], ['config', '--format', 'json'])
        self.assertIn(str(REPOSITORY/'compose.prd.yaml'), command)
        self.assertNotIn(str(REPOSITORY/'compose.uat.yaml'), command)
        environment = docker.call_args.kwargs['env']
        self.assertEqual(environment['DOCKER_HOST'], 'unix:///var/run/docker.sock')
        self.assertFalse(any(key.startswith(('DCMS_', 'COMPOSE_')) for key in environment))
        self.assertNotIn('synthetic-prd-password', output)
        for path, content in originals.items():
            self.assertEqual(path.read_bytes(), content)

    def test_profile_requires_production_exact_project_and_https_only(self):
        for arguments in ({'target': 'uat'}, {'project': 'dcms-uat-xppl'}, {'project': 'dcms-production-xppl'},
                          {'project': 'dcms-prd-other'}, {'extra': ['--uat-profile']},
                          {'extra': ['--localhost-verification']}, {'profile': False, 'project': 'dcms-production-xppl'}):
            with self.subTest(arguments=arguments):
                self.assert_refused(**arguments)

    def test_uat_relative_and_alternative_configuration_paths_are_refused_before_compose(self):
        for path in ('/srv/dcms-uat/config/compose.env', 'compose.env', '/tmp/compose.env'):
            with self.subTest(path=path):
                self.assert_refused(compose_env=Path(path))
        for original, changed in (('/srv/dcms-prd/config/app.env', '/srv/dcms-uat/config/app.env'),
                                  ('/srv/dcms-prd/config/db-password', '/srv/dcms-uat/config/db-password'),
                                  ('/srv/dcms-prd/config/db-root-password', '/srv/dcms-prd/config/db-password'),
                                  ('DCMS_ENVIRONMENT=production', 'DCMS_ENVIRONMENT=uat'),
                                  ('DCMS_HTTP_PORT=18085', 'DCMS_HTTP_PORT=18084'),
                                  ('DCMS_DB_DATABASE=dcms_prd', 'DCMS_DB_DATABASE=dcms_uat')):
            with self.subTest(changed=changed):
                self.write('compose.env', self.variables.replace(original, changed))
                self.assert_refused('Fixed Production')
        self.write('compose.env', self.variables)

    def test_production_configuration_and_secret_symlinks_and_public_modes_are_refused(self):
        original_resolve = Path.resolve
        for name in ('compose.env', 'app.env', 'db-password', 'db-root-password'):
            with self.subTest(name=name):
                (self.directory/name).chmod(0o644)
                self.assert_refused('owner-only')
                (self.directory/name).chmod(0o600)
                with patch.object(Path, 'resolve', lambda path, *a, **kw: Path('/srv/dcms-uat/config')/name if path == PRODUCTION_CONFIG/name else original_resolve(path, *a, **kw)):
                    self.assert_refused('symlinks')

    def test_unsafe_application_environment_and_cross_environment_identifiers_are_refused(self):
        environment = self.config['services']['app']['environment']
        changes = {
            'APP_ENV': 'uat', 'APP_DEBUG': 'true', 'APP_URL': 'https://dcms.phonesackgroup.com:8444',
            'APP_KEY': 'base64:placeholder', 'APP_PREVIOUS_KEYS': 'base64:uat-key',
            'DB_CONNECTION': 'sqlite', 'DB_HOST': 'host.docker.internal', 'DB_PORT': '3307',
            'DB_DATABASE': 'dcms_uat', 'DB_USERNAME': 'dcms_uat', 'DB_PASSWORD': 'different-password',
            'DB_URL': 'mysql://external.invalid/uat', 'DB_SOCKET': '/tmp/mysql.sock',
            'SESSION_COOKIE': 'dcms_uat_session', 'CACHE_PREFIX': 'dcms_uat_',
            'SESSION_DRIVER': 'file', 'CACHE_STORE': 'file', 'QUEUE_CONNECTION': 'database',
            'SESSION_ENCRYPT': 'false', 'SESSION_SECURE_COOKIE': 'false', 'SESSION_HTTP_ONLY': 'false',
            'SESSION_SAME_SITE': 'none', 'SESSION_DOMAIN': '.phonesackgroup.com', 'SESSION_PATH': '/uat',
            'SESSION_CONNECTION': 'uat', 'SESSION_STORE': 'redis', 'DB_CACHE_CONNECTION': 'uat',
            'DB_CACHE_LOCK_CONNECTION': 'uat', 'FILESYSTEM_DISK': 'public', 'LOG_CHANNEL': 'single',
            'XPPL_UAT_IMPORT_ENABLED': 'true', 'PHP_MEMORY_LIMIT': '1G',
        }
        original = dict(environment)
        for key, value in changes.items():
            with self.subTest(key=key):
                environment[key] = value
                self.assert_refused()
                environment.clear()
                environment.update(original)
        for value in ('', '*', '172.16.0.0/12', '0.0.0.0', 'untrusted.invalid'):
            with self.subTest(proxy=value):
                environment['TRUSTED_PROXIES'] = value
                self.assert_refused()

    def test_unapproved_image_repository_and_mutable_database_image_are_refused(self):
        image = self.image.replace('pintybhatt/psg-document-control', 'other/application')
        self.write('manifest.json', json.dumps({'image': image, 'source_commit': 'b'*40, 'migration_inventory': ['synthetic']}))
        self.config['services']['app']['image'] = image
        self.assert_refused('approved DCMS')
        self.config['services']['app']['image'] = self.image
        self.write('manifest.json', json.dumps({'image': self.image, 'source_commit': 'b'*40, 'migration_inventory': ['synthetic']}))
        for image in ('mysql:8.4', 'mysql:8.0@sha256:'+'c'*64):
            self.config['services']['db']['image'] = image
            self.assert_refused('MySQL 8.4')

    def test_shared_named_resources_external_storage_and_host_backed_volumes_are_refused(self):
        original = copy.deepcopy(self.config)
        for kind in ('volumes', 'networks'):
            for name in original[kind]:
                for change in ({'external': True}, {'name': 'dcms-uat-xppl_'+name},
                               {'name': 'dcms-prd-xppl_unapproved'}, {'driver': 'unapproved'},
                               {'driver_opts': {'type': 'none', 'o': 'bind', 'device': '/srv/dcms-uat'}}):
                    with self.subTest(kind=kind, name=name, change=change):
                        self.config = copy.deepcopy(original)
                        self.config[kind][name].update(change)
                        self.assert_refused()

    def test_unreviewed_mounts_and_public_database_and_network_connections_are_refused(self):
        original = copy.deepcopy(self.config)
        for name in ('app', 'db'):
            for key, value in (('volumes', [{'type': 'bind', 'source': '/srv/dcms-uat', 'target': '/var/www/html'}]),
                               ('networks', {'ingress': {}}), ('ports', [{'host_ip': '0.0.0.0', 'target': 3306, 'published': '3306'}])):
                with self.subTest(name=name, key=key):
                    self.config = copy.deepcopy(original)
                    self.config['services'][name][key] = value
                    self.assert_refused()
        self.config = copy.deepcopy(original)
        self.config['networks']['backend']['internal'] = False
        self.assert_refused()
        for change in ({'host_ip': '0.0.0.0'}, {'published': '18084'}, {'protocol': 'udp'}, {'target': 80}):
            self.config = copy.deepcopy(original)
            self.config['services']['app']['ports'][0].update(change)
            self.assert_refused()
        for change in ({'read_only': False}, {'source': str(REPOSITORY/'docker/apache-uat.conf')},
                       {'bind': {'create_host_path': True}}):
            self.config = copy.deepcopy(original)
            self.config['services']['app']['volumes'][-1].update(change)
            self.assert_refused()

    def test_runtime_protections_resources_and_service_injection_are_refused(self):
        original = copy.deepcopy(self.config)
        for name in ('app', 'db'):
            for key, value in (('privileged', True), ('network_mode', 'host'), ('container_name', 'uat'),
                               ('pid', 'host'), ('ipc', 'host'), ('devices', ['/dev/sda']), ('volumes_from', ['uat']),
                               ('user', 'root'), ('entrypoint', ['sh']), ('cap_add', ['SYS_ADMIN']),
                               ('mem_limit', 0), ('memswap_limit', -1), ('cpus', 2), ('pids_limit', -1)):
                with self.subTest(name=name, key=key):
                    self.config = copy.deepcopy(original)
                    self.config['services'][name][key] = value
                    self.assert_refused()
        for key, value in (('read_only', False), ('cap_drop', []), ('security_opt', []), ('tmpfs', []), ('command', ['sh'])):
            self.config = copy.deepcopy(original)
            self.config['services']['app'][key] = value
            self.assert_refused()
        self.config = copy.deepcopy(original)
        self.config['services']['extra'] = {'image': 'unapproved'}
        self.assert_refused()

    def test_database_passwords_secret_wiring_and_identity_must_be_isolated(self):
        original = copy.deepcopy(self.config)
        for key, value in (('MYSQL_DATABASE', 'dcms_uat'), ('MYSQL_USER', 'root'), ('MYSQL_PASSWORD', 'inline'),
                           ('MYSQL_ROOT_PASSWORD_FILE', '/run/secrets/uat')):
            self.config = copy.deepcopy(original)
            self.config['services']['db']['environment'][key] = value
            self.assert_refused()
        for value in ('', 'synthetic-prd-password', 'REPLACE_WITH_PASSWORD'):
            self.config = copy.deepcopy(original)
            self.write('db-root-password', value)
            self.assert_refused('passwords')
        self.write('db-root-password', 'synthetic-prd-root-password')
        for name in ('db_password', 'db_root_password'):
            self.config = copy.deepcopy(original)
            self.config['secrets'][name]['file'] = '/srv/dcms-uat/config/db-password'
            self.assert_refused('secret files')
        self.config = copy.deepcopy(original)
        self.config['services']['db']['healthcheck']['disable'] = True
        self.assert_refused('healthcheck')


class ProductionIngressTests(unittest.TestCase):
    def test_restricted_rendering_preserves_nginx_variables_and_fixed_security_boundary(self):
        template = (REPOSITORY/'docker/nginx-prd.conf.template').read_text()
        self.assertEqual(set(re.findall(r'\$\{([^}]+)\}', template)), {'DCMS_TLS_CERTIFICATE', 'DCMS_TLS_KEY'})
        rendered = template.replace('${DCMS_TLS_CERTIFICATE}', '/etc/ssl/dcms-prd.crt').replace('${DCMS_TLS_KEY}', '/etc/ssl/dcms-prd.key')
        for directive in ('listen 9444 ssl;', 'server_name dcms.phonesackgroup.com;', 'proxy_pass http://127.0.0.1:18085;',
                          'proxy_set_header Host dcms.phonesackgroup.com:9444;', 'proxy_set_header X-Forwarded-For $remote_addr;',
                          'proxy_set_header X-Forwarded-Proto https;', 'proxy_set_header X-Forwarded-Port 9444;',
                          'proxy_set_header X-Forwarded-Host "";', 'proxy_set_header Forwarded "";',
                          'proxy_set_header X-Real-IP "";', 'if ($host != dcms.phonesackgroup.com) { return 444; }'):
            self.assertIn(directive, rendered)
        denied = re.search(r'location ~\* (\S+) \{ deny all; \}', rendered).group(1)
        for path in ('/storage', '/storage/confidential.pdf', '/document-vault/secret', '/private/secret', '/.env', '/.git/config', '/STORAGE/secret'):
            self.assertRegex(path.lower(), denied)
        self.assertNotIn('$proxy_add_x_forwarded_for', rendered)
        self.assertNotIn('${', rendered)


if __name__ == '__main__':
    unittest.main()
