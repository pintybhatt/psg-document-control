"""Production ref/evidence and workflow tests. GitHub/SSH are synthetic; no server access."""
import contextlib
import copy
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
from unittest.mock import Mock, patch
import urllib.error
import urllib.parse

from test_release_preparation import load, automatic_release, SOURCE, DIGEST

selector = load('prd_selection', 'select-prd-release.py')
ROOT = Path(__file__).resolve().parent.parent
WORKFLOW = ROOT/'.github/workflows/deploy-prd.yml'


def environment():
    return {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
            'GITHUB_REPOSITORY': 'pintybhatt/psg-document-control', 'GITHUB_SHA': SOURCE,
            'GITHUB_RUN_ID': '789', 'GITHUB_RUN_ATTEMPT': '2', 'CONFIRM_DEPLOY': 'DEPLOY_PRD',
            'WORKFLOW_REF': 'pintybhatt/psg-document-control/.github/workflows/deploy-prd.yml@refs/heads/main',
            'WORKFLOW_SHA': SOURCE, 'DEPLOY_REF': 'main', 'GH_TOKEN': 'synthetic-read-token'}


class GitHub:
    def __init__(self):
        self.release_data = automatic_release()
        self.main = SOURCE
        self.workflow_sha = SOURCE
        self.private, self.protected = True, True
        self.tag = {'ref': 'refs/tags/v1.0.0', 'object': {'type': 'commit', 'sha': SOURCE}}
        self.tags = {}
        self.calls = []
        self.histories = {'ci.yml': [self.release_data['run']], 'release-image.yml': []}
        self.moved = False

    def get(self, path):
        self.calls.append(path)
        if path == '': return {'full_name': 'pintybhatt/psg-document-control', 'private': self.private}
        if path == 'branches/main': return {'protected': self.protected, 'commit': {'sha': self.main}}
        if path == 'actions/runs/789':
            return {'id': 789, 'run_attempt': 2, 'path': '.github/workflows/deploy-prd.yml', 'name': 'Deploy DCMS PRD',
                    'head_branch': 'main', 'head_sha': self.workflow_sha, 'event': 'workflow_dispatch', 'status': 'in_progress',
                    'repository': {'full_name': 'pintybhatt/psg-document-control'},
                    'head_repository': {'full_name': 'pintybhatt/psg-document-control'}}
        if path.startswith('git/ref/tags/'):
            if self.moved and self.calls.count(path) > 1:
                return dict(self.tag, object={'type': 'commit', 'sha': 'f'*40})
            return copy.deepcopy(self.tag)
        if path.startswith('git/tags/'): return copy.deepcopy(self.tags[path.removeprefix('git/tags/')])
        if path.startswith('git/commits/'): return {'sha': path.removeprefix('git/commits/')}
        if path.startswith('compare/'):
            return {'status': 'ahead', 'merge_base_commit': {'sha': path.removeprefix('compare/').split('...')[0]}}
        if path.startswith('actions/workflows/'):
            return {'total_count': len(self.histories[path.split('/')[2]]), 'workflow_runs': copy.deepcopy(self.histories[path.split('/')[2]])}
        if path == 'actions/artifacts/456': return copy.deepcopy(self.release_data['artifacts']['artifacts'][0])
        raise AssertionError('Unexpected read-only API path: '+path)

    def collection(self, path, key):
        self.calls.append(path)
        assert path == 'actions/runs/123/artifacts' and key == 'artifacts'
        return copy.deepcopy(self.release_data['artifacts'])

    def release(self, run_id, artifact_id):
        self.calls.append(('release', run_id, artifact_id))
        assert (run_id, artifact_id) == (123, 456)
        return copy.deepcopy(self.release_data)

    def release_current(self, run):
        self.calls.append(('release_current', run['id'], run['run_attempt']))


