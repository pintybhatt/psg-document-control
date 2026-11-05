"""One-click UAT release selection and authorized publication; all network calls are fake."""
import copy
import datetime
import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest
from unittest.mock import patch
import urllib.error

from test_release_preparation import load, SOURCE, DIGEST, automatic_release
from test_uat_deployment import release_fixture, prepare


selector = load('uat_release_selection', 'select-uat-release.py')
ROOT = Path(__file__).resolve().parent.parent
NOW = datetime.datetime(2026, 10, 27, 12, tzinfo=datetime.timezone.utc)


class MainCiDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.fixture = automatic_release()
        self.api = selector.auth.GitHub('synthetic-read-token')

    def metadata(self, path):
        if path == 'branches/main': return {'protected': True, 'commit': {'sha': SOURCE}}
        if path == 'actions/runs/123': return self.fixture['run']
        raise AssertionError('Unexpected API path: '+path)

    def collection(self, path, key):
        return self.fixture['jobs'] if key == 'jobs' else self.fixture['artifacts']

    def select(self, candidates=None):
        with patch.object(selector, 'runs', return_value=candidates or [self.fixture['run']]), \
                patch.object(self.api, 'get', side_effect=self.metadata), \
                patch.object(self.api, 'collection', side_effect=self.collection), \
                patch.object(self.api, 'manifest', return_value=self.fixture['manifest']):
            return selector.deployment_request(self.api, SOURCE, '789', '1')

    def test_exact_successful_main_image_is_selected_and_the_latest_attempt_is_verified(self):
        for attempt in (1, 2):
            self.fixture = automatic_release(attempt)
            self.assertEqual(self.select(), {'image': DIGEST, 'source_commit': SOURCE, 'run': '789-1'})

    def test_selection_waits_only_for_the_exact_main_ci_run_and_never_builds_or_publishes(self):
        run = self.fixture['run']
        with patch.object(selector, 'runs', side_effect=[[], [dict(run, status='in_progress')], [run]]) as runs, \
                patch.object(self.api, 'get', side_effect=self.metadata), \
                patch.object(self.api, 'collection', side_effect=self.collection), \
                patch.object(self.api, 'manifest', return_value=self.fixture['manifest']), \
                patch.object(selector.time, 'sleep') as sleep, patch.object(selector, 'dispatch') as dispatch:
            result = selector.deployment_request(self.api, SOURCE, '789', '1')
        self.assertEqual(result['image'], DIGEST)
        self.assertEqual(sleep.call_count, 2)
        dispatch.assert_not_called()
        for call in runs.call_args_list:
            self.assertEqual(call.kwargs, dict(workflow=selector.CI_WORKFLOW, branch='main', event='push', head_sha=SOURCE))

    def test_failed_newest_run_cannot_fall_back_to_an_older_successful_run(self):
        for conclusion in ('failure', 'skipped', 'cancelled', 'timed_out'):
            with self.subTest(conclusion=conclusion), self.assertRaisesRegex(ValueError, 'failed or was skipped'):
                self.select([dict(self.fixture['run'], id=124, conclusion=conclusion), self.fixture['run']])

    def test_missing_expired_and_ambiguous_evidence_fail_without_deployment(self):
        for change in ('missing', 'expired', 'duplicate'):
            self.fixture = automatic_release()
            rows = self.fixture['artifacts']['artifacts']
            if change == 'missing': rows.clear()
            elif change == 'expired': rows[0]['expired'] = True
            else: rows.append(dict(rows[0], id=457))
            with self.subTest(change=change), self.assertRaisesRegex(ValueError, 'missing, expired or ambiguous'):
                self.select()

    def test_changed_main_wrong_source_and_timeout_refuse(self):
        for change in ('main', 'source', 'attempt'):
            self.fixture = automatic_release()
            if change == 'source':
                self.fixture['run']['head_sha'] = 'f'*40
            elif change == 'attempt':
                manifest = json.loads(self.fixture['manifest']); manifest['github_run_attempt'] = '2'
                self.fixture['manifest'] = json.dumps(manifest).encode()
            if change == 'main':
                with patch.object(self.api, 'get', return_value={'protected': True, 'commit': {'sha': 'f'*40}}), self.assertRaises(ValueError):
                    selector.deployment_request(self.api, SOURCE, '789', '1')
            else:
                with self.subTest(change=change), self.assertRaises(ValueError): self.select()
        with patch.object(selector.time, 'monotonic', side_effect=[0, 2]), self.assertRaisesRegex(ValueError, 'Timed out'):
            selector.deployment_request(self.api, SOURCE, '789', '1', timeout=1)

    def test_main_is_checked_again_after_verification_and_racing_reruns_are_refused(self):
        with patch.object(selector, 'runs', return_value=[self.fixture['run']]), \
                patch.object(self.api, 'collection', side_effect=self.collection), \
                patch.object(self.api, 'release', return_value=self.fixture), \
                patch.object(selector, 'current_main', side_effect=[None, ValueError('main advanced')]), self.assertRaises(ValueError):
            selector.deployment_request(self.api, SOURCE, '789', '1')
        changed = copy.deepcopy(self.fixture); changed['run']['run_attempt'] = 2
        with patch.object(selector, 'runs', return_value=[self.fixture['run']]), \
                patch.object(self.api, 'get', side_effect=self.metadata), patch.object(self.api, 'collection', side_effect=self.collection), \
                patch.object(self.api, 'release', return_value=changed), self.assertRaisesRegex(ValueError, 'identity changed'):
            selector.deployment_request(self.api, SOURCE, '789', '1')

    def test_deploy_cli_writes_the_existing_private_envelope_only_after_success(self):
        with tempfile.TemporaryDirectory() as directory:
            expected = {'image': DIGEST, 'source_commit': SOURCE, 'run': '789-1'}
            with patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE, GITHUB_RUN_ID='789', GITHUB_RUN_ATTEMPT='1', RUNNER_TEMP=directory), \
                    patch.object(selector.sys, 'argv', ['selector', 'deploy']), \
                    patch.object(selector, 'deployment_request', return_value=expected):
                selector.main()
            path = Path(directory)/'uat-request.json'
            self.assertEqual(json.loads(path.read_text()), expected)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_deploy_cli_does_not_write_a_request_or_echo_untrusted_errors_on_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE, GITHUB_RUN_ID='789', GITHUB_RUN_ATTEMPT='1', RUNNER_TEMP=directory), \
                    patch.object(selector.sys, 'argv', ['selector', 'deploy']), \
                    patch.object(selector, 'deployment_request', side_effect=ValueError('synthetic-secret-token')), self.assertRaises(SystemExit) as error:
                selector.main()
            self.assertNotIn('synthetic-secret-token', str(error.exception))
            self.assertFalse((Path(directory)/'uat-request.json').exists())


