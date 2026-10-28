"""Release policy tests: offline evidence, mocked GitHub metadata and fake Docker CLI only."""
import copy
import contextlib
import datetime
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest
from unittest.mock import patch
import urllib.error


ROOT = Path(__file__).resolve().parent.parent


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT/'docker'/filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


policy = load('ghcr_policy', 'ghcr-policy.py')
evidence = load('uat_release_evidence', 'verify-uat-release.py')
SOURCE = 'a'*40
IMAGE_ID = 'sha256:'+'b'*64
DIGEST = 'ghcr.io/pintybhatt/psg-document-control@sha256:'+'c'*64
TAG = 'v1.0.0-rc.1-'+SOURCE


class GhcrPolicyTests(unittest.TestCase):
    def test_low_level_header_errors_cannot_print_registry_credentials(self):
        output = io.StringIO()
        with patch.dict(os.environ, GH_TOKEN='synthetic-token'), patch('sys.argv', ['ghcr-policy.py']), \
                patch.object(policy, 'metadata', side_effect=ValueError('Invalid header: synthetic-token')), \
                contextlib.redirect_stderr(output), self.assertRaises(SystemExit):
            policy.main()
        self.assertNotIn('synthetic-token', output.getvalue())
        self.assertIn('publication refused', output.getvalue())

    def test_private_package_and_unused_tag_are_accepted(self):
        with patch.object(policy, 'metadata', side_effect=[{'name': 'psg-document-control', 'visibility': 'private'}, []]):
            self.assertIn('passed', policy.check('synthetic-token', TAG))

    def test_public_packages_and_existing_tags_are_refused(self):
        with patch.object(policy, 'metadata', return_value={'name': 'psg-document-control', 'visibility': 'public'}):
            with self.assertRaisesRegex(ValueError, 'private'):
                policy.check('synthetic-token')
        versions = [{'metadata': {'container': {'tags': [TAG]}}}]
        with patch.object(policy, 'metadata', side_effect=[{'name': 'psg-document-control', 'visibility': 'private'}, versions]):
            with self.assertRaisesRegex(ValueError, 'already exists'):
                policy.check('synthetic-token', TAG)

    def test_only_explicit_first_private_publication_can_accept_404(self):
        for code, allow, tag, accepted in [(404, True, TAG, True), (404, False, TAG, False),
                                           (404, True, None, False), (403, True, TAG, False), (500, True, TAG, False)]:
            with self.subTest(code=code, allow=allow, tag=tag):
                error = urllib.error.HTTPError(policy.PACKAGE, code, 'synthetic failure', {}, None)
                with patch.object(policy, 'metadata', side_effect=error):
                    if accepted:
                        self.assertIn('owner approval', policy.check('synthetic-token', tag, allow))
                    else:
                        with self.assertRaises(urllib.error.HTTPError):
                            policy.check('synthetic-token', tag, allow)

    def test_all_version_pages_are_checked_and_token_never_in_url(self):
        version = {'metadata': {'container': {'tags': []}}}
        with patch.object(policy, 'metadata', side_effect=[{'name': 'psg-document-control', 'visibility': 'private'},
                                                         [version]*100, [{'metadata': {'container': {'tags': [TAG]}}}]]) as call:
            with self.assertRaisesRegex(ValueError, 'already exists'):
                policy.check('synthetic-token', TAG)
            self.assertTrue(call.call_args.args[0].endswith('page=2'))
            self.assertNotIn('synthetic-token', call.call_args.args[0])

    def test_metadata_uses_fixed_https_authorization_header_and_refuses_redirects(self):
        response = io.StringIO('{"visibility":"private"}')
        with patch.object(policy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = response
            self.assertEqual(policy.metadata(policy.PACKAGE, 'synthetic-token'), {'visibility': 'private'})
            request = opener.return_value.open.call_args.args[0]
            self.assertEqual(request.full_url, policy.PACKAGE)
            self.assertEqual(request.get_header('Authorization'), 'Bearer synthetic-token')
        self.assertIsNone(policy.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.invalid'))


class UatReleaseEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.now = datetime.datetime(2026, 10, 27, 12, tzinfo=datetime.timezone.utc)
        self.manifest = {'image': DIGEST, 'source_commit': SOURCE, 'tested_image_id': IMAGE_ID,
                         'version': 'v1.0.0-rc.1', 'repository': evidence.REPOSITORY, 'platform': 'linux/amd64',
                         'migration_inventory': [{'path': 'database/migrations/synthetic.php', 'sha256': 'e'*64}],
                         'release_workflow_commit': 'd'*40, 'github_run_id': '123', 'github_run_attempt': '1'}
        self.manifest_bytes = json.dumps(self.manifest).encode()
        self.approval = {'target': 'uat', 'project': 'dcms-uat-xppl', 'decision': 'approved', 'image': DIGEST,
                         'source_commit': SOURCE, 'manifest_sha256': hashlib.sha256(self.manifest_bytes).hexdigest(),
                         'approval_reference': 'CHG-123', 'approved_by': 'owner', 'recovery_reference': 'REC-123',
                         'migration_review_reference': 'MIG-123', 'previous_image': None,
                         'approved_at': '2026-10-27T11:00:00+00:00', 'expires_at': '2026-10-27T13:00:00+00:00',
                         'release_run_id': 123, 'release_run_attempt': 1, 'release_artifact_id': 456}
        self.run = {'repository': {'full_name': evidence.REPOSITORY}, 'head_repository': {'full_name': evidence.REPOSITORY},
                    'path': '.github/workflows/release-image.yml', 'event': 'workflow_dispatch', 'head_branch': 'main',
                    'status': 'completed', 'conclusion': 'success', 'head_sha': 'd'*40, 'id': 123, 'run_attempt': 1}
        names = ['ci / Fast CI', 'ci / MySQL 8.4', 'ci / Image / smoke', 'ci / Full release validation', 'publish']
        self.jobs = {'total_count': 5, 'jobs': [{'name': name, 'run_id': 123, 'status': 'completed', 'conclusion': 'success',
                                                'steps': [{'name': 'Test that exact image in a disposable stack', 'conclusion': 'success'}]}
                                               for name in names]}
        self.artifacts = {'artifacts': [{'name': 'release-manifest-v1.0.0-rc.1-'+SOURCE, 'id': 456,
                                        'expired': False, 'workflow_run': {'id': 123}}]}

    def verify(self):
        return evidence.verify(self.manifest_bytes, self.approval, self.run, self.jobs, self.artifacts, self.now)

    def test_matching_successful_release_and_explicit_uat_approval_pass(self):
        self.assertEqual(self.verify(), DIGEST)

    def test_production_wrong_source_digest_and_missing_recovery_are_refused(self):
        original = copy.deepcopy(self.approval)
        for key, value in [('target', 'production'), ('project', 'netbox'), ('source_commit', 'f'*40),
                           ('image', DIGEST[:-1]+'f'), ('manifest_sha256', 'f'*64), ('decision', 'pending'),
                           ('recovery_reference', ''), ('previous_image', 'dcms:latest'), ('release_run_attempt', 2)]:
            with self.subTest(key=key):
                self.approval = dict(original, **{key: value})
                with self.assertRaises(ValueError):
                    self.verify()

    def test_expired_future_and_naive_approval_times_are_refused(self):
        for now in (self.now+datetime.timedelta(days=1), self.now-datetime.timedelta(days=1)):
            with self.subTest(now=now), self.assertRaises(ValueError):
                evidence.verify(self.manifest_bytes, self.approval, self.run, self.jobs, self.artifacts, now)
        self.approval['expires_at'] = '2026-10-27T13:00:00'
        with self.assertRaises(ValueError):
            self.verify()

    def test_untrusted_or_incomplete_release_runs_are_refused(self):
        original = copy.deepcopy(self.run)
        for key, value in [('event', 'pull_request'), ('head_branch', 'feature'), ('conclusion', 'failure'),
                           ('head_sha', 'f'*40), ('run_attempt', 2), ('path', '.github/workflows/ci.yml'),
                           ('repository', {'full_name': 'other/application'})]:
            with self.subTest(key=key):
                self.run = dict(original, **{key: value})
                with self.assertRaises(ValueError):
                    self.verify()

    def test_every_full_gate_and_exact_smoke_must_succeed(self):
        original = copy.deepcopy(self.jobs)
        for index in range(len(original['jobs'])):
            for result in ('skipped', 'failure', 'cancelled'):
                with self.subTest(index=index, result=result):
                    self.jobs = copy.deepcopy(original)
                    self.jobs['jobs'][index]['conclusion'] = result
                    with self.assertRaises(ValueError):
                        self.verify()
        self.jobs = copy.deepcopy(original)
        self.jobs['jobs'][2]['steps'][0]['conclusion'] = 'skipped'
        with self.assertRaises(ValueError):
            self.verify()
        self.jobs = copy.deepcopy(original)
        self.jobs['jobs'].pop()
        with self.assertRaises(ValueError):
            self.verify()

    def test_expired_different_or_ambiguous_artifacts_are_refused(self):
        original = copy.deepcopy(self.artifacts)
        for key, value in [('expired', True), ('id', 999), ('workflow_run', {'id': 999}), ('name', 'unrelated')]:
            with self.subTest(key=key):
                self.artifacts = copy.deepcopy(original)
                self.artifacts['artifacts'][0][key] = value
                with self.assertRaises(ValueError):
                    self.verify()
        self.artifacts = copy.deepcopy(original)
        self.artifacts['artifacts'].append(copy.deepcopy(self.artifacts['artifacts'][0]))
        with self.assertRaises(ValueError):
            self.verify()

    def test_cli_requires_owner_only_approval_and_exposes_no_input_content_on_failure(self):
        with tempfile.TemporaryDirectory(prefix='dcms-offline-release-cli-') as directory:
            path = Path(directory)
            approval = copy.deepcopy(self.approval)
            now = datetime.datetime.now(datetime.timezone.utc)
            approval['approved_at'] = (now-datetime.timedelta(minutes=1)).isoformat()
            approval['expires_at'] = (now+datetime.timedelta(minutes=1)).isoformat()
            inputs = {'manifest': self.manifest, 'approval': approval, 'run': self.run,
                      'jobs': self.jobs, 'artifacts': self.artifacts}
            command = ['python3', '-B', str(ROOT/'docker/verify-uat-release.py')]
            for name, data in inputs.items():
                file = path/(name+'.json')
                file.write_bytes(self.manifest_bytes if name == 'manifest' else json.dumps(data).encode())
                file.chmod(0o600)
                command += ['--'+name, str(file)]
            result = subprocess.run(command, text=True, capture_output=True, check=True)
            self.assertIn('No deployment performed', result.stdout)
            (path/'approval.json').chmod(0o644)
            result = subprocess.run(command, text=True, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('CHG-123', result.stdout+result.stderr)


class PublishingHandoffTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-release-policy-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        (self.directory/'validated-image').mkdir()
        (self.directory/'docker').mkdir()
        (self.directory/'bin').mkdir()
        (self.directory/'docker/ghcr-policy.py').write_text('print("Synthetic policy accepted")\n')
        artifact = self.directory/'validated-image'
        (artifact/'image.tar').write_bytes(b'synthetic archive, never loaded by a real Docker CLI')
        digest = hashlib.sha256((artifact/'image.tar').read_bytes()).hexdigest()
        (artifact/'image.tar.sha256').write_text(digest+'  image.tar\n')
        (artifact/'source-commit.txt').write_text(SOURCE+'\n')
        (artifact/'image-id.txt').write_text(IMAGE_ID+'\n')
        executable = self.directory/'bin/docker'
        executable.write_text('''#!/usr/bin/python3
import json, os, sys
from pathlib import Path
args = sys.argv[1:]
with Path('calls.jsonl').open('a') as log: log.write(json.dumps(args)+'\\n')
if args[0] == 'login': sys.stdin.read()
if args[:2] == ['image', 'inspect']:
    template = args[3]
    if template == '{{.Id}}':
        print(os.environ.get('REMOTE_ID', os.environ['EXPECTED_ID']) if '@sha256:' in args[-1] else os.environ['EXPECTED_ID'])
    elif 'image.revision' in template: print(os.environ.get('REVISION', os.environ['SOURCE']))
    else: print('https://github.com/pintybhatt/psg-document-control')
if args[:3] == ['buildx', 'imagetools', 'inspect']: print(json.dumps({'digest': 'sha256:'+'c'*64}))
''')
        executable.chmod(0o755)
        workflow = (ROOT/'.github/workflows/release-image.yml').read_text()
        section = workflow.split('      - name: Publish the exact image already built and smoke tested\n')[1]
        self.script = textwrap.dedent(section.split('        run: |\n', 1)[1].split('      - name:', 1)[0])

    def run_publish(self, **changes):
        environment = dict(os.environ, PATH=str(self.directory/'bin')+':/usr/bin:/bin', GH_TOKEN='synthetic-token',
                           SOURCE=SOURCE, VERSION='v1.0.0-rc.1', ALLOW_INITIAL='false', GITHUB_ACTOR='synthetic',
                           EXPECTED_ID=IMAGE_ID, **changes)
        result = subprocess.run(['bash', '-euo', 'pipefail', '-c', self.script], cwd=self.directory,
                                env=environment, text=True, capture_output=True, timeout=15)
        calls = [json.loads(line) for line in (self.directory/'calls.jsonl').read_text().splitlines()] if (self.directory/'calls.jsonl').exists() else []
        self.assertNotIn('synthetic-token', result.stdout+result.stderr)
        return result, calls

    def test_verified_image_is_tagged_pushed_and_pulled_by_digest_without_rebuild(self):
        result, calls = self.run_publish()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(['tag', IMAGE_ID, 'ghcr.io/pintybhatt/psg-document-control:'+TAG], calls)
        self.assertIn(['pull', DIGEST], calls)
        self.assertFalse(any('build' in args or 'compose' in args for args in calls))

    def test_corrupt_archive_refuses_before_load_login_or_push(self):
        (self.directory/'validated-image/image.tar').write_bytes(b'corrupted')
        result, calls = self.run_publish()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])

    def test_source_mismatch_refuses_before_registry_login(self):
        result, calls = self.run_publish(REVISION='f'*40)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any(args[0] in ('push', 'login') for args in calls))

    def test_pulled_digest_must_identify_the_tested_image(self):
        result, calls = self.run_publish(REMOTE_ID='sha256:'+'f'*64)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(['pull', DIGEST], calls)


