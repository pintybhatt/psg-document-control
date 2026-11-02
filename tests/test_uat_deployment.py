"""UAT trust boundaries and deployment orchestration; no daemon, SSH or network access."""
import copy
import datetime
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest
from unittest.mock import patch
import zipfile

import test_release_preparation as release_fixtures
import test_github_uat_auth as identity_fixtures
from test_release_preparation import load, SOURCE, IMAGE_ID


deploy = load('uat_deployment', 'deploy-uat.py')
prepare = load('uat_request', 'prepare-uat-request.py')
REGISTRY_MANIFEST = {'schemaVersion': 2, 'mediaType': 'application/vnd.oci.image.manifest.v1+json',
                     'config': {'mediaType': 'application/vnd.oci.image.config.v1+json', 'digest': IMAGE_ID, 'size': 100},
                     'layers': []}
RAW_MANIFEST = json.dumps(REGISTRY_MANIFEST)
OLD_RAW_MANIFEST = json.dumps(dict(REGISTRY_MANIFEST, annotations={'test.previous': 'true'}))
DIGEST = 'ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(RAW_MANIFEST.encode()).hexdigest()
OLD = 'ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(OLD_RAW_MANIFEST.encode()).hexdigest()


def release_fixture():
    fixture = release_fixtures.UatReleaseEvidenceTests()
    fixture.setUp()
    fixture.run['run_started_at'] = '2026-10-27T11:59:00Z'
    fixture.manifest['image'] = fixture.approval['image'] = DIGEST
    fixture.manifest_bytes = json.dumps(fixture.manifest).encode()
    fixture.approval['manifest_sha256'] = hashlib.sha256(fixture.manifest_bytes).hexdigest()
    return fixture