class ReleaseSelectionTests(unittest.TestCase):
    def setUp(self):
        fixture = release_fixture()
        self.release = {'manifest': fixture.manifest_bytes, 'run': fixture.run,
                        'jobs': fixture.jobs, 'artifacts': fixture.artifacts}
        self.api = selector.auth.GitHub('synthetic-read-token')

    def test_latest_eligible_exact_source_is_selected_after_full_publication_verification(self):
        fixtures = copy.deepcopy(self.release)
        with patch.object(self.api, 'get', side_effect=[fixtures['run'], {'status': 'ahead'}, {'status': 'identical'}]), \
                patch.object(self.api, 'collection', side_effect=[fixtures['artifacts'], fixtures['jobs'], fixtures['artifacts']]), \
                patch.object(self.api, 'manifest', return_value=fixtures['manifest']), \
                patch.object(self.api, 'release_current') as current:
            self.assertEqual(selector.select(self.api, SOURCE, NOW, candidates=[fixtures['run']]), (123, 456))
        current.assert_called_once_with(fixtures['run'])

    def test_failed_skipped_smoke_missing_shard_and_expired_artifact_never_qualify(self):
        for change in ('run-failed', 'publish-skipped', 'smoke-failed', 'mysql-missing', 'expired'):
            fixture = copy.deepcopy(self.release)
            if change == 'run-failed': fixture['run']['conclusion'] = 'failure'
            if change == 'publish-skipped': fixture['jobs']['jobs'][-1]['conclusion'] = 'skipped'
            if change == 'smoke-failed':
                job = next(job for job in fixture['jobs']['jobs'] if job['name'] == 'ci / Image / smoke')
                job['steps'][0]['conclusion'] = 'failure'
            if change == 'mysql-missing':
                fixture['jobs']['jobs'] = [job for job in fixture['jobs']['jobs'] if 'MySQL' not in job['name']]
                fixture['jobs']['total_count'] = len(fixture['jobs']['jobs'])
            if change == 'expired': fixture['artifacts']['artifacts'][0]['expired'] = True
            with self.subTest(change=change), \
                    patch.object(self.api, 'get', return_value=fixture['run']), \
                    patch.object(self.api, 'collection', side_effect=[fixture['artifacts'], fixture['jobs'], fixture['artifacts']]), \
                    patch.object(self.api, 'manifest', return_value=fixture['manifest']):
                self.assertIsNone(selector.select(self.api, SOURCE, NOW, candidates=[fixture['run']]))

    def test_wrong_source_old_future_and_non_main_ancestry_are_refused(self):
        for change in ('source', 'old', 'future', 'ancestry'):
            fixture = copy.deepcopy(self.release)
            manifest = json.loads(fixture['manifest'])
            if change == 'source': manifest['source_commit'] = 'f'*40
            if change == 'old': fixture['run']['run_started_at'] = '2026-10-01T00:00:00Z'
            if change == 'future': fixture['run']['run_started_at'] = '2026-10-28T00:00:00Z'
            fixture['manifest'] = json.dumps(manifest).encode()
            with self.subTest(change=change), patch.object(self.api, 'release', return_value=fixture), \
                    patch.object(self.api, 'get', return_value={'status': 'diverged'}), self.assertRaises(ValueError):
                selector.eligible(self.api, 123, 456, SOURCE, NOW)

    def test_selection_continues_to_older_valid_evidence_but_http_failure_stops(self):
        artifacts = {'artifacts': [{'id': 456, 'expired': False, 'name': 'release-manifest-v0.1.0-'+SOURCE}]}
        with patch.object(self.api, 'collection', return_value=artifacts), \
                patch.object(selector, 'eligible', side_effect=[ValueError('invalid'), (123, 456)]):
            self.assertEqual(selector.select(self.api, SOURCE, NOW, candidates=[{'id': 124}, {'id': 123}]), (123, 456))
        with patch.object(self.api, 'collection', side_effect=urllib.error.URLError('synthetic-secret')), self.assertRaises(urllib.error.URLError):
            selector.select(self.api, SOURCE, NOW, candidates=[{'id': 123}])

    def test_paginated_search_filters_workflow_branch_event_and_orders_newest_first(self):
        with patch.object(self.api, 'get', side_effect=[{'workflow_runs': [{'id': i} for i in range(100)]},
                                                      {'workflow_runs': [{'id': 100}]}]) as get:
            result = selector.runs(self.api, branch='main', event='workflow_dispatch', status='success')
        self.assertEqual(result[0]['id'], 100)
        self.assertIn('actions/workflows/release-image.yml/runs?', get.call_args_list[0].args[0])
        self.assertIn('status=success', get.call_args_list[0].args[0])
        self.assertIn('page=2', get.call_args_list[1].args[0])

    def test_changed_or_unprotected_main_refuses_before_publication(self):
        for branch in ({'protected': False, 'commit': {'sha': SOURCE}}, {'protected': True, 'commit': {'sha': 'f'*40}}):
            with self.subTest(branch=branch), patch.object(self.api, 'get', return_value=branch), \
                    patch.object(selector, 'dispatch') as dispatch, self.assertRaises(ValueError):
                selector.publish(self.api, SOURCE, '789', '1')
            dispatch.assert_not_called()

    def test_dispatch_only_targets_existing_pipeline_and_never_bootstraps_package(self):
        with patch.object(selector.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value.__enter__.return_value.status = 204
            selector.dispatch(self.api, SOURCE, 'v0.0.789-rc.1', 'DCMS-UAT-789-1')
        request = opener.return_value.open.call_args.args[0]
        self.assertEqual(request.full_url, 'https://api.github.com/repos/pintybhatt/psg-document-control/actions/workflows/release-image.yml/dispatches')
        self.assertEqual(request.method, 'POST')
        self.assertEqual(json.loads(request.data), {'ref': 'main', 'inputs': {'source_commit': SOURCE,
                         'baseline_commit': SOURCE, 'version': 'v0.0.789-rc.1',
                         'approval_reference': 'DCMS-UAT-789-1', 'allow_initial_private_package': False}})

    def test_publication_waits_for_correlated_run_success_and_verified_artifact(self):
        run = {'id': 123, 'display_title': 'DCMS release v0.0.789-rc.1 '+SOURCE,
               'status': 'in_progress', 'conclusion': None}
        unrelated = dict(run, id=124, display_title='another release')
        with patch.object(selector, 'current_main'), patch.object(selector, 'dispatch') as dispatch, \
                patch.object(selector, 'runs', side_effect=[[], [unrelated, run], [dict(run, status='completed', conclusion='success')]]), \
                patch.object(selector, 'select', return_value=(123, 456)) as select, patch.object(selector.time, 'sleep') as sleep:
            self.assertEqual(selector.publish(self.api, SOURCE, '789', '1'), (123, 456))
        dispatch.assert_called_once_with(self.api, SOURCE, 'v0.0.789-rc.1', 'DCMS-UAT-789-1')
        self.assertEqual(sleep.call_count, 2)
        self.assertEqual(select.call_args.kwargs['candidates'][0]['id'], 123)

    def test_failed_cancelled_skipped_ambiguous_and_timeout_publication_refuse(self):
        for conclusion in ('failure', 'cancelled', 'skipped', 'success'):
            run = {'id': 123, 'display_title': 'DCMS release v0.0.789-rc.1 '+SOURCE,
                   'status': 'completed', 'conclusion': conclusion}
            with self.subTest(conclusion=conclusion), patch.object(selector, 'current_main'), \
                    patch.object(selector, 'dispatch'), patch.object(selector, 'runs', return_value=[run]), \
                    patch.object(selector, 'select', return_value=None), self.assertRaises(ValueError):
                selector.publish(self.api, SOURCE, '789', '1')
        with patch.object(selector, 'current_main'), patch.object(selector, 'dispatch'), \
                patch.object(selector, 'runs', return_value=[run, dict(run, id=124)]), self.assertRaisesRegex(ValueError, 'Ambiguous'):
            selector.publish(self.api, SOURCE, '789', '1')
        with patch.object(selector, 'current_main'), patch.object(selector, 'dispatch'), \
                patch.object(selector.time, 'monotonic', side_effect=[0, 2]), self.assertRaisesRegex(ValueError, 'Timed out'):
            selector.publish(self.api, SOURCE, '789', '1', timeout=1)

    def test_cli_normal_selection_needs_no_ids_and_rollback_never_publishes(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)/'output'
            environment = dict(GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE, GITHUB_OUTPUT=str(output),
                               OPERATION='deploy', RELEASE_RUN_ID='', RELEASE_ARTIFACT_ID='', ROLLBACK_COMPATIBILITY_REFERENCE='')
            with patch.dict(os.environ, environment), patch.object(selector.sys, 'argv', ['selector']), \
                    patch.object(selector, 'current_main'), patch.object(selector, 'select', return_value=None), \
                    patch.object(selector, 'publish') as publish:
                selector.main()
            self.assertIn('needs_publication=true', output.read_text())
            environment.update(OPERATION='rollback', RELEASE_RUN_ID='123', RELEASE_ARTIFACT_ID='456', ROLLBACK_COMPATIBILITY_REFERENCE='COMPAT-123')
            with patch.dict(os.environ, environment), patch.object(selector.sys, 'argv', ['selector']), \
                    patch.object(selector, 'current_main'), patch.object(selector, 'eligible', return_value=(123, 456)) as eligible, \
                    patch.object(selector, 'publish') as publish:
                selector.main()
            eligible.assert_called_once()
            publish.assert_not_called()
            self.assertIn('release_run_id=123', output.read_text())

    def test_manual_deploy_override_and_incomplete_rollback_refuse_without_leaking_errors(self):
        cases = [('deploy', '123', '456', ''), ('rollback', '', '', 'COMPAT-123'), ('rollback', '123', '456', '')]
        for operation, run_id, artifact_id, reference in cases:
            with self.subTest(operation=operation, run_id=run_id, reference=reference), \
                    patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE, OPERATION=operation,
                               RELEASE_RUN_ID=run_id, RELEASE_ARTIFACT_ID=artifact_id, ROLLBACK_COMPATIBILITY_REFERENCE=reference), \
                    patch.object(selector.sys, 'argv', ['selector']), patch.object(selector, 'current_main'), \
                    self.assertRaises(SystemExit) as result:
                selector.main()
            self.assertIn('::error::', str(result.exception))
        with patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE), \
                patch.object(selector, 'current_main', side_effect=ValueError('synthetic-secret')), self.assertRaises(SystemExit) as result:
            selector.main()
        self.assertNotIn('synthetic-secret', str(result.exception))

    def test_prepare_rechecks_main_and_exact_forward_source_before_minting_oidc(self):
        for branch, source in [({'protected': True, 'commit': {'sha': 'f'*40}}, SOURCE),
                               ({'protected': False, 'commit': {'sha': SOURCE}}, SOURCE),
                               ({'protected': True, 'commit': {'sha': SOURCE}}, 'f'*40)]:
            release = copy.deepcopy(self.release)
            manifest = json.loads(release['manifest'])
            manifest['source_commit'] = source
            release['manifest'] = json.dumps(manifest).encode()
            with self.subTest(branch=branch, source=source), patch.dict(os.environ, GH_TOKEN='synthetic-read-token', GITHUB_SHA=SOURCE), \
                    patch.object(prepare.auth.GitHub, 'release', return_value=release), patch.object(prepare.auth.GitHub, 'get', return_value=branch), \
                    patch.object(prepare.auth, 'read_https') as oidc, self.assertRaises(ValueError):
                prepare.prepare('123', '456', 'deploy')
            oidc.assert_not_called()