class ReleaseConfigurationTests(unittest.TestCase):
    def test_publication_requires_explicit_switch_main_manual_and_full_validation(self):
        workflow = (ROOT/'.github/workflows/release-image.yml').read_text()
        publish = workflow.split('  publish:\n', 1)[1]
        self.assertRegex(publish, r'(?m)^    needs: ci$')
        self.assertRegex(publish, r"\n    if: .*workflow_dispatch.*refs/heads/main.*pintybhatt/psg-document-control.*ENABLE_IMAGE_PUBLISHING")
        self.assertIn("vars.ENABLE_IMAGE_PUBLISHING == 'true'", publish)
        self.assertEqual(workflow.count('packages: write'), 1)
        self.assertIn('    environment: release-image\n', publish)
        self.assertIn('      retain_image: true\n', workflow)
        self.assertIn('    needs: identity\n', workflow)
        self.assertIn('  contents: read\n', workflow)
        for path in (ROOT/'.github/workflows').glob('*.yml'):
            self.assertNotIn('pull_request_target:', path.read_text())
            if path.name != 'release-image.yml':
                self.assertNotRegex(path.read_text(), r'packages:\s*write')
        self.assertRegex(publish, r'\n      packages: write\n')

    def test_manifest_records_tested_identity_run_and_complete_migration_inventory(self):
        source = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
        environment = dict(os.environ, GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='1',
                           GITHUB_REPOSITORY=evidence.REPOSITORY, GITHUB_SHA=source, APPROVAL_REFERENCE='CHG-123')
        command = ['python3', '-B', str(ROOT/'docker/release-manifest.py'), '--image', DIGEST, '--source', source,
                   '--baseline', source, '--version', 'v1.0.0-rc.1', '--tested-image-id', IMAGE_ID]
        result = subprocess.run(command, cwd=ROOT, env=environment, text=True, capture_output=True, check=True)
        manifest = json.loads(result.stdout)
        self.assertEqual(manifest['tested_image_id'], IMAGE_ID)
        self.assertEqual(manifest['source_commit'], source)
        self.assertEqual(manifest['github_run_id'], '123')
        self.assertEqual(manifest['repository'], evidence.REPOSITORY)
        expected = sorted(str(path.relative_to(ROOT)) for path in (ROOT/'database/migrations').glob('*.php'))
        self.assertEqual([row['path'] for row in manifest['migration_inventory']], expected)
        self.assertTrue(all(re.fullmatch(r'[a-f0-9]{64}', row['sha256']) for row in manifest['migration_inventory']))
        self.assertEqual(manifest['migration_changes'], [])
        self.assertIsNone(manifest['production_authorization'])
        self.assertNotEqual(subprocess.run(command[:-1]+['dcms:latest'], cwd=ROOT, env=environment,
                                          text=True, capture_output=True).returncode, 0)


if __name__ == '__main__':
    unittest.main()