class ProductionSelectionTests(unittest.TestCase):
    def setUp(self):
        self.api = GitHub()
        self.environment = environment()

    def select(self, reference='main'):
        return selector.deployment_request(self.api, reference, self.environment)

    def test_main_builds_exact_eight_field_request_accepted_by_the_server(self):
        self.api.private = False
        data = self.select()
        self.assertEqual(data, {'deployment_run_id': 789, 'deployment_run_attempt': 2, 'release_run_id': 123,
                                'release_run_attempt': 1, 'release_artifact_id': 456, 'image': DIGEST,
                                'source_commit': SOURCE, 'manifest_sha256': hashlib.sha256(self.api.release_data['manifest']).hexdigest()})
        self.assertEqual(set(data), selector.runtime.REQUEST_KEYS)
        self.assertEqual(selector.runtime.request(io.BytesIO(json.dumps(data).encode())), data)
        current = json.loads(self.api.release_data['manifest'])
        current.update(image='ghcr.io/pintybhatt/psg-document-control@sha256:'+'f'*64, source_commit='d'*40)
        with patch.object(selector.runtime, 'protected', return_value=Mock(read_text=lambda: 'synthetic-server-token')), \
                patch.object(selector.runtime.shared.auth, 'GitHub', return_value=self.api):
            _, _, manifest = selector.runtime.authorize(data, current)
        self.assertEqual(manifest['source_commit'], SOURCE)
        self.assertEqual(selector.runtime.WORKFLOW, '.github/workflows/deploy-prd.yml')

    def test_lightweight_tag_explicit_tag_and_full_sha_resolve_exact_older_main_commit(self):
        self.api.main = self.api.workflow_sha = 'd'*40
        self.environment.update(GITHUB_SHA='d'*40, WORKFLOW_SHA='d'*40)
        for reference in ('v1.0.0', 'refs/tags/v1.0.0', SOURCE, SOURCE.upper()):
            with self.subTest(reference=reference):
                self.assertEqual(self.select(reference)['source_commit'], SOURCE)
        self.assertNotIn('git/ref/heads/v1.0.0', self.api.calls)

    def test_annotated_and_nested_tags_are_peeled_to_commits(self):
        self.api.tag['object'] = {'type': 'tag', 'sha': 'b'*40}
        self.api.tags = {'b'*40: {'sha': 'b'*40, 'object': {'type': 'tag', 'sha': 'c'*40}},
                         'c'*40: {'sha': 'c'*40, 'object': {'type': 'commit', 'sha': SOURCE}}}
        self.assertEqual(self.select('v1.0.0')['source_commit'], SOURCE)
        self.assertIn('git/tags/'+'b'*40, self.api.calls)

    def test_branches_short_shas_repository_refs_and_shell_syntax_are_rejected(self):
        for reference in ('feature', 'refs/heads/main', 'refs/heads/feature', 'abc1234', 'deadbeef', 'owner/repo@main',
                          'https://github.com/owner/repo', 'main~1', 'main^{commit}', 'main;id', '$(id)', '--help',
                          ' v1.0.0', 'v1.0.0\n', 'tags//bad', 'refs/tags/../main', 'x.lock', ''):
            with self.subTest(reference=reference), self.assertRaises(ValueError): self.select(reference)
        # A real branch with no exact tag is never resolved through the commits/branch endpoint.
        with patch.object(self.api, 'get', side_effect=urllib.error.HTTPError('synthetic', 404, 'not found', {}, None)):
            with self.assertRaisesRegex(selector.SelectionError, 'reference'):
                selector.resolve(self.api, 'feature', SOURCE)

    def test_tag_cycles_non_commit_objects_prefix_matches_and_changed_tags_are_rejected(self):
        for item in ({'type': 'tree', 'sha': SOURCE}, {'type': 'blob', 'sha': SOURCE}, {'type': 'tag', 'sha': 'b'*40}):
            self.api.tag['object'] = item
            self.api.tags['b'*40] = {'sha': 'b'*40, 'object': item}
            with self.subTest(item=item), self.assertRaises(ValueError): self.select('v1.0.0')
        self.api.tag = {'ref': 'refs/tags/v1.0.0-other', 'object': {'type': 'commit', 'sha': SOURCE}}
        with self.assertRaises(ValueError): self.select('v1.0.0')
        self.api.tag['ref'] = 'refs/tags/v1.0.0'
        self.api.calls.clear()
        self.api.moved = True
        with self.assertRaisesRegex(selector.SelectionError, 'moved'): self.select('v1.0.0')

    def test_non_main_ancestry_and_non_protected_or_stale_workflow_definitions_are_refused(self):
        original = self.api.get
        for response in ({'status': 'behind', 'merge_base_commit': {'sha': SOURCE}},
                         {'status': 'diverged', 'merge_base_commit': {'sha': 'b'*40}},
                         {'status': 'ahead', 'merge_base_commit': {'sha': 'b'*40}}):
            with patch.object(self.api, 'get', side_effect=lambda path: response if path.startswith('compare/') else original(path)):
                with self.assertRaisesRegex(selector.SelectionError, 'ancestry'): self.select()
        for attribute, value in (('protected', False), ('main', 'f'*40), ('workflow_sha', 'f'*40)):
            previous = getattr(self.api, attribute)
            setattr(self.api, attribute, value)
            with self.subTest(attribute=attribute), self.assertRaisesRegex(selector.SelectionError, 'workflow'): self.select()
            setattr(self.api, attribute, previous)

    def test_missing_expired_failed_and_ambiguous_publications_never_select_an_image(self):
        original = copy.deepcopy(self.api.release_data)
        for failure in ('missing', 'expired', 'failed', 'pending', 'ambiguous'):
            self.api.release_data = copy.deepcopy(original)
            run = self.api.release_data['run']
            artifacts = self.api.release_data['artifacts']['artifacts']
            if failure == 'missing': artifacts.clear()
            if failure == 'expired': artifacts[0]['expired'] = True
            if failure == 'failed': run['conclusion'] = 'failure'
            if failure == 'pending': run['status'] = 'in_progress'
            if failure == 'ambiguous': artifacts.append(copy.deepcopy(artifacts[0]))
            self.api.release_data['artifacts']['total_count'] = len(artifacts)
            self.api.histories['ci.yml'] = [run]
            with self.subTest(failure=failure), self.assertRaises(selector.SelectionError): self.select()

    def test_verifier_rejects_wrong_source_repo_platform_digest_inventory_and_attempt(self):
        for failure in ('source', 'repository', 'platform', 'digest', 'inventory', 'attempt', 'failed_job', 'missing_shard', 'smoke'):
            api = GitHub()
            manifest = json.loads(api.release_data['manifest'])
            if failure == 'source': manifest['source_commit'] = 'f'*40
            if failure == 'repository': manifest['repository'] = 'untrusted/fork'
            if failure == 'platform': manifest['platform'] = 'linux/arm64'
            if failure == 'digest': manifest['image'] = 'ghcr.io/other/app@sha256:'+'c'*64
            if failure == 'inventory': manifest['migration_inventory'][0]['path'] = '../migration.php'
            if failure == 'attempt': manifest['github_run_attempt'] = '2'
            if failure == 'failed_job': api.release_data['jobs']['jobs'][0]['conclusion'] = 'failure'
            if failure == 'missing_shard': api.release_data['jobs']['jobs'].pop()
            if failure == 'smoke': api.release_data['jobs']['jobs'][3]['steps'][0]['conclusion'] = 'skipped'
            api.release_data['manifest'] = json.dumps(manifest).encode()
            with self.subTest(failure=failure), self.assertRaises(ValueError):
                selector.deployment_request(api, 'main', self.environment)

    def test_existing_legacy_publication_is_verified_without_a_new_publication_or_age_gate(self):
        self.api.histories['ci.yml'] = []
        release = self.api.release_data
        manifest = json.loads(release['manifest'])
        manifest['version'] = 'v1.0.0-rc.2'
        release['manifest'] = json.dumps(manifest).encode()
        release['run'].update(path='.github/workflows/release-image.yml', event='workflow_dispatch')
        self.api.histories['release-image.yml'] = [release['run']]
        release['artifacts']['artifacts'][0]['name'] = 'release-manifest-v1.0.0-rc.2-'+SOURCE
        release['jobs'] = {'total_count': 5, 'jobs': [{'name': name, 'run_id': 123, 'status': 'completed', 'conclusion': 'success',
                            'steps': [{'name': 'Test that exact image in a disposable stack', 'conclusion': 'success'}]}
                            for name in ('ci / Fast CI', 'ci / Image / smoke', 'ci / Full release validation', 'publish', 'ci / MySQL 8.4')]}
        self.assertEqual(self.select()['source_commit'], SOURCE)

    def test_expired_newest_publication_cannot_fall_back_to_older_evidence(self):
        newest = dict(self.api.release_data['run'], id=124)
        self.api.histories['ci.yml'].append(newest)
        original = self.api.collection
        def artifacts(path, key):
            if path == 'actions/runs/124/artifacts':
                result = copy.deepcopy(self.api.release_data['artifacts'])
                result['artifacts'][0]['expired'] = True
                return result
            return original(path, key)
        with patch.object(self.api, 'collection', side_effect=artifacts):
            with self.assertRaisesRegex(selector.SelectionError, 'expired'): self.select()
        self.assertFalse(any(isinstance(call, tuple) and call[0] == 'release' for call in self.api.calls))

    def test_requested_commit_without_publication_never_substitutes_published_main(self):
        self.api.histories['ci.yml'] = []
        self.api.histories['release-image.yml'] = [dict(self.api.release_data['run'],
                                                      path='.github/workflows/release-image.yml', event='workflow_dispatch')]
        with self.assertRaisesRegex(selector.SelectionError, 'missing'): self.select('f'*40)

    def test_valid_evidence_for_a_different_source_is_rejected_by_exact_source_binding(self):
        discovered = copy.deepcopy(self.api.release_data['artifacts'])
        self.api.histories['ci.yml'] = copy.deepcopy(self.api.histories['ci.yml'])
        manifest = json.loads(self.api.release_data['manifest'])
        manifest.update(source_commit='f'*40, release_workflow_commit='f'*40)
        self.api.release_data['manifest'] = json.dumps(manifest).encode()
        self.api.release_data['run']['head_sha'] = 'f'*40
        artifact = self.api.release_data['artifacts']['artifacts'][0]
        artifact['name'] = artifact['name'].replace(SOURCE, 'f'*40)
        artifact['workflow_run']['head_sha'] = 'f'*40
        with patch.object(self.api, 'collection', return_value=discovered):
            with self.assertRaisesRegex(selector.SelectionError, 'binding'): self.select()

    def test_rerun_during_selection_and_late_artifact_expiry_are_rejected(self):
        self.api.histories['ci.yml'] = copy.deepcopy(self.api.histories['ci.yml'])
        self.api.release_data = automatic_release(attempt=2)
        with self.assertRaisesRegex(selector.SelectionError, 'binding'): self.select()
        self.api = GitHub()
        original = self.api.get
        with patch.object(self.api, 'get', side_effect=lambda path: {'id': 456, 'expired': True} if path == 'actions/artifacts/456' else original(path)):
            with self.assertRaisesRegex(selector.SelectionError, 'expired'): self.select()

    def test_pagination_is_read_only_bounded_and_rejects_changed_or_partial_results(self):
        rows = [{'id': i} for i in range(1, 102)]
        api = Mock()
        api.get.side_effect = [{'total_count': 101, 'workflow_runs': rows[:100]}, {'total_count': 101, 'workflow_runs': rows[100:]}]
        self.assertEqual(selector.runs(api, 'ci.yml', head_sha=SOURCE)[0]['id'], 101)
        self.assertIn('page=2', api.get.call_args.args[0])
        api.get.side_effect = [{'total_count': 101, 'workflow_runs': rows[:100]}, {'total_count': 102, 'workflow_runs': rows[100:]}]
        with self.assertRaises(selector.SelectionError): selector.runs(api, 'ci.yml')
        api.get.side_effect = [{'total_count': 102, 'workflow_runs': []}]
        with self.assertRaises(selector.SelectionError): selector.runs(api, 'ci.yml')
        api.get.side_effect = None
        api.get.return_value = {'total_count': 1001, 'workflow_runs': rows[:100]}
        with self.assertRaises(selector.SelectionError): selector.runs(api, 'ci.yml')

    def test_cli_writes_private_request_only_after_verification_and_sanitizes_failure(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary)/'prd-request.json'
            env = dict(self.environment, RUNNER_TEMP=temporary)
            with patch.dict(os.environ, env), patch.object(selector.sys, 'argv', ['select-prd-release.py']), \
                    patch.object(selector.auth, 'GitHub', return_value=self.api), contextlib.redirect_stdout(io.StringIO()):
                selector.main()
            self.assertEqual(json.loads(path.read_text())['source_commit'], SOURCE)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with patch.dict(os.environ, env), patch.object(selector.sys, 'argv', ['select-prd-release.py']), \
                    patch.object(selector.auth, 'GitHub', side_effect=ValueError('synthetic-read-token')), self.assertRaises(SystemExit) as error:
                selector.main()
            self.assertNotIn('synthetic-read-token', str(error.exception))
            self.assertFalse(path.exists())