class WorkflowGateTests(unittest.TestCase):
    def script(self, filename, step):
        workflow = (ROOT/'.github/workflows'/filename).read_text()
        block = workflow.split('      - name: '+step+'\n', 1)[1]
        return textwrap.dedent(block.split('        run: |\n', 1)[1].split('      - ', 1)[0])

    def run_script(self, script, **environment):
        return subprocess.run(['bash', '-euo', 'pipefail', '-c', script], env=dict(os.environ, **environment),
                              capture_output=True, text=True, timeout=10)

    def test_exact_confirmation_and_manual_main_are_required_before_checkout(self):
        script = self.script('deploy-uat.yml', 'Validate manual main deployment')
        with tempfile.TemporaryDirectory() as directory:
            environment = dict(ENABLED='true', CONFIRM='DEPLOY_UAT',
                               GITHUB_EVENT_NAME='workflow_dispatch', GITHUB_REF='refs/heads/main', GITHUB_SHA='b'*40,
                               GITHUB_REPOSITORY='pintybhatt/psg-document-control', GITHUB_RUN_ID='1', GITHUB_RUN_ATTEMPT='1', RUNNER_TEMP=directory)
            self.assertEqual(self.run_script(script, **environment).returncode, 0)
            for key, value in [('ENABLED','false'), ('CONFIRM',''), ('CONFIRM','deploy_uat'), ('CONFIRM','DEPLOY_UAT '), ('CONFIRM',' DEPLOY_UAT'), ('GITHUB_REF','refs/heads/other'), ('GITHUB_EVENT_NAME','push'), ('GITHUB_SHA','main'), ('GITHUB_REPOSITORY','untrusted/fork')]:
                with self.subTest(key=key):
                    self.assertNotEqual(self.run_script(script, **dict(environment, **{key:value})).returncode, 0)

    def test_publication_switch_is_checked_inside_environment_before_any_checkout(self):
        script = self.script('release-image.yml', 'Require explicit publication authorization')
        self.assertEqual(self.run_script(script, ENABLED='true').returncode, 0)
        for value in ('false', ''):
            result = self.run_script(script, ENABLED=value)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Image publication is disabled', result.stdout)

    def test_publisher_accepts_only_this_source_and_same_attempt_archive(self):
        script = self.script('release-image.yml', 'Verify the exact main source and archive identity')
        with tempfile.TemporaryDirectory() as directory:
            git = Path(directory)/'git'
            git.write_text('#!/usr/bin/python3\nimport os,sys\nif sys.argv[1:]==["rev-parse","HEAD"]: print(os.environ["SOURCE"])\n')
            git.chmod(0o700)
            environment = dict(PATH=directory+':/usr/bin:/bin', SOURCE=SOURCE, GITHUB_SHA=SOURCE,
                               GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='2', ARTIFACT='validated-image-'+SOURCE+'-123-2')
            self.assertEqual(self.run_script(script, **environment).returncode, 0)
            for change in ({'GITHUB_SHA':'f'*40}, {'ARTIFACT':'validated-image-'+SOURCE+'-123-1'}, {'SOURCE':'main'}):
                with self.subTest(change=change):
                    self.assertNotEqual(self.run_script(script, **dict(environment, **change)).returncode, 0)

    def test_manual_workflow_uses_read_only_selection_before_restricted_ssh(self):
        workflow = (ROOT/'.github/workflows/deploy-uat.yml').read_text()
        self.assertIn('UAT_HOST: 192.168.0.228', workflow)
        self.assertNotIn('id-token:', workflow)
        self.assertNotIn('  publish:', workflow)
        self.assertNotIn('  resolve:', workflow)
        self.assertIn('python3 -B docker/select-uat-release.py deploy', workflow)
        self.assertIn('  actions: read', workflow)
        self.assertIn('      confirmation:', workflow)
        self.assertNotIn('      image:', workflow)
        self.assertNotIn('inputs.image', workflow)
        self.assertNotIn('select-uat-release.py publish', workflow)
        self.assertLess(workflow.index('Validate manual main deployment'), workflow.index('actions/checkout@'))
        self.assertLess(workflow.index('Select verified release'), workflow.index('Invoke only the enrolled'))
