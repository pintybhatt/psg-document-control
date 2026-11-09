"""Production engine exercises real orchestration with mocked Docker, GitHub and backup transport."""
import copy
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch

from test_release_preparation import load, automatic_release

prd = load('prd_deployment', 'deploy-prd.py')
PROJECT = 'dcms-prd-xppl'
SOURCE = 'a'*40
ONE = {'path': 'database/migrations/2026_01_01_one.php', 'sha256': '1'*64}
TWO = {'path': 'database/migrations/2026_01_02_two.php', 'sha256': '2'*64}


def image(config_id):
    raw = json.dumps({'schemaVersion': 2, 'mediaType': 'application/vnd.oci.image.manifest.v1+json',
                      'config': {'mediaType': 'application/vnd.oci.image.config.v1+json', 'digest': config_id}})
    return 'ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(raw.encode()).hexdigest(), raw


class ProductionRequestTests(unittest.TestCase):
    def data(self):
        return {'deployment_run_id': 789, 'deployment_run_attempt': 1, 'release_run_id': 123, 'release_run_attempt': 1,
                'release_artifact_id': 456, 'image': image('sha256:'+'b'*64)[0], 'source_commit': SOURCE, 'manifest_sha256': 'c'*64}

    def test_only_exact_release_and_workflow_identities_are_accepted(self):
        data = self.data()
        self.assertEqual(prd.request(io.BytesIO(json.dumps(data).encode())), data)
        for change in ({'target': 'uat'}, {'project': 'dcms-uat-xppl'}, {'command': 'sh'}, {'github_token': 'secret'},
                       {'config': '/srv/dcms-uat'}, {'ref': 'main'}, {'image': 'ghcr.io/other/app@sha256:'+'a'*64},
                       {'source_commit': 'main'}, {'deployment_run_id': True}, {'release_run_attempt': 0}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                prd.request(io.BytesIO(json.dumps(dict(data, **change)).encode()))
        for raw in (b' ' * 4097, b'[]', b'{"image":1,"image":2}'):
            with self.assertRaises(ValueError):
                prd.request(io.BytesIO(raw))

    def test_fixed_production_constants_and_forced_command_reject_arbitrary_commands(self):
        self.assertEqual(prd.ROOT, Path('/srv/dcms-prd'))
        self.assertEqual(prd.PROJECT, PROJECT)
        script = Path(prd.__file__).with_name('prd-ssh-command.sh')
        for command in ('deploy-dcms-uat', 'sh', 'deploy-dcms-prd; id', 'deploy-dcms-prd extra', ''):
            result = subprocess.run(['/bin/sh', str(script)], env={'SSH_ORIGINAL_COMMAND': command}, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
        entrypoint = Path(prd.__file__).with_name('prd-deploy-entrypoint.sh')
        result = subprocess.run(['/bin/sh', str(entrypoint), 'unexpected'], capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_forward_only_inventory_rejects_modified_missing_duplicate_and_out_of_order_files(self):
        prd.forward({'migration_inventory': [ONE]}, {'migration_inventory': [ONE, TWO]})
        for inventory in ([], [TWO], [dict(ONE, sha256='f'*64), TWO], [ONE, ONE], [TWO, ONE],
                          [ONE, {'path': '../uat.php', 'sha256': '2'*64}]):
            with self.subTest(inventory=inventory), self.assertRaises(ValueError):
                prd.forward({'migration_inventory': [ONE]}, {'migration_inventory': inventory})


class ProductionDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-prd-engine-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for name in ('config', 'history', 'backups', 'registry', 'release/docker'):
            (self.root/name).mkdir(parents=True, exist_ok=True)
        self.current_image, self.old_raw = image('sha256:'+'e'*64)
        self.selected_image, self.new_raw = image('sha256:'+'b'*64)
        release = automatic_release()
        self.current = {'image': self.current_image, 'source_commit': 'd'*40, 'tested_image_id': 'sha256:'+'e'*64,
                        'migration_inventory': [ONE], 'repository': prd.shared.evidence.REPOSITORY, 'platform': 'linux/amd64'}
        self.manifest = json.loads(release['manifest'])
        self.manifest.update(image=self.selected_image, migration_inventory=[ONE, TWO])
        release['manifest'] = json.dumps(self.manifest).encode()
        self.release = release
        self.data = ProductionRequestTests().data()
        self.data.update(image=self.selected_image, manifest_sha256=hashlib.sha256(release['manifest']).hexdigest())
        self.api = Mock()
        self.api.get.side_effect = self.github
        self.api.release.return_value = self.release
        self.files = {
            'deployment.lock': '', 'config/current.json': json.dumps(self.current),
            'config/compose.env': 'DCMS_IMAGE='+self.current_image+'\nSERVER_SETTING=preserve\n',
            'config/app.env': 'APP_KEY=base64:synthetic-production\nDB_DATABASE=dcms_prd\n',
            'config/db-password': 'synthetic-runtime', 'config/db-root-password': 'synthetic-root',
            'config/migration.env': 'DB_USERNAME=dcms_prd_migrate\nDB_PASSWORD=synthetic-migration\n',
            'config/backup.cnf': '[client]\nuser=dcms_prd_backup\npassword=synthetic-backup\n',
            'config/qnap-readiness-known-hosts': 'synthetic-key-pin', 'config/qnap-readiness-key': 'synthetic-read-only-key',
            'config/github-read-token': 'synthetic-server-token', 'registry/config.json': '{}',
            'release/compose.release.yaml': 'synthetic reviewed release configuration',
            'release/compose.prd.yaml': 'synthetic reviewed Production overlay',
            'release/docker/apache-prd.conf': 'synthetic reviewed Apache tuning',
        }
        for name, content in self.files.items():
            (self.root/name).write_text(content)
            (self.root/name).chmod(0o600)
        self.app_id, self.db_id = '1'*64, '2'*64
        self.db_image = 'mysql:8.4.13@sha256:'+'9'*64
        self.app = self.container_info('app', self.app_id)
        self.database = self.container_info('db', self.db_id)
        self.calls, self.failure, self.migrated, self.replaced = [], None, False, False
        self.volume_change, self.container_change, self.schema_change = {}, {}, None
        self.config = {'services': {'app': {'environment': {'DB_PASSWORD': 'synthetic-runtime', 'APP_KEY': 'base64:synthetic-production'}}, 'db': {'image': self.db_image}}}
        self.history = self.root/'history/gha-789-1'

    def github(self, path):
        if path.startswith('compare/'):
            return {'status': 'ahead'}
        return {'id': 789, 'run_attempt': 1, 'path': prd.WORKFLOW, 'name': 'Deploy DCMS PRD',
                'event': 'workflow_dispatch', 'head_branch': 'main', 'status': 'in_progress',
                'repository': {'full_name': prd.shared.evidence.REPOSITORY},
                'head_repository': {'full_name': prd.shared.evidence.REPOSITORY}}

    def container_info(self, service, identity):
        names = ('documents', 'private', 'framework') if service == 'app' else ('database',)
        destinations = ('/var/www/html/storage/app/document-vault', '/var/www/html/storage/app/private', '/var/www/html/storage/framework') if service == 'app' else ('/var/lib/mysql',)
        mounts = [{'Type': 'volume', 'Name': PROJECT+'_'+name, 'Destination': target, 'RW': True} for name, target in zip(names, destinations)]
        if service == 'app':
            mounts += [{'Type': 'bind', 'Source': str(self.root/'release/docker/apache-prd.conf'),
                        'Destination': '/etc/apache2/conf-enabled/zz-dcms-prd.conf', 'RW': False}]
        return {'Id': identity, 'Image': 'sha256:'+'e'*64,
                'Config': {'Image': self.current_image if service == 'app' else self.db_image,
                           'Labels': {'com.docker.compose.project': PROJECT, 'com.docker.compose.service': service,
                                      'org.opencontainers.image.revision': 'd'*40,
                                      'org.opencontainers.image.source': 'https://github.com/'+prd.shared.evidence.REPOSITORY},
                           'Env': ['APP_ENV=production', 'APP_URL=https://dcms.phonesackgroup.com:9444', 'DB_DATABASE=dcms_prd',
                                   'DB_USERNAME=dcms_prd', 'DB_HOST=db', 'DB_PASSWORD=synthetic-runtime', 'APP_KEY=base64:synthetic-production'] if service == 'app' else ['MYSQL_DATABASE=dcms_prd', 'MYSQL_USER=dcms_prd']},
                'State': {'Running': True, 'StartedAt': 'original', 'Health': {'Status': 'healthy'}}, 'Mounts': mounts,
                'HostConfig': {'PortBindings': {'8080/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18085'}]} if service == 'app' else {}},
                'NetworkSettings': {'Networks': {PROJECT+'_'+name: {} for name in (('ingress', 'backend') if service == 'app' else ('backend',))}}}

    def fake_run(self, command, timeout=180):
        self.calls.append(command)
        if 'preflight.py' in ' '.join(command):
            if self.failure == 'preflight':
                raise ValueError('preflight')
            return ''
        if 'compose' in command:
            args = command[command.index('compose')+1:]
            if args[-3:] == ['config', '--format', 'json']:
                return json.dumps(self.config)
            if 'ps' in args:
                if args[-1] == 'app': return self.app_id
                if args[-1] == 'db': return self.db_id
                return self.app_id+'\n'+self.db_id+ ('\n'+'3'*64 if self.failure == 'extra_container' else '')
            if prd.SCHEMA_PROBE in args:
                rows = [{'migration': Path(ONE['path']).stem, 'batch': 1}]
                if self.migrated:
                    rows += [{'migration': Path(TWO['path']).stem, 'batch': 2}]
                if self.schema_change:
                    rows = self.schema_change
                return json.dumps({'inventory': self.manifest['migration_inventory'], 'ran': rows})
            if 'migrate' in args:
                if self.failure == 'migration': raise ValueError('migration')
                self.migrated = True
            if '--force-recreate' in args:
                self.replaced = True
                self.app['Config']['Image'] = self.selected_image
                self.app['Config']['Labels']['org.opencontainers.image.revision'] = SOURCE
                self.app['Image'] = 'sha256:'+'b'*64
            if 'is_file(' in ' '.join(args): return 'down'
            return ''
        if 'container' in command:
            value = copy.deepcopy(self.app if command[-1] == self.app_id else self.database)
            value.update(self.container_change)
            if self.failure == 'database_restart' and self.replaced and command[-1] == self.db_id:
                value['State']['StartedAt'] = 'changed'
            return json.dumps([value])
        if 'volume' in command:
            name = command[-1].removeprefix(PROJECT+'_')
            return json.dumps([dict({'Driver': 'local', 'Options': None, 'Labels': {'com.docker.compose.project': PROJECT,
                                    'com.docker.compose.volume': name}}, **self.volume_change)])
        if 'network' in command:
            return json.dumps([{'Labels': {'com.docker.compose.project': PROJECT}, 'Driver': 'bridge', 'Internal': command[-1].endswith('_backend')}])
        if 'imagetools' in command:
            return self.new_raw if command[-1] == self.selected_image else self.old_raw
        if 'image' in command:
            selected = command[-1] == self.selected_image
            return json.dumps([{'Id': 'sha256:'+('b' if selected else 'e')*64, 'RepoDigests': [command[-1]],
                                'Os': 'linux', 'Architecture': 'amd64', 'Config': {'Labels': {
                                    'org.opencontainers.image.revision': SOURCE if selected else 'd'*40,
                                    'org.opencontainers.image.source': 'https://github.com/'+prd.shared.evidence.REPOSITORY}}}])
        if command[0] == '/usr/bin/curl':
            return ('500' if self.failure == 'health' else '200') if command[-1].endswith('/up') else ('200' if self.failure == 'exposure' else '403')
        return ''

    def stream(self, command, destination):
        self.calls.append(command)
        if destination.name == 'database.sql':
            destination.write_text('synthetic SQL\n-- Dump completed on synthetic\n')
            if self.failure == 'dump': raise ValueError('dump')
        else:
            with tarfile.open(destination, 'x') as archive:
                for name in (('foreign', 'private') if self.failure == 'archive' else ('documents', 'private')):
                    item = tarfile.TarInfo(name)
                    item.type = tarfile.DIRTYPE
                    archive.addfile(item)
        destination.chmod(0o600)

    def verify_qnap(self):
        self.calls.append(['verify-qnap'])
        if self.failure == 'qnap':
            raise ValueError('QNAP readiness')
        return {'verified': True, 'scope': 'off-host'}

    def exercise(self):
        with patch.object(prd, 'ROOT', self.root), patch.object(prd.paired, 'ROOT', self.root), \
                patch.object(prd, 'protected', side_effect=lambda path, *args: Path(path)), \
                patch.object(prd.paired, 'protected', side_effect=lambda path, *args: Path(path)), \
                patch.object(prd.os, 'geteuid', return_value=0), \
                patch.object(prd.shared.auth, 'GitHub', return_value=self.api), \
                patch.object(prd.Deployment, 'run', side_effect=self.fake_run), \
                patch.object(prd.Deployment, 'stream', side_effect=self.stream), \
                patch.object(prd.qnap, 'verify', side_effect=self.verify_qnap):
            prd.execute(self.data)

    def test_success_backs_up_before_forward_migration_and_replaces_only_production_app(self):
        self.exercise()
        self.assertTrue(self.migrated and self.replaced)
        backup_index = next(i for i, c in enumerate(self.calls) if 'mysqldump' in ' '.join(c))
        migrate_index = next(i for i, c in enumerate(self.calls) if 'migrate' in c)
        replace_index = next(i for i, c in enumerate(self.calls) if '--force-recreate' in c)
        self.assertLess(self.calls.index(['verify-qnap']), next(i for i, c in enumerate(self.calls) if 'down' in c))
        self.assertLess(backup_index, migrate_index)
        self.assertLess(migrate_index, replace_index)
        self.assertEqual(self.calls[replace_index][-1], 'app')
        self.assertIn('--no-deps', self.calls[replace_index])
        self.assertIn(str(self.root/'config/migration.env'), self.calls[migrate_index])
        self.assertEqual(json.loads((self.root/'config/current.json').read_text()), self.manifest)
        self.assertEqual(json.loads((self.history/'result.json').read_text())['status'], 'deployed')
        self.assertEqual(json.loads((self.history/'backup.json').read_text())['scope'], 'local')
        self.assertTrue(json.loads((self.history/'qnap-backup.json').read_text())['verified'])
        self.assertEqual(prd.paired.validate(self.root/'backups/gha-789-1')[1]['backup_id'], 'gha-789-1')
        self.assertFalse((self.root/'config/recovery-required.json').exists())
        for name, content in self.files.items():
            if name not in ('config/current.json', 'config/compose.env'):
                self.assertEqual((self.root/name).read_text(), content)
        self.assertEqual((self.root/'config/compose.env').read_text(), self.files['config/compose.env'].replace(self.current_image, self.selected_image))
        joined = '\n'.join(' '.join(command) for command in self.calls)
        for forbidden in ('dcms-uat', 'dcms_uat', '18084', ':8444', 'netbox', 'migrate:rollback', '--volumes', 'volume rm'):
            self.assertNotIn(forbidden, joined.lower())
        self.assertFalse(any('compose' in c and 'artisan' not in c and ('up' in c or 'stop' in c) and c[-1] != 'app' for c in self.calls))
        self.assertTrue(all(command[:3] == prd.DOCKER for command in self.calls if '/usr/bin/docker' in command))

    def test_failures_before_maintenance_do_not_create_recovery_state(self):
        for failure in ('preflight', 'extra_container', 'qnap'):
            with self.subTest(failure=failure):
                self.failure = failure
                with self.assertRaises(ValueError): self.exercise()
                self.assertFalse((self.root/'config/recovery-required.json').exists())
                self.assertFalse(self.migrated or self.replaced)
                self.assertFalse(any('down' in c or 'stop' in c or 'mysqldump' in ' '.join(c) for c in self.calls))
                self.assertEqual(json.loads((self.history/'result.json').read_text())['status'], 'failed')
                # A workflow attempt is deliberately single-use; use a new run for the next case.
                self.data['deployment_run_attempt'] += 1
                self.api.get.side_effect = lambda path: dict(self.github(path), run_attempt=self.data['deployment_run_attempt']) if not path.startswith('compare/') else {'status': 'ahead'}
                self.history = self.root/'history'/('gha-789-'+str(self.data['deployment_run_attempt']))

    def test_dump_migration_health_and_exposure_failures_preserve_recovery_evidence(self):
        for failure, stage in (('dump', 'local_backup'), ('archive', 'local_backup'),
                               ('migration', 'migrations'), ('health', 'health'), ('exposure', 'health'),
                               ('database_restart', 'health')):
            fixture = ProductionDeploymentTests()
            fixture.setUp()
            try:
                fixture.failure = failure
                with self.subTest(failure=failure), self.assertRaises(ValueError): fixture.exercise()
                result = json.loads((fixture.history/'result.json').read_text())
                self.assertEqual(result['status'], 'failed')
                self.assertEqual(result['stage'], stage)
                self.assertTrue((fixture.root/'config/recovery-required.json').exists())
                self.assertTrue((fixture.root/'backups/gha-789-1/database.sql').exists())
                self.assertEqual(json.loads((fixture.root/'config/current.json').read_text()), fixture.current)
                self.assertEqual((fixture.root/'config/compose.env').read_text(), fixture.files['config/compose.env'])
                if failure in ('dump', 'archive'):
                    self.assertFalse(fixture.migrated or fixture.replaced)
                if failure == 'migration': self.assertFalse(fixture.replaced)
            finally:
                fixture.doCleanups()

    def test_wrong_source_or_release_identity_refuses_before_any_docker_operation(self):
        self.data['source_commit'] = 'f'*40
        with self.assertRaises(ValueError): self.exercise()
        self.assertEqual(self.calls, [])
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_host_backed_volume_or_uat_runtime_refuses_without_backup_or_migration(self):
        self.volume_change = {'Options': {'device': '/srv/dcms-uat', 'o': 'bind'}}
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse(self.migrated or self.replaced)

    def test_wrong_running_image_source_is_refused(self):
        self.app['Config']['Labels']['org.opencontainers.image.revision'] = 'f'*40
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse((self.root/'backups/gha-789-1').exists())

    def test_uat_runtime_credentials_or_document_volumes_are_refused(self):
        self.app['Config']['Env'] = ['APP_ENV=uat', 'DB_DATABASE=dcms_uat', 'DB_USERNAME=dcms_uat']
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse(self.migrated or self.replaced)

    def test_foreign_document_volume_is_rejected_before_maintenance(self):
        self.app['Mounts'][0]['Name'] = 'dcms-uat-xppl_documents'
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_migration_batches_cannot_be_rewritten(self):
        deployment = prd.Deployment(self.history, self.root/'config/compose.env')
        state = {'inventory': [ONE, TWO], 'ran': [{'migration': Path(ONE['path']).stem, 'batch': 2},
                                                {'migration': Path(TWO['path']).stem, 'batch': 2}]}
        with patch.object(deployment, 'dc', return_value=json.dumps(state)):
            with self.assertRaisesRegex(ValueError, 'history changed'):
                deployment.schema(self.manifest, previous_rows=[{'migration': Path(ONE['path']).stem, 'batch': 1}])

    def test_unadvanced_new_migration_batch_is_refused(self):
        deployment = prd.Deployment(self.history, self.root/'config/compose.env')
        state = {'inventory': [ONE, TWO], 'ran': [{'migration': Path(ONE['path']).stem, 'batch': 1},
                                                {'migration': Path(TWO['path']).stem, 'batch': 1}]}
        with patch.object(deployment, 'dc', return_value=json.dumps(state)):
            with self.assertRaisesRegex(ValueError, 'advance'):
                deployment.schema(self.manifest, previous_rows=[{'migration': Path(ONE['path']).stem, 'batch': 1}])

    def test_wrong_workflow_or_failed_publication_refuses_without_docker(self):
        self.api.get.side_effect = lambda path: dict(self.github(path), name='Deploy DCMS UAT')
        with self.assertRaises(ValueError): self.exercise()
        self.assertEqual(self.calls, [])

    def test_failed_release_checks_are_rejected_before_touching_the_stack(self):
        self.release['jobs']['jobs'][0]['conclusion'] = 'failure'
        with self.assertRaises(ValueError): self.exercise()
        self.assertEqual(self.calls, [])

    def test_runtime_key_mismatch_stops_before_maintenance(self):
        self.config['services']['app']['environment']['APP_KEY'] = 'base64:other-key'
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_command_outputs_stay_private_and_ambient_docker_environment_is_ignored(self):
        self.history.mkdir(mode=0o700)
        deployment = prd.Deployment(self.history, self.root/'config/compose.env')
        output = io.StringIO()
        with patch.object(prd, 'ROOT', self.root), patch.dict(os.environ, DOCKER_HOST='tcp://untrusted:2375'), \
                patch.object(prd.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, b'synthetic-password')) as command, \
                contextlib.redirect_stdout(output), self.assertRaises(ValueError):
            deployment.run(prd.DOCKER+['info'])
        self.assertEqual(output.getvalue(), '')
        self.assertIn(b'synthetic-password', (self.history/'1.log').read_bytes())
        self.assertEqual(command.call_args.kwargs['env']['DOCKER_HOST'], 'unix:///var/run/docker.sock')
        self.assertEqual(command.call_args.kwargs['env']['DOCKER_CONFIG'], '/srv/dcms-prd/registry')

    def test_cli_reports_only_fixed_stage_and_never_exception_secrets(self):
        error = ValueError('synthetic-password')
        error.production_stage = 'migrations'
        with patch.object(prd.sys, 'argv', ['deploy-prd.py']), patch.object(prd, 'request', return_value=self.data), \
                patch.object(prd, 'execute', side_effect=error), patch.object(prd.signal, 'signal'), patch.object(prd.signal, 'alarm'):
            with self.assertRaises(SystemExit) as stopped:
                prd.main()
        self.assertIn('at migrations', str(stopped.exception))
        self.assertNotIn('synthetic-password', str(stopped.exception))

    def test_missing_migration_credentials_stop_before_maintenance(self):
        (self.root/'config/migration.env').unlink()
        with self.assertRaises(FileNotFoundError): self.exercise()
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_uat_migration_credentials_and_password_reuse_are_rejected(self):
        (self.root/'config/migration.env').write_text('DB_USERNAME=dcms_uat_migrate\nDB_PASSWORD=synthetic-migration\n')
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse(self.migrated)

    def test_runtime_password_cannot_be_reused_for_migrations(self):
        (self.root/'config/migration.env').write_text('DB_USERNAME=dcms_prd_migrate\nDB_PASSWORD=synthetic-runtime\n')
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_missing_qnap_credentials_stop_before_maintenance(self):
        (self.root/'config/qnap-readiness-key').unlink()
        with self.assertRaises(FileNotFoundError): self.exercise()
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_unchanged_schema_skips_migration_and_clears_recovery_state(self):
        self.manifest['migration_inventory'] = [ONE]
        self.release['manifest'] = json.dumps(self.manifest).encode()
        self.data['manifest_sha256'] = hashlib.sha256(self.release['manifest']).hexdigest()
        self.exercise()
        self.assertFalse(self.migrated)
        self.assertTrue(self.replaced)
        self.assertFalse((self.root/'config/recovery-required.json').exists())

    def test_concurrent_deployment_and_existing_recovery_state_are_refused(self):
        with patch.object(prd.fcntl, 'flock', side_effect=BlockingIOError()):
            with self.assertRaises(BlockingIOError): self.exercise()
        self.assertFalse(self.history.exists())
        (self.root/'config/recovery-required.json').write_text('{}')
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse(self.history.exists())

    def test_incompatible_live_migrations_are_refused_before_backup(self):
        self.schema_change = [{'migration': 'unknown_migration', 'batch': 1}]
        with self.assertRaises(ValueError): self.exercise()
        self.assertFalse((self.root/'backups/gha-789-1').exists())


if __name__ == '__main__':
    unittest.main()