class ProductionWorkflowTests(unittest.TestCase):
    def workflow(self):
        return WORKFLOW.read_text()

    def script(self, name):
        block = self.workflow().split('      - name: '+name+'\n', 1)[1]
        return textwrap.dedent(block.split('        run: |\n', 1)[1].split('      - ', 1)[0])

    def shell(self, name, env, cwd=None):
        return subprocess.run(['bash', '-euo', 'pipefail', '-c', self.script(name)], env=dict(os.environ, **env),
                              cwd=cwd, text=True, capture_output=True, timeout=10)

    def test_exactly_two_inputs_default_main_trusted_checkout_and_read_only_permissions(self):
        workflow = self.workflow()
        inputs = workflow.split('    inputs:\n', 1)[1].split('permissions:\n', 1)[0]
        self.assertEqual(re.findall(r'^      ([a-z_]+):$', inputs, re.M), ['confirm_deploy', 'ref'])
        self.assertEqual(inputs.count('        required: true'), 2)
        self.assertEqual(inputs.count('        type: string'), 2)
        self.assertIn('        default: main\n', inputs)
        self.assertIn('name: Deploy DCMS PRD\n', workflow)
        self.assertIn('    environment: prd\n', workflow)
        self.assertIn('    runs-on: [self-hosted, Linux, X64, dcms-prd-runner]', workflow)
        self.assertIn('  group: dcms-prd-xppl-deployment', workflow)
        self.assertIn('  cancel-in-progress: false', workflow)
        self.assertIn('          ref: ${{ github.sha }}', workflow)
        self.assertIn('          persist-credentials: false', workflow)
        self.assertEqual(workflow.count('actions/checkout@'), 1)
        self.assertIn('  contents: read\n  actions: read', workflow)
        for forbidden in ('inputs.image', 'inputs.host', 'inputs.command', 'inputs.project', 'write', 'id-token:',
                          'deploy-uat', 'UAT_SSH', 'UAT_KNOWN', 'netbox', 'docker build', 'docker push', 'workflow_call:',
                          'continue-on-error:', 'ENABLE_PRD', 'needs_publication', 'select-uat-release.py'):
            self.assertNotIn(forbidden, workflow)
        self.assertLess(workflow.index('Validate confirmed main workflow'), workflow.index('actions/checkout@'))
        self.assertLess(workflow.index('Resolve reference'), workflow.index('Invoke only the Production'))
        self.assertNotIn('${{ inputs.', '\n'.join(self.script(name) for name in ('Validate confirmed main workflow', 'Invoke only the Production entry point')))

    @unittest.skipUnless(importlib.util.find_spec('yaml'), 'Optional PyYAML for full YAML structure validation')
    def test_yaml_structure_and_server_workflow_contract(self):
        import yaml
        workflow = yaml.load(self.workflow(), Loader=yaml.BaseLoader)
        self.assertEqual(set(workflow['on']), {'workflow_dispatch'})
        self.assertEqual(set(workflow['on']['workflow_dispatch']['inputs']), {'confirm_deploy', 'ref'})
        self.assertEqual(workflow['on']['workflow_dispatch']['inputs']['ref']['default'], 'main')
        self.assertNotIn('default', workflow['on']['workflow_dispatch']['inputs']['confirm_deploy'])
        self.assertEqual(set(workflow['jobs']), {'deploy'})
        self.assertEqual(workflow['name'], 'Deploy DCMS PRD')
        self.assertEqual(workflow['permissions'], {'contents': 'read', 'actions': 'read'})
        steps = workflow['jobs']['deploy']['steps']
        for step in steps:
            if 'run' in step:
                result = subprocess.run(['bash', '-n'], input=step['run'], text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
        checkout = next(step for step in steps if 'uses' in step)
        self.assertRegex(checkout['uses'], r'^actions/checkout@[a-f0-9]{40}$')
        self.assertNotIn('if', workflow['jobs']['deploy'])
        self.assertIn('refs/heads/main', workflow['jobs']['deploy']['steps'][0]['run'])
        self.assertIn('DEPLOY_PRD', workflow['jobs']['deploy']['steps'][0]['run'])
        self.assertEqual(selector.runtime.WORKFLOW, '.github/workflows/'+WORKFLOW.name)

    def test_confirmation_and_workflow_identity_are_checked_before_checkout(self):
        env = environment()
        self.assertEqual(self.shell('Validate confirmed main workflow', env).returncode, 0)
        for key, value in (('CONFIRM_DEPLOY', ''), ('CONFIRM_DEPLOY', 'deploy_prd'), ('CONFIRM_DEPLOY', 'DEPLOY_PRD '),
                           ('CONFIRM_DEPLOY', ' DEPLOY_PRD'), ('GITHUB_REF', 'refs/heads/feature'),
                           ('GITHUB_EVENT_NAME', 'push'), ('GITHUB_REPOSITORY', 'untrusted/fork'),
                           ('WORKFLOW_REF', 'pintybhatt/psg-document-control/.github/workflows/deploy-uat.yml@refs/heads/main'),
                           ('WORKFLOW_SHA', 'f'*40), ('GITHUB_SHA', 'main')):
            with self.subTest(key=key, value=value):
                self.assertNotEqual(self.shell('Validate confirmed main workflow', dict(env, **{key: value})).returncode, 0)
                with self.assertRaises(selector.SelectionError): selector.context(dict(env, **{key: value}))

    def test_fixed_ssh_target_strict_key_handling_stdin_and_failure_propagation(self):
        with tempfile.TemporaryDirectory(prefix='dcms-prd-workflow-test-') as temporary:
            root = Path(temporary)
            binary = root/'bin'
            binary.mkdir()
            (binary/'ssh-keygen').write_text('#!/bin/sh\nexit 0\n')
            (binary/'ssh-keygen').chmod(0o700)
            (binary/'ssh').write_text('''#!/usr/bin/python3
import json, os, pathlib, stat, sys
args = sys.argv[1:]
key = pathlib.Path(args[args.index('-i')+1])
hosts = pathlib.Path(next(x.split('=',1)[1] for x in args if x.startswith('UserKnownHostsFile=')))
assert stat.S_IMODE(key.stat().st_mode) == 0o600
assert stat.S_IMODE(hosts.stat().st_mode) == 0o600
assert 'PRD_SSH_KEY' not in os.environ and 'PRD_KNOWN_HOSTS' not in os.environ
pathlib.Path(os.environ['CALLS']).write_text(json.dumps({'args':args,'request':json.load(sys.stdin)}))
print('synthetic private output must not appear in CI')
sys.exit(int(os.environ['SSH_EXIT']))
''')
            (binary/'ssh').chmod(0o700)
            env = dict(PATH=str(binary)+':/usr/bin:/bin', RUNNER_TEMP=temporary, CALLS=str(root/'calls.json'),
                       SSH_EXIT='0', PRD_SSH_KEY='synthetic-key', PRD_KNOWN_HOSTS='synthetic-host-pin')
            request = {'synthetic': 'validated request'}
            for code in ('0', '1', '255'):
                (root/'prd-request.json').write_text(json.dumps(request))
                result = self.shell('Invoke only the Production entry point', dict(env, SSH_EXIT=code))
                self.assertEqual(result.returncode, int(code))
                call = json.loads((root/'calls.json').read_text())
                self.assertEqual(call['args'][-2:], ['dcms-prd-deploy@192.168.0.228', 'deploy-dcms-prd'])
                self.assertEqual(call['request'], request)
                for option in ('StrictHostKeyChecking=yes', 'IdentitiesOnly=yes', 'IdentityAgent=none',
                               'BatchMode=yes', 'ConnectionAttempts=1', 'ProxyCommand=none', 'ProxyJump=none', 'GlobalKnownHostsFile=/dev/null'):
                    self.assertIn(option, call['args'])
                self.assertNotIn('synthetic-key', result.stdout+result.stderr)
                self.assertNotIn('synthetic private output', result.stdout+result.stderr)
                self.assertFalse((root/'prd-request.json').exists())
                self.assertFalse(list(root.glob('dcms-prd-ssh.*')))
            (binary/'ssh-keygen').write_text('#!/bin/sh\nexit 1\n')
            (root/'calls.json').unlink()
            (root/'prd-request.json').write_text(json.dumps(request))
            failed_pin = self.shell('Invoke only the Production entry point', env)
            self.assertNotEqual(failed_pin.returncode, 0)
            self.assertFalse((root/'calls.json').exists())
            self.assertFalse((root/'prd-request.json').exists())
            self.assertFalse(list(root.glob('dcms-prd-ssh.*')))

    def test_failed_selection_cannot_reach_ssh_and_reports_no_success(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root/'docker').mkdir()
            (root/'docker/select-prd-release.py').write_text('raise SystemExit(1)\n')
            (root/'bin').mkdir()
            (root/'bin/git').write_text('#!/bin/sh\nprintf "%s\\n" "$GITHUB_SHA"\n')
            (root/'bin/git').chmod(0o700)
            result = self.shell('Resolve reference and verify existing publication', dict(environment(), RUNNER_TEMP=temporary,
                                PATH=str(root/'bin')+':/usr/bin:/bin'), cwd=root)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root/'prd-request.json').exists())
            failure = self.shell('Report Production failure', {'DEPLOY_OUTCOME': 'skipped'})
            self.assertIn('before the Production SSH step', failure.stdout)
            server_failure = self.shell('Report Production failure', {'DEPLOY_OUTCOME': 'failure', 'GITHUB_RUN_ID': '789', 'GITHUB_RUN_ATTEMPT': '2'})
            self.assertIn('/srv/dcms-prd/history/gha-789-2/result.json', server_failure.stdout)
            self.assertIn('Do not retry automatically', server_failure.stdout)
        self.assertIn("        if: success() && steps.production_deploy.outcome == 'success'\n", self.workflow())
        self.assertIn('        if: failure()\n', self.workflow())
        self.assertIn('        if: always()\n', self.workflow())


if __name__ == '__main__':
    unittest.main()