class RequestPreparationTests(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_RUN_ID='789', GITHUB_RUN_ATTEMPT='1',
                         ACTIONS_ID_TOKEN_REQUEST_URL='https://pipelines.actions.githubusercontent.com/synthetic?api-version=1',
                         ACTIONS_ID_TOKEN_REQUEST_TOKEN='synthetic-oidc-request-token')
        self.environment.start()
        self.addCleanup(self.environment.stop)
        fixture = release_fixture()
        self.release = {'manifest': fixture.manifest_bytes, 'run': fixture.run,
                        'jobs': fixture.jobs, 'artifacts': fixture.artifacts}

    def test_downloaded_publication_binds_manifest_hash_and_current_attempt(self):
        with patch.object(prepare.auth.GitHub, 'release', return_value=self.release), \
                patch.object(prepare.auth, 'read_https', return_value=b'{"value":"synthetic-oidc-token"}') as oidc:
            envelope = prepare.prepare('123', '456', 'deploy')
        request = envelope['request']
        self.assertEqual(request['image'], DIGEST)
        self.assertEqual(request['manifest_sha256'], hashlib.sha256(self.release['manifest']).hexdigest())
        self.assertEqual(request['release_run_attempt'], 1)
        self.assertIn('audience=', oidc.call_args.args[0])
        self.assertEqual(envelope['github_token'], 'synthetic-read-token')

    def test_forged_identity_and_rerun_during_download_refuse_request(self):
        for run, artifact, operation in (('123;id', '456', 'deploy'), ('123', '../456', 'deploy'), ('123', '456', 'production')):
            with self.subTest(run=run, artifact=artifact, operation=operation), self.assertRaises(ValueError):
                prepare.prepare(run, artifact, operation)
        with patch.object(prepare.auth.GitHub, 'release', side_effect=ValueError('stale release')), self.assertRaises(ValueError):
            prepare.prepare('123', '456', 'deploy')

    def test_artifact_paths_are_never_extracted_and_unexpected_members_are_refused(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as archive:
            archive.writestr('../../untrusted', 'synthetic')
        import urllib.error
        redirect = urllib.error.HTTPError('https://api.github.com/synthetic', 302, '',
                   {'Location': 'https://synthetic.blob.core.windows.net/a'}, io.BytesIO())
        with patch.object(prepare.auth, 'read_https', side_effect=[redirect, data.getvalue()]), self.assertRaises(ValueError):
            prepare.auth.GitHub('synthetic-read-token').manifest(456)

    def test_api_pagination_must_be_complete(self):
        api = prepare.auth.GitHub('synthetic-read-token')
        with patch.object(api, 'get', return_value={'total_count': 1, 'jobs': [{'id': 1}]}):
            self.assertEqual(api.collection('synthetic', 'jobs')['total_count'], 1)
        with patch.object(api, 'get', return_value={'total_count': 2, 'jobs': [{'id': 1}]}), self.assertRaises(ValueError):
            api.collection('synthetic', 'jobs')


class UatDeploymentTests(unittest.TestCase):
    def setUp(self):
        original_umask = os.umask(0o077)
        self.addCleanup(os.umask, original_umask)
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-uat-offline-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for folder in ('history', 'release/docker', 'config', 'registry'):
            (self.root/folder).mkdir(parents=True, exist_ok=True)
        self.fixture = release_fixture()
        self.current = dict(self.fixture.manifest, image=OLD)
        self.request = identity_fixtures.request(image=DIGEST, manifest_sha256=hashlib.sha256(self.fixture.manifest_bytes).hexdigest())
        self.envelope = {'request': self.request, 'oidc_token': 'synthetic-oidc-token', 'github_token': 'synthetic-read-token'}
        self.claims = identity_fixtures.claims_for(self.request)
        self.policy = dict(identity_fixtures.POLICY, health_url='https://uat.example.invalid:8444/up',
                           minimum_free_memory_mib=1, minimum_free_disk_bytes=1)
        self.release = {'manifest': self.fixture.manifest_bytes, 'run': self.fixture.run,
                        'jobs': self.fixture.jobs, 'artifacts': self.fixture.artifacts}
        from unittest.mock import Mock
        self.api = Mock()
        self.api.get.side_effect = self.github_metadata
        self.api.collection.return_value = {'jobs': [identity_fixtures.deployment_job()]}
        self.api.release.return_value = self.release
        for name, value in [('automation', self.policy), ('current', self.current)]:
            (self.root/'config'/(name+'.json')).write_text(json.dumps(value))
        (self.root/'config/compose.env').write_text('\n'.join([
            'DCMS_IMAGE='+OLD, 'DCMS_APP_ENV_FILE='+str(self.root/'config/app.env'),
            'DCMS_DB_PASSWORD_FILE='+str(self.root/'config/db-password'),
            'DCMS_DB_ROOT_PASSWORD_FILE='+str(self.root/'config/db-root-password')])+'\n')
        (self.root/'deployment.lock').touch()
        self.calls = []
        self.replaced = False
        self.failure = None
        self.image_store = 'classic'
        self.db_identity = 'a'*64
        self.volume = {'Type': 'volume', 'Name': deploy.PROJECT+'_documents',
                       'Destination': '/var/www/html/storage/app/document-vault'}
        self.patches = [patch.object(deploy, 'ROOT', self.root), patch.object(deploy.os, 'geteuid', return_value=0),
                        patch.object(deploy, 'protected', side_effect=lambda path, private=False: Path(path)),
                        patch.object(deploy.sys, 'argv', ['deploy-uat.py']),
                        patch.object(deploy.datetime, 'datetime', wraps=datetime.datetime)]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)
        deploy.datetime.datetime.now.return_value = self.fixture.now

    def github_metadata(self, path):
        if path == '':
            return {'id': 42, 'full_name': deploy.auth.REPOSITORY, 'private': True}
        if path == 'branches/main':
            return {'protected': True, 'commit': {'sha': 'd'*40}}
        if path == 'actions/runs/789':
            return identity_fixtures.deployment_run()
        if path == 'actions/runs/123':
            return self.fixture.run
        if path == 'actions/artifacts/456':
            return self.fixture.artifacts['artifacts'][0]
        if path.startswith('compare/'):
            return {'status': 'identical'}
        raise AssertionError('Unexpected synthetic GitHub path: '+path)

    def fake_run(self, command, timeout=180):
        self.calls.append(command)
        if 'compose' in command:
            args = command[command.index('compose')+10:]
            # Find operations independently of the temporary env-file path.
            for operation in ('ps', 'exec', 'run', 'up'):
                if operation in command:
                    args = command[command.index(operation):]
                    break
            if args[0] == 'ps':
                return ('b'*64 if args[-1] == 'app' else self.db_identity)+'\n'
            if args[0] == 'run':
                ran = ['synthetic'] if self.failure != 'pending' else []
                return json.dumps({'inventory': self.fixture.manifest['migration_inventory'], 'ran': ran})
            if args[0] == 'up':
                self.replaced = True
                if self.failure == 'replace':
                    raise ValueError('synthetic replacement failure')
                if self.failure == 'cancelled-workflow':
                    original = self.api.get.side_effect
                    self.api.get.side_effect = lambda path: dict(original(path), status='completed') if path == 'actions/runs/789' else original(path)
            if args[0] == 'exec' and 'apache2ctl' in args and self.failure == 'health':
                raise ValueError('synthetic health failure')
            if args[0] == 'exec' and 'is_file' in args[-1]:
                return 'up' if self.failure == 'maintenance' else 'down'
            return ''
        if command[:4] == deploy.DOCKER+['info']:
            return str(self.root)
        if 'container' in command:
            service = 'app' if command[-1] == 'b'*64 else 'db'
            image = DIGEST if self.replaced else OLD
            image_id = IMAGE_ID if self.image_store == 'classic' else image.split('@')[1]
            if self.replaced and self.failure == 'container-image':
                image_id = 'sha256:'+'f'*64
            return json.dumps([{'Id': command[-1], 'Image': image_id,
                                'Config': {'Image': image, 'Labels': {'com.docker.compose.project': deploy.PROJECT,
                                           'com.docker.compose.service': service,
                                           'org.opencontainers.image.revision': SOURCE,
                                           'org.opencontainers.image.source': 'https://github.com/'+deploy.evidence.REPOSITORY}},
                                'State': {'Running': True, 'StartedAt': 'synthetic-start', 'Health': {'Status': 'healthy'}},
                                'Mounts': [self.volume]}])
        if 'image' in command and 'inspect' in command:
            image_id = IMAGE_ID if self.image_store == 'classic' else command[-1].split('@')[1]
            return json.dumps([{'Id': image_id, 'RepoDigests': [command[-1]], 'Os': 'linux', 'Architecture': 'amd64',
                                'Config': {'Labels': {'org.opencontainers.image.revision': SOURCE,
                                  'org.opencontainers.image.source': 'https://github.com/'+deploy.evidence.REPOSITORY}}}])
        if command[3:7] == ['buildx', 'imagetools', 'inspect', '--raw']:
            if command[-1] == DIGEST and self.failure == 'oci':
                return RAW_MANIFEST+'\n'
            return OLD_RAW_MANIFEST if command[-1] == OLD else RAW_MANIFEST
        if command[0] == '/usr/bin/curl':
            if command[-1].endswith('/storage/private-check'):
                return '403'
            return '302' if self.failure == 'redirect' else '200'
        return ''

    def execute(self):
        with patch.object(deploy.Deployment, 'run', side_effect=self.fake_run), \
                patch.object(deploy.Deployment, 'storage'), \
                patch.object(deploy.auth, 'verify_oidc', return_value=self.claims), \
                patch.object(deploy.auth, 'GitHub', return_value=self.api):
            deploy.execute(self.envelope)

    def test_success_replaces_only_app_preserves_configuration_and_records_evidence(self):
        self.execute()

        replacement = next(call for call in self.calls if '--force-recreate' in call)
        self.assertEqual(replacement[-8:], ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--force-recreate', 'app'])
        self.assertEqual(replacement[-1], 'app')
        self.assertEqual([call for call in self.calls if call[:4] == deploy.DOCKER+['pull']], [deploy.DOCKER+['pull', DIGEST]])
        self.assertFalse(any(value in call for call in self.calls for value in ('migrate', 'migrate:fresh', 'migrate:rollback')))
        self.assertFalse(any(any(value in call for value in ('down', 'build', 'migrate', 'migrate:fresh', 'migrate:rollback', '--volumes'))
                             for call in self.calls if 'artisan' not in call))
        config = (self.root/'config/compose.env').read_text()
        self.assertIn('DCMS_IMAGE='+DIGEST+'\n', config)
        self.assertIn('DCMS_APP_ENV_FILE='+str(self.root/'config/app.env')+'\n', config)
        self.assertEqual(json.loads((self.root/'config/current.json').read_text())['image'], DIGEST)
        self.assertEqual(json.loads((self.root/'history/gha-789-1/result.json').read_text())['status'], 'deployed')
        self.assertTrue((self.root/'history/gha-789-1/previous-manifest.json').exists())
        self.assertFalse((self.root/'config/recovery-required.json').exists())
        for file in (self.root/'history/gha-789-1').iterdir():
            self.assertNotIn(b'synthetic-read-token', file.read_bytes())
            self.assertNotIn(b'synthetic-oidc-token', file.read_bytes())

    def test_containerd_store_validates_previous_candidate_and_replacement_images(self):
        self.image_store = 'containerd'

        self.execute()

        references = [call[-1] for call in self.calls if call[3:7] == ['buildx', 'imagetools', 'inspect', '--raw']]
        self.assertEqual(references, [OLD, DIGEST, DIGEST])
        self.assertEqual(json.loads((self.root/'history/gha-789-1/result.json').read_text())['status'], 'deployed')

    def test_workflow_revision_release_attempt_image_and_hash_tampering_fail_before_commands(self):
        original = copy.deepcopy(self.request)
        for key, value in [('image', OLD), ('manifest_sha256', 'f'*64), ('release_run_attempt', 2)]:
            with self.subTest(key=key):
                self.request.update(original)
                self.request[key] = value
                with self.assertRaises(ValueError):
                    self.execute()
                self.assertEqual(self.calls, [])
        self.request.update(original)
        self.claims['sha'] = 'f'*40
        with self.assertRaises(ValueError):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_disabled_policy_and_changed_schema_cannot_start_commands(self):
        self.policy['enabled'] = False
        (self.root/'config/automation.json').write_text(json.dumps(self.policy))
        with self.assertRaises(ValueError):
            self.execute()
        self.assertEqual(self.calls, [])
        self.policy['enabled'] = True
        (self.root/'config/automation.json').write_text(json.dumps(self.policy))
        changed = dict(self.current, migration_inventory=[])
        (self.root/'config/current.json').write_text(json.dumps(changed))
        with self.assertRaises(ValueError):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_held_host_lock_blocks_a_second_operator(self):
        import fcntl
        with (self.root/'deployment.lock').open('r+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                self.execute()
        self.assertEqual(self.calls, [])

    def test_missing_shared_maintenance_marker_refuses_replacement(self):
        self.failure = 'maintenance'
        with self.assertRaises(ValueError):
            self.execute()
        self.assertFalse(self.replaced)
        self.assertEqual(self.calls[-1][-4:], ['php', 'artisan', 'down', '--no-interaction'])

    def test_replayed_request_cannot_execute_a_second_time(self):
        self.execute()
        (self.root/'config/current.json').write_text(json.dumps(self.current))
        count = len(self.calls)
        with self.assertRaises(FileExistsError):
            self.execute()
        self.assertEqual(len(self.calls), count)

    def test_pending_schema_refuses_before_maintenance_or_replacement(self):
        self.failure = 'pending'
        with self.assertRaises(ValueError):
            self.execute()
        self.assertFalse(self.replaced)
        self.assertFalse(any('down' in call for call in self.calls))

    def test_oci_mismatch_refuses_before_maintenance_or_replacement(self):
        self.failure = 'oci'
        with self.assertRaisesRegex(ValueError, 'manifest digest mismatch'):
            self.execute()
        self.assertFalse(self.replaced)
        self.assertFalse(any('down' in call for call in self.calls))

    def test_replacement_health_and_redirect_failures_retain_previous_state_and_request_maintenance(self):
        original_metadata = self.api.get.side_effect
        for failure in ('replace', 'health', 'redirect', 'container-image', 'cancelled-workflow'):
            with self.subTest(failure=failure):
                self.failure = failure
                self.api.get.side_effect = original_metadata
                self.replaced = False
                self.calls = []
                history = self.root/'history/gha-789-1'
                if history.exists():
                    import shutil
                    shutil.rmtree(history)
                (self.root/'config/recovery-required.json').unlink(missing_ok=True)
                with self.assertRaises(ValueError):
                    self.execute()
                self.assertEqual(self.calls[-1][-4:], ['php', 'artisan', 'down', '--no-interaction'])
                self.assertEqual(json.loads((self.root/'config/current.json').read_text())['image'], OLD)
                self.assertEqual(json.loads((history/'result.json').read_text())['status'], 'failed')
                self.assertTrue((self.root/'config/recovery-required.json').exists())
                with self.assertRaisesRegex(ValueError, 'recovery inspection'):
                    self.execute()
                self.assertFalse(any('migrate:rollback' in call or 'migrate:fresh' in call for call in self.calls))

    def test_schema_changing_candidate_and_stale_release_are_refused_before_commands(self):
        original = dict(self.current, migration_inventory=[])
        (self.root/'config/current.json').write_text(json.dumps(original))
        with self.assertRaisesRegex(ValueError, 'controlled migration'):
            self.execute()
        self.assertEqual(self.calls, [])
        (self.root/'config/current.json').write_text(json.dumps(self.current))
        self.fixture.run['run_started_at'] = '2025-10-27T00:00:00Z'
        with self.assertRaisesRegex(ValueError, 'Stale release'):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_rollback_requires_recorded_previous_release_and_exact_live_schema(self):
        self.request['operation'] = 'rollback'
        self.request['rollback_compatibility_reference'] = 'COMPAT-123'
        with self.assertRaisesRegex(ValueError, 'recorded previous'):
            self.execute()
        (self.root/'config/previous.json').write_text(json.dumps(self.fixture.manifest))
        self.execute()
        self.assertTrue(self.replaced)
        runner = deploy.Deployment(self.root, self.root/'config/compose.env')
        with patch.object(runner, 'dc', return_value=json.dumps({'inventory': self.fixture.manifest['migration_inventory'],
                                                               'ran': ['synthetic', 'added']})), self.assertRaises(ValueError):
            runner.schema(self.fixture.manifest)



class ImageIdentityTests(unittest.TestCase):
    def setUp(self):
        self.runner = deploy.Deployment(Path('/unused'), Path('/unused'))
        self.manifest = {'image': DIGEST, 'tested_image_id': IMAGE_ID, 'source_commit': SOURCE, 'platform': 'linux/amd64'}
        self.local = {'Id': IMAGE_ID, 'RepoDigests': [DIGEST], 'Os': 'linux', 'Architecture': 'amd64',
                      'Config': {'Labels': {'org.opencontainers.image.revision': SOURCE,
                                'org.opencontainers.image.source': 'https://github.com/'+deploy.evidence.REPOSITORY}}}

    def test_classic_and_containerd_ids_require_the_same_authenticated_configuration_digest(self):
        for local_id in (IMAGE_ID, DIGEST.split('@')[1]):
            with self.subTest(local_id=local_id), \
                    patch.object(self.runner, 'run', side_effect=[RAW_MANIFEST, json.dumps([dict(self.local, Id=local_id)])]) as run:
                self.assertEqual(self.runner.image(self.manifest), local_id)
                self.assertEqual(run.call_args_list[0].args[0],
                                 deploy.DOCKER+['buildx', 'imagetools', 'inspect', '--raw', DIGEST])
        self.assertEqual(self.runner.environment['DOCKER_CONFIG'], '/srv/dcms-uat/registry')

    def test_config_mismatch_is_refused_even_when_docker_id_matches_published_digest(self):
        registry = copy.deepcopy(REGISTRY_MANIFEST)
        registry['config']['digest'] = 'sha256:'+'f'*64
        raw = json.dumps(registry)
        manifest = dict(self.manifest, image='ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(raw.encode()).hexdigest())
        with patch.object(self.runner, 'run', return_value=raw) as run, self.assertRaisesRegex(ValueError, 'configuration differs'):
            self.runner.image(manifest)
        self.assertEqual(run.call_count, 1)

    def test_raw_manifest_must_hash_to_the_approved_digest_without_normalization(self):
        for raw in (RAW_MANIFEST+'\n', json.dumps(REGISTRY_MANIFEST, indent=2), '{}'):
            with self.subTest(raw=raw), patch.object(self.runner, 'run', return_value=raw), \
                    self.assertRaisesRegex(ValueError, 'manifest digest mismatch'):
                self.runner.image(self.manifest)

    def test_unavailable_registry_or_buildx_refuses_without_local_id_fallback(self):
        for error in (OSError('synthetic missing Buildx'), subprocess.CalledProcessError(1, ['docker']),
                      subprocess.TimeoutExpired(['docker'], 180)):
            with self.subTest(error=type(error)), patch.object(self.runner, 'run', side_effect=error) as run, \
                    self.assertRaises(type(error)):
                self.runner.image(self.manifest)
            self.assertEqual(run.call_count, 1)

    def test_indexes_malformed_manifests_and_missing_config_descriptors_are_refused(self):
        invalid = [dict(REGISTRY_MANIFEST, mediaType='application/vnd.oci.image.index.v1+json', manifests=[]),
                   dict(REGISTRY_MANIFEST, manifests=[]), dict(REGISTRY_MANIFEST, schemaVersion=1),
                   dict(REGISTRY_MANIFEST, config={}),
                   dict(REGISTRY_MANIFEST, config={'mediaType': 'untrusted', 'digest': IMAGE_ID}),
                   dict(REGISTRY_MANIFEST, mediaType='untrusted')]
        for registry in invalid:
            raw = json.dumps(registry)
            manifest = dict(self.manifest, image='ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(raw.encode()).hexdigest())
            with self.subTest(registry=registry), patch.object(self.runner, 'run', return_value=raw), self.assertRaises(ValueError):
                self.runner.image(manifest)

    def test_docker_schema2_manifest_config_is_supported(self):
        registry = copy.deepcopy(REGISTRY_MANIFEST)
        registry['mediaType'] = 'application/vnd.docker.distribution.manifest.v2+json'
        registry['config']['mediaType'] = 'application/vnd.docker.container.image.v1+json'
        raw = json.dumps(registry)
        manifest = dict(self.manifest, image='ghcr.io/pintybhatt/psg-document-control@sha256:'+hashlib.sha256(raw.encode()).hexdigest())
        local = dict(self.local, RepoDigests=[manifest['image']])
        with patch.object(self.runner, 'run', side_effect=[raw, json.dumps([local])]):
            self.assertEqual(self.runner.image(manifest), IMAGE_ID)

    def test_mutable_or_unrelated_references_are_refused_before_registry_access(self):
        for image in ('ghcr.io/pintybhatt/psg-document-control:latest', DIGEST.replace('pintybhatt', 'untrusted')):
            with self.subTest(image=image), patch.object(self.runner, 'run') as run, self.assertRaises(ValueError):
                self.runner.image(dict(self.manifest, image=image))
            run.assert_not_called()

    def test_running_container_must_match_verified_local_id_reference_and_source(self):
        for local_id in (IMAGE_ID, DIGEST.split('@')[1]):
            container = {'Image': local_id, 'Config': {'Image': DIGEST, 'Labels': self.local['Config']['Labels']}}
            local = dict(self.local, Id=local_id)
            with self.subTest(local_id=local_id), patch.object(self.runner, 'run', side_effect=[RAW_MANIFEST, json.dumps([local])]):
                self.runner.running_image(container, self.manifest)
            invalid = [dict(container, Image='sha256:'+'f'*64),
                       dict(container, Image=DIGEST.split('@')[1] if local_id == IMAGE_ID else IMAGE_ID),
                       dict(container, Config=dict(container['Config'], Image=OLD)),
                       dict(container, Config=dict(container['Config'], Image='dcms:latest')),
                       dict(container, Config=dict(container['Config'], Labels={}))]
            for changed in invalid:
                with self.subTest(local_id=local_id, container=changed), \
                        patch.object(self.runner, 'run', side_effect=[RAW_MANIFEST, json.dumps([local])]), self.assertRaises(ValueError):
                    self.runner.running_image(changed, self.manifest)


class DeploymentBoundaryTests(unittest.TestCase):
    def test_schema_refusal_reports_controlled_migration_without_echoing_exception_contents(self):
        with patch.object(deploy, 'request_from', return_value={}), \
                patch.object(deploy, 'execute', side_effect=deploy.auth.SchemaReviewRequired('synthetic-secret-never-log')), \
                patch.object(deploy.signal, 'signal'), patch.object(deploy.signal, 'alarm'), self.assertRaises(SystemExit) as result:
            deploy.main()
        self.assertIn('separate controlled migration/recovery is required', str(result.exception))
        self.assertNotIn('synthetic-secret-never-log', str(result.exception))

    def test_workflow_keeps_dispatch_main_runner_and_least_privilege_without_image_or_request_inputs(self):
        repository = Path(__file__).resolve().parent.parent
        workflow = (repository/'.github/workflows/deploy-uat.yml').read_text()
        self.assertIn('  workflow_dispatch:', workflow)
        self.assertIn("github.ref == 'refs/heads/main'", workflow)
        self.assertIn("vars.ENABLE_UAT_DEPLOYMENT == 'true'", workflow)
        self.assertIn('runs-on: [self-hosted, Linux, X64, dcms-uat-runner]', workflow)
        self.assertIn('    environment: uat', workflow)
        self.assertIn('  contents: read\n  actions: read\n  id-token: write', workflow)
        self.assertNotIn('packages: write', workflow)
        self.assertNotIn('      image:', workflow)
        self.assertNotIn('      request_id:', workflow)
        self.assertNotIn('pull_request', workflow)
        self.assertIs(json.loads((repository/'docker/uat-automation.example.json').read_text())['enabled'], False)

    def test_runner_uses_pinned_host_identity_and_removes_temporary_credentials(self):
        repository = Path(__file__).resolve().parent.parent
        workflow = (repository/'.github/workflows/deploy-uat.yml').read_text()
        script = textwrap.dedent(workflow.split('        run: |\n')[-1].split('      - name:', 1)[0])
        with tempfile.TemporaryDirectory(prefix='dcms-fake-ssh-') as directory:
            root = Path(directory)
            executable = root/'ssh'
            executable.write_text('#!/usr/bin/python3\nimport json, os, sys\nfrom pathlib import Path\nsys.stdin.read()\nPath(os.environ["SSH_CALLS"]).write_text(json.dumps(sys.argv[1:]))\n')
            executable.chmod(0o700)
            (root/'uat-request.json').write_text('{}')
            environment = dict(os.environ, PATH=str(root)+':/usr/bin:/bin', RUNNER_TEMP=str(root),
                               UAT_SSH_KEY='synthetic-key', UAT_KNOWN_HOSTS='synthetic-host-key',
                               UAT_HOST='uat.example.invalid', UAT_SSH_PORT='2222', SSH_CALLS=str(root/'calls.json'))
            result = subprocess.run(['bash', '-euo', 'pipefail', '-c', script], env=environment,
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            calls = json.loads((root/'calls.json').read_text())
            self.assertIn('StrictHostKeyChecking=yes', calls)
            self.assertIn('GlobalKnownHostsFile=/dev/null', calls)
            self.assertIn('IdentityAgent=none', calls)
            self.assertEqual(calls[-2:], ['dcms-deploy@uat.example.invalid', 'deploy-dcms-uat'])
            self.assertFalse(list(root.glob('dcms-uat-ssh.*')))
            self.assertFalse((root/'uat-request.json').exists())
            self.assertNotIn('synthetic-key', result.stdout+result.stderr)
            environment['UAT_HOST'] = 'uat.example.invalid;id'
            (root/'calls.json').unlink()
            result = subprocess.run(['bash', '-euo', 'pipefail', '-c', script], env=environment,
                                    capture_output=True, text=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root/'calls.json').exists())

    def test_requests_cannot_inject_commands_paths_targets_or_additional_fields(self):
        valid = identity_fixtures.request(image=DIGEST)
        for changes in ({'request_id': '../CHG-123'}, {'image': 'dcms:latest'}, {'target': 'production'},
                        {'command': 'docker'}, {'release_run_id': True}):
            envelope = {'request': dict(valid, **changes), 'oidc_token': 'synthetic', 'github_token': 'synthetic-read-token'}
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                deploy.request_from(io.BytesIO(json.dumps(envelope).encode()))
        envelope = {'request': valid, 'oidc_token': 'synthetic', 'github_token': 'synthetic-read-token'}
        self.assertEqual(deploy.request_from(io.BytesIO(json.dumps(envelope).encode())), envelope)
        with self.assertRaises(ValueError):
            deploy.request_from(io.BytesIO(b' '*32769))

    def test_forced_command_rejects_shell_scp_sftp_and_arguments_without_sudo(self):
        script = Path(__file__).resolve().parent.parent/'docker/uat-ssh-command.sh'
        for command in ('', 'sh', 'scp -t /srv', 'internal-sftp', 'deploy-dcms-uat; id'):
            with self.subTest(command=command):
                result = subprocess.run(['/bin/sh', str(script)], env={'SSH_ORIGINAL_COMMAND': command},
                                        capture_output=True, timeout=5)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout+result.stderr, b'')
        result = subprocess.run(['/bin/sh', str(script), 'extra'], env={'SSH_ORIGINAL_COMMAND': 'deploy-dcms-uat'}, capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_custody_rejects_symlinks_nonroot_or_writable_paths(self):
        import stat
        for uid, mode in ((1000, stat.S_IFREG | 0o600), (0, stat.S_IFLNK | 0o777), (0, stat.S_IFREG | 0o622)):
            with self.subTest(uid=uid, mode=mode), \
                    patch.object(Path, 'lstat', return_value=os.stat_result((mode, 0, 0, 1, uid, 0, 0, 0, 0, 0))), \
                    self.assertRaises(ValueError):
                deploy.protected(Path('/srv/dcms-uat/config/approval.json'), True)

    def test_server_rejects_pulled_image_with_wrong_tested_identity_source_or_platform(self):
        runner = deploy.Deployment(Path('/unused'), Path('/unused'))
        manifest = {'image': DIGEST, 'tested_image_id': IMAGE_ID, 'source_commit': SOURCE, 'platform': 'linux/amd64'}
        original = {'Id': IMAGE_ID, 'RepoDigests': [DIGEST], 'Os': 'linux', 'Architecture': 'amd64',
                    'Config': {'Labels': {'org.opencontainers.image.revision': SOURCE,
                              'org.opencontainers.image.source': 'https://github.com/'+deploy.evidence.REPOSITORY}}}
        for field, value in [('Id', 'sha256:'+'f'*64), ('RepoDigests', []), ('Architecture', 'arm64'),
                             ('Config', {'Labels': {'org.opencontainers.image.revision': 'f'*40}})]:
            with self.subTest(field=field), patch.object(runner, 'run', side_effect=[RAW_MANIFEST, json.dumps([dict(original, **{field: value})])]), \
                    self.assertRaises(ValueError):
                runner.image(manifest)

    def test_foreign_or_missing_volumes_refuse_without_volume_creation(self):
        runner = deploy.Deployment(Path('/unused'), Path('/unused'))
        with patch.object(runner, 'run') as run, self.assertRaises(ValueError):
            runner.storage({'Mounts': []}, {'Mounts': []})
        run.assert_not_called()

    def test_database_restart_is_detected_after_replacement(self):
        runner = deploy.Deployment(Path('/unused'), Path('/unused'))
        before = {'Id': 'a'*64, 'State': {'StartedAt': 'before'}, 'Mounts': []}
        with patch.object(runner, 'container', return_value={'Id': 'a'*64, 'State': {'StartedAt': 'after'}, 'Mounts': []}), \
                self.assertRaises(ValueError):
            runner.invariant(before)


if __name__ == '__main__':
    unittest.main()
