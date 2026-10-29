"""GitHub authorization tests: synthetic RSA signatures, mocked HTTPS, no live credentials."""
import base64
import copy
import datetime
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch
import urllib.error
import zipfile

import test_release_preparation as release_fixtures
from test_release_preparation import load, DIGEST


auth = load('github_uat_auth_tests', 'github-uat-auth.py')
POLICY = {'enabled': True, 'target': 'uat', 'project': 'dcms-uat-xppl', 'authorization': 'github-oidc',
          'repository_id': 42, 'operator_ids': [100], 'runner_ids': [200], 'maximum_release_age_hours': 168,
          'recovery_reference': 'UAT-RECOVERY-123'}
NOW = 1793102400


def request(**changes):
    result = {'deployment_run_id': 789, 'deployment_run_attempt': 1, 'release_run_id': 123,
              'release_run_attempt': 1, 'release_artifact_id': 456, 'image': DIGEST,
              'manifest_sha256': 'a'*64, 'operation': 'deploy', 'rollback_compatibility_reference': ''}
    return dict(result, **changes)


def claims_for(payload):
    return {'iss': auth.ISSUER, 'aud': auth.audience(payload), 'iat': NOW-10, 'nbf': NOW-10, 'exp': NOW+290,
            'repository': auth.REPOSITORY, 'repository_id': '42', 'event_name': 'workflow_dispatch',
            'ref': 'refs/heads/main', 'ref_type': 'branch', 'repository_visibility': 'private',
            'sub': 'repo:'+auth.REPOSITORY+':environment:uat', 'runner_environment': 'self-hosted',
            'workflow_ref': auth.REPOSITORY+'/'+auth.WORKFLOW+'@refs/heads/main', 'workflow_sha': 'd'*40,
            'sha': 'd'*40, 'run_id': str(payload['deployment_run_id']), 'run_attempt': str(payload['deployment_run_attempt']),
            'actor_id': '100', 'check_run_id': '555'}


def deployment_run():
    return {'id': 789, 'path': auth.WORKFLOW, 'event': 'workflow_dispatch', 'head_branch': 'main', 'head_sha': 'd'*40,
            'status': 'in_progress', 'run_attempt': 1, 'repository': {'id': 42}, 'head_repository': {'id': 42},
            'actor': {'id': 100}, 'triggering_actor': {'id': 100}}


def deployment_job():
    return {'name': 'deploy', 'status': 'in_progress', 'run_id': 789, 'run_attempt': 1, 'runner_id': 200,
            'check_run_url': 'https://api.github.com/repos/'+auth.REPOSITORY+'/check-runs/555',
            'labels': ['self-hosted', 'Linux', 'X64', 'dcms-uat-runner']}


class OidcVerificationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='dcms-synthetic-oidc-test-')
        cls.key = Path(cls.temporary.name)/'synthetic-only.pem'
        subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048',
                        '-out', str(cls.key)], check=True, capture_output=True, timeout=20)
        modulus = subprocess.check_output(['/usr/bin/openssl', 'rsa', '-in', str(cls.key), '-noout', '-modulus'], timeout=10)
        n = bytes.fromhex(modulus.decode().strip().split('=')[1])
        cls.jwk = {'kty': 'RSA', 'use': 'sig', 'alg': 'RS256', 'kid': 'synthetic',
                   'n': base64.urlsafe_b64encode(n).decode().rstrip('='), 'e': 'AQAB'}

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def token(self, claims=None, header=None):
        header = {'alg': 'RS256', 'typ': 'JWT', 'kid': 'synthetic'} if header is None else header
        claims = claims_for(request()) if claims is None else claims
        parts = [base64.urlsafe_b64encode(json.dumps(value).encode()).decode().rstrip('=') for value in (header, claims)]
        content = '.'.join(parts).encode()
        signature = subprocess.check_output(['/usr/bin/openssl', 'dgst', '-sha256', '-sign', str(self.key)], input=content, timeout=10)
        return content.decode()+'.'+base64.urlsafe_b64encode(signature).decode().rstrip('=')

    def verify(self, token, payload=None, jwks=None):
        with patch.object(auth, 'read_https', return_value=json.dumps({'keys': [self.jwk] if jwks is None else jwks}).encode()) as read:
            result = auth.verify_oidc(token, request() if payload is None else payload, POLICY, now=NOW)
        read.assert_called_once_with(auth.ISSUER+'/.well-known/jwks', limit=128*1024)
        return result

    def test_signed_request_bound_trusted_workflow_passes_real_openssl_verification(self):
        self.assertEqual(self.verify(self.token())['actor_id'], '100')

    def test_modified_signature_and_request_are_refused(self):
        token = self.token()
        parts = token.split('.')
        parts[-1] = base64.urlsafe_b64encode(bytes(256)).decode().rstrip('=')
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.verify('.'.join(parts))
        with self.assertRaisesRegex(ValueError, 'identity'):
            self.verify(token, request(operation='rollback', rollback_compatibility_reference='COMPAT-123'))

    def test_wrong_workflow_actor_environment_repository_ref_or_run_are_refused(self):
        original = claims_for(request())
        for key, value in [('iss', 'https://untrusted.invalid'), ('aud', 'arbitrary'), ('repository', 'other/repo'),
                           ('repository_id', '43'), ('event_name', 'pull_request'), ('ref', 'refs/heads/feature'),
                           ('sub', 'repo:'+auth.REPOSITORY+':environment:production'), ('actor_id', '999'),
                           ('workflow_ref', auth.REPOSITORY+'/.github/workflows/ci.yml@refs/heads/main'),
                           ('runner_environment', 'github-hosted'), ('run_attempt', '2'), ('run_id', '999'),
                           ('workflow_sha', 'f'*40), ('check_run_id', ''), ('repository_visibility', 'public'),
                           ('job_workflow_ref', 'other/reusable')]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(self.token(dict(original, **{key: value})))

    def test_expired_future_and_long_lived_tokens_are_refused(self):
        original = claims_for(request())
        for change in ({'exp': NOW}, {'nbf': NOW+1}, {'iat': NOW+1}, {'iat': NOW-301}, {'exp': NOW+3600}, {'exp': None}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                self.verify(self.token(dict(original, **change)))

    def test_unknown_duplicate_keys_algorithm_confusion_and_remote_key_urls_are_refused(self):
        with self.assertRaises(ValueError):
            self.verify(self.token(), jwks=[])
        with self.assertRaises(ValueError):
            self.verify(self.token(), jwks=[self.jwk, self.jwk])
        for header in ({'alg': 'none', 'typ': 'JWT', 'kid': 'synthetic'},
                       {'alg': 'HS256', 'typ': 'JWT', 'kid': 'synthetic'},
                       {'alg': 'RS256', 'typ': 'JWT', 'kid': 'synthetic', 'jku': 'https://untrusted.invalid'}):
            with self.subTest(header=header), self.assertRaises(ValueError):
                self.verify(self.token(header=header))
        with self.assertRaises(ValueError):
            auth.parse_json('{"image":"one","image":"two"}')


class GithubEvidenceTests(unittest.TestCase):
    def api(self):
        return auth.GitHub('synthetic-read-token')

    def test_release_download_must_pass_existing_publication_verification(self):
        fixture = release_fixtures.UatReleaseEvidenceTests()
        fixture.setUp()
        api = self.api()
        with patch.object(api, 'get', return_value=fixture.run), \
                patch.object(api, 'collection', side_effect=[fixture.jobs, fixture.artifacts]), \
                patch.object(api, 'manifest', return_value=fixture.manifest_bytes):
            self.assertEqual(api.release(123, 456)['manifest'], fixture.manifest_bytes)
        fixture.jobs['jobs'][0]['conclusion'] = 'skipped'
        with patch.object(api, 'get', return_value=fixture.run), \
                patch.object(api, 'collection', side_effect=[fixture.jobs, fixture.artifacts]), \
                patch.object(api, 'manifest', return_value=fixture.manifest_bytes), self.assertRaises(ValueError):
            api.release(123, 456)

    def test_static_policy_requires_numeric_pins_recovery_and_bounded_freshness(self):
        auth.validate_policy(POLICY)
        for field, value in [('authorization', 'legacy'), ('repository_id', None), ('operator_ids', []),
                             ('runner_ids', [True]), ('recovery_reference', None), ('maximum_release_age_hours', 0)]:
            with self.subTest(field=field), self.assertRaises(ValueError):
                auth.validate_policy(dict(POLICY, **{field: value}))

    def test_https_calls_use_headers_and_refuse_redirects_without_logging_credentials(self):
        with patch.object(auth.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value.__enter__.return_value.read.return_value = b'{}'
            self.assertEqual(self.api().get('branches/main'), {})
            sent = opener.return_value.open.call_args.args[0]
            self.assertEqual(sent.full_url, 'https://api.github.com/repos/'+auth.REPOSITORY+'/branches/main')
            self.assertEqual(sent.get_header('Authorization'), 'Bearer synthetic-read-token')
            self.assertNotIn('synthetic-read-token', sent.full_url)
        self.assertIsNone(auth.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://untrusted.invalid'))

    def test_only_active_dispatch_on_current_protected_main_and_dedicated_runner_passes(self):
        api = self.api()
        with patch.object(api, 'get', side_effect=[{'id': 42, 'full_name': auth.REPOSITORY, 'private': True},
                         {'protected': True, 'commit': {'sha': 'd'*40}}, deployment_run()]), \
                patch.object(api, 'collection', return_value={'jobs': [deployment_job()]}):
            auth.workflow_current(api, request(), claims_for(request()), POLICY)

    def test_unprotected_main_stale_revision_changed_actor_and_untrusted_runner_are_refused(self):
        for change in ('protection', 'sha', 'path', 'status', 'actor', 'attempt', 'runner', 'labels', 'job'):
            branch = {'protected': True, 'commit': {'sha': 'd'*40}}
            run, job = deployment_run(), deployment_job()
            if change == 'protection': branch['protected'] = False
            if change == 'sha': branch['commit']['sha'] = 'f'*40
            if change == 'path': run['path'] = '.github/workflows/ci.yml'
            if change == 'status': run['status'] = 'completed'
            if change == 'actor': run['triggering_actor']['id'] = 999
            if change == 'attempt': run['run_attempt'] = 2
            if change == 'runner': job['runner_id'] = 999
            if change == 'labels': job['labels'] = ['self-hosted']
            if change == 'job': job['check_run_url'] += '9'
            api = self.api()
            with self.subTest(change=change), \
                    patch.object(api, 'get', side_effect=[{'id': 42, 'full_name': auth.REPOSITORY, 'private': True}, branch, run]), \
                    patch.object(api, 'collection', return_value={'jobs': [job]}), self.assertRaises(ValueError):
                auth.workflow_current(api, request(), claims_for(request()), POLICY)

    def test_artifact_download_never_forwards_api_token_and_never_extracts_paths(self):
        archive = io.BytesIO()
        with zipfile.ZipFile(archive, 'w') as package:
            package.writestr('release-manifest.json', '{}')
        redirect = urllib.error.HTTPError('https://api.github.com/synthetic', 302, '', {'Location': 'https://synthetic.blob.core.windows.net/artifact?signature=synthetic'}, io.BytesIO())
        with patch.object(auth, 'read_https', side_effect=[redirect, archive.getvalue()]) as read:
            self.assertEqual(self.api().manifest(456), b'{}')
        self.assertEqual(len(read.call_args_list[0].args), 2)
        self.assertEqual(len(read.call_args_list[1].args), 1)

    def test_untrusted_artifact_redirect_is_refused(self):
        for url in ('http://synthetic.blob.core.windows.net/a', 'https://untrusted.invalid/a', 'https://user@synthetic.blob.core.windows.net/a'):
            redirect = urllib.error.HTTPError('https://api.github.com/synthetic', 302, '', {'Location': url}, io.BytesIO())
            with self.subTest(url=url), patch.object(auth, 'read_https', side_effect=redirect) as read, self.assertRaises(ValueError):
                self.api().manifest(456)
            self.assertEqual(read.call_count, 1)

    def test_pagination_requires_complete_pages(self):
        api = self.api()
        with patch.object(api, 'get', side_effect=[{'total_count': 101, 'jobs': [{'id': i} for i in range(100)]},
                                                 {'total_count': 101, 'jobs': [{'id': 100}]}]):
            self.assertEqual(api.collection('actions/synthetic', 'jobs')['total_count'], 101)
        with patch.object(api, 'get', return_value={'total_count': 2, 'jobs': [{'id': 1}]}), self.assertRaises(ValueError):
            api.collection('actions/synthetic', 'jobs')

    def test_current_attempt_failed_or_rerun_releases_are_refused(self):
        api = self.api()
        run = {'id': 123, 'run_attempt': 1, 'head_sha': 'd'*40}
        for change in ({'run_attempt': 2}, {'status': 'in_progress'}, {'conclusion': 'failure'}):
            current = dict(dict(run, status='completed', conclusion='success'), **change)
            with self.subTest(change=change), patch.object(api, 'get', return_value=current), self.assertRaises(ValueError):
                api.release_current(run)


if __name__ == '__main__':
    unittest.main()
