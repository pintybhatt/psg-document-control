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
            'sub': 'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:uat',
            'runner_environment': 'self-hosted',
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

    def verify(self, token, payload=None, jwks=None, progress=None, policy=None):
        with patch.object(auth, 'read_https', return_value=json.dumps({'keys': [self.jwk] if jwks is None else jwks}).encode()) as read:
            result = auth.verify_oidc(token, request() if payload is None else payload,
                                      POLICY if policy is None else policy, now=NOW, progress=progress)
        read.assert_called_once_with(auth.ISSUER+'/.well-known/jwks', limit=128*1024)
        return result

    def test_signed_request_bound_trusted_workflow_passes_real_openssl_verification(self):
        self.assertEqual(self.verify(self.token())['actor_id'], '100')

    def test_exact_immutable_subject_and_verified_github_identity_pass_signed_verification(self):
        claims = dict(claims_for(request()), repository_id='1435463595', actor_id='8923839',
                      repository_owner='pintybhatt', repository_owner_id='8923839', environment='uat')
        policy = dict(POLICY, repository_id=1435463595, operator_ids=[8923839], runner_ids=[21])
        verified = self.verify(self.token(claims), policy=policy)
        self.assertEqual(verified['sub'], 'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:uat')
        self.assertEqual(verified['repository_id'], '1435463595')
        self.assertEqual(verified['actor_id'], '8923839')

    def test_authorized_operator_passes_with_absent_or_matching_optional_job_workflow_claims(self):
        policy = dict(POLICY, repository_id=1435463595, operator_ids=[8923839], runner_ids=[21])
        claims = dict(claims_for(request()), repository_id='1435463595', actor_id='8923839')
        for optional in ({}, {'job_workflow_ref': claims['workflow_ref']}, {'job_workflow_sha': claims['workflow_sha']},
                         {'job_workflow_ref': claims['workflow_ref'], 'job_workflow_sha': claims['workflow_sha']}):
            with self.subTest(optional=optional):
                verified = self.verify(self.token(dict(claims, **optional)), policy=policy)
                self.assertEqual(verified['actor_id'], '8923839')
                self.assertEqual(verified['workflow_ref'], auth.REPOSITORY+'/'+auth.WORKFLOW+'@refs/heads/main')

    def test_unauthorized_operator_has_its_own_reason_regardless_of_job_claim_presence(self):
        policy = dict(POLICY, operator_ids=[8923839])
        claims = claims_for(request())
        for actor in ('999', 8923839, None, ''):
            for optional in ({}, {'job_workflow_ref': claims['workflow_ref'], 'job_workflow_sha': claims['workflow_sha']}):
                progress = auth.AuthorizationProgress()
                with self.subTest(actor=actor, optional=optional), self.assertRaisesRegex(ValueError, 'operator'):
                    self.verify(self.token(dict(claims, actor_id=actor, **optional)), policy=policy, progress=progress)
                self.assertEqual(vars(progress), {'stage': 'oidc_identity', 'reason': 'OIDC_OPERATOR_MISMATCH'})

    def test_optional_job_workflow_ref_cannot_authorize_a_different_repository_workflow_or_ref(self):
        values = ['other/repository/'+auth.WORKFLOW+'@refs/heads/main',
                  auth.REPOSITORY+'/.github/workflows/ci.yml@refs/heads/main',
                  auth.REPOSITORY+'/'+auth.WORKFLOW+'@refs/heads/feature',
                  auth.REPOSITORY+'/'+auth.WORKFLOW+'@refs/tags/main',
                  auth.REPOSITORY+'/'+auth.WORKFLOW+'@'+'d'*40,
                  auth.REPOSITORY+'/'+auth.WORKFLOW+'@refs/heads/main/extra', '', None, 123]
        for value in values:
            progress = auth.AuthorizationProgress()
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, 'job workflow'):
                self.verify(self.token(dict(claims_for(request()), job_workflow_ref=value)), progress=progress)
            self.assertEqual(vars(progress), {'stage': 'oidc_identity', 'reason': 'OIDC_JOB_WORKFLOW_REF_MISMATCH'})

    def test_optional_job_workflow_sha_must_match_validated_workflow_sha_with_or_without_job_ref(self):
        claims = claims_for(request())
        for value in ('f'*40, 'd'*39, '', None, 123):
            for optional in ({}, {'job_workflow_ref': claims['workflow_ref']}):
                progress = auth.AuthorizationProgress()
                with self.subTest(value=value, optional=optional), self.assertRaisesRegex(ValueError, 'job workflow revision'):
                    self.verify(self.token(dict(claims, job_workflow_sha=value, **optional)), progress=progress)
                self.assertEqual(vars(progress), {'stage': 'oidc_identity', 'reason': 'OIDC_JOB_WORKFLOW_SHA_MISMATCH'})

    def test_matching_optional_job_identity_does_not_bypass_independent_api_operator_verification(self):
        policy = dict(POLICY, repository_id=1435463595, operator_ids=[8923839], runner_ids=[21])
        payload = request(deployment_run_id=41384659499)
        claims = dict(claims_for(payload), repository_id='1435463595', actor_id='8923839',
                      check_run_id='125084866857', sha='8f0edb99377512b6ec93653f9de904ca7573214b',
                      workflow_sha='8f0edb99377512b6ec93653f9de904ca7573214b')
        claims.update(job_workflow_ref=claims['workflow_ref'], job_workflow_sha=claims['workflow_sha'])
        verified = self.verify(self.token(claims), payload=payload, policy=policy)
        run = dict(deployment_run(), id=payload['deployment_run_id'], head_sha=verified['sha'],
                   repository={'id': 1435463595}, head_repository={'id': 1435463595},
                   actor={'id': 8923839}, triggering_actor={'id': 8923839})
        job = dict(deployment_job(), run_id=payload['deployment_run_id'], runner_id=21,
                   check_run_url='https://api.github.com/repos/'+auth.REPOSITORY+'/check-runs/125084866857')
        for change in (None, 'actor', 'triggering_actor'):
            current = copy.deepcopy(run)
            if change is not None:
                current[change]['id'] = 999
            api = auth.GitHub('synthetic-read-token')
            progress = auth.AuthorizationProgress()
            with self.subTest(change=change), \
                    patch.object(api, 'get', side_effect=[{'id': 1435463595, 'full_name': auth.REPOSITORY, 'private': True},
                             {'protected': True, 'commit': {'sha': verified['sha']}}, current]), \
                    patch.object(api, 'collection', return_value={'jobs': [job]}) as jobs:
                if change is None:
                    auth.workflow_current(api, payload, verified, policy, progress=progress)
                    jobs.assert_called_once()
                else:
                    with self.assertRaisesRegex(ValueError, 'unauthorized'):
                        auth.workflow_current(api, payload, verified, policy, progress=progress)
                    self.assertEqual(progress.reason, 'DEPLOYMENT_RUN_MISMATCH')
                    jobs.assert_not_called()

    def test_wrong_subject_owner_repository_environment_and_legacy_format_are_audited_and_refused(self):
        subjects = [
            'repo:pintybhatt@8923840/psg-document-control@1435463595:environment:uat',
            'repo:pintybhatt@8923839/psg-document-control@1435463596:environment:uat',
            'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:production',
            'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:UAT',
            'repo:other-owner@8923839/psg-document-control@1435463595:environment:uat',
            'repo:pintybhatt@8923839/other-repository@1435463595:environment:uat',
            'repo:'+auth.REPOSITORY+':environment:uat',
            'repo:pintybhatt@8923839/psg-document-control@1435463595:ref:refs/heads/main',
            'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:uat:extra',
        ]
        for subject in subjects:
            progress = auth.AuthorizationProgress()
            with self.subTest(subject=subject), self.assertRaisesRegex(ValueError, 'identity'):
                self.verify(self.token(dict(claims_for(request()), sub=subject)), progress=progress)
            self.assertEqual((progress.stage, progress.reason), ('oidc_identity', 'OIDC_SUBJECT_MISMATCH'))

    def test_each_identity_mismatch_has_a_fixed_reason_without_storing_claim_values(self):
        reasons = {'iss': 'OIDC_ISSUER_MISMATCH', 'aud': 'OIDC_AUDIENCE_MISMATCH',
                   'repository': 'OIDC_REPOSITORY_MISMATCH', 'repository_id': 'OIDC_REPOSITORY_ID_MISMATCH',
                   'event_name': 'OIDC_EVENT_MISMATCH', 'ref': 'OIDC_REF_MISMATCH', 'ref_type': 'OIDC_REF_TYPE_MISMATCH',
                   'repository_visibility': 'OIDC_VISIBILITY_MISMATCH', 'sub': 'OIDC_SUBJECT_MISMATCH',
                   'runner_environment': 'OIDC_RUNNER_ENVIRONMENT_MISMATCH', 'workflow_ref': 'OIDC_WORKFLOW_REF_MISMATCH',
                   'run_id': 'OIDC_RUN_ID_MISMATCH', 'run_attempt': 'OIDC_RUN_ATTEMPT_MISMATCH',
                   'actor_id': 'OIDC_OPERATOR_MISMATCH', 'workflow_sha': 'OIDC_WORKFLOW_REVISION_INVALID',
                   'check_run_id': 'OIDC_CHECK_RUN_ID_INVALID', 'job_workflow_ref': 'OIDC_JOB_WORKFLOW_REF_MISMATCH',
                   'job_workflow_sha': 'OIDC_JOB_WORKFLOW_SHA_MISMATCH'}
        for key, reason in reasons.items():
            progress = auth.AuthorizationProgress()
            value = '' if key == 'check_run_id' else 'synthetic-sensitive-claim'
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(self.token(dict(claims_for(request()), **{key: value})), progress=progress)
            self.assertEqual(vars(progress), {'stage': 'oidc_identity', 'reason': reason})

    def test_non_string_check_run_and_missing_workflow_sha_are_diagnosed_and_still_refused(self):
        for field, value, reason in [('check_run_id', 555, 'OIDC_CHECK_RUN_ID_INVALID'),
                                     ('check_run_id', None, 'OIDC_CHECK_RUN_ID_INVALID'),
                                     ('workflow_sha', None, 'OIDC_WORKFLOW_REVISION_INVALID')]:
            progress = auth.AuthorizationProgress()
            with self.subTest(field=field, value=value), self.assertRaises((ValueError, TypeError)):
                self.verify(self.token(dict(claims_for(request()), **{field: value})), progress=progress)
            self.assertEqual(progress.reason, reason)

    def test_header_jwks_signature_and_time_failures_keep_distinct_stages(self):
        progress = auth.AuthorizationProgress()
        with self.assertRaises(ValueError):
            self.verify(self.token(header={'alg': 'none'}), progress=progress)
        self.assertEqual((progress.stage, progress.reason), ('oidc_header', 'OIDC_HEADER_INVALID'))
        with patch.object(auth, 'read_https', side_effect=OSError('synthetic-secret')), self.assertRaises(OSError):
            auth.verify_oidc(self.token(), request(), POLICY, now=NOW, progress=progress)
        self.assertEqual((progress.stage, progress.reason), ('oidc_jwks', 'OIDC_JWKS_UNAVAILABLE'))
        with self.assertRaises(ValueError):
            self.verify(self.token(), jwks=[], progress=progress)
        self.assertEqual(progress.reason, 'OIDC_SIGNING_KEY_INVALID')
        parts = self.token().split('.')
        parts[-1] = base64.urlsafe_b64encode(bytes(256)).decode().rstrip('=')
        with self.assertRaises(ValueError):
            self.verify('.'.join(parts), progress=progress)
        self.assertEqual((progress.stage, progress.reason), ('oidc_signature', 'OIDC_SIGNATURE_INVALID'))
        with self.assertRaises(ValueError):
            self.verify(self.token(dict(claims_for(request()), iat=NOW+1)), progress=progress)
        self.assertEqual((progress.stage, progress.reason), ('oidc_time', 'OIDC_TIME_INVALID'))

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

    def test_actual_github_job_metadata_shape_matches_all_pinned_ids(self):
        policy = dict(POLICY, repository_id=1435463595, operator_ids=[8923839], runner_ids=[21])
        payload = request(deployment_run_id=41380288154)
        claims = dict(claims_for(payload), repository_id='1435463595', actor_id='8923839',
                      check_run_id='125069452934', sha='b2d775ae394660e76bd89c6071772d49adac828c')
        run = dict(deployment_run(), id=41380288154, head_sha=claims['sha'], repository={'id': 1435463595},
                   head_repository={'id': 1435463595}, actor={'id': 8923839}, triggering_actor={'id': 8923839})
        job = dict(deployment_job(), run_id=41380288154, runner_id=21,
                   check_run_url='https://api.github.com/repos/'+auth.REPOSITORY+'/check-runs/125069452934')
        # Completed historical API responses cannot authorize a new deployment. Only status is simulated here.
        api = self.api()
        with patch.object(api, 'get', side_effect=[{'id': 1435463595, 'full_name': auth.REPOSITORY, 'private': True},
                         {'protected': True, 'commit': {'sha': claims['sha']}}, run]), \
                patch.object(api, 'collection', return_value={'jobs': [job]}):
            auth.workflow_current(api, payload, claims, policy)

    def test_job_attempt_missing_check_run_mismatch_and_wrong_runner_are_distinguishable(self):
        for change, reason in [('attempt-missing', 'DEDICATED_RUNNER_MISMATCH'),
                               ('check-run', 'CHECK_RUN_MATCH_INVALID'), ('runner', 'DEDICATED_RUNNER_MISMATCH')]:
            job = deployment_job()
            if change == 'attempt-missing': del job['run_attempt']
            if change == 'check-run': job['check_run_url'] += '9'
            if change == 'runner': job['runner_id'] = 999
            progress = auth.AuthorizationProgress()
            api = self.api()
            with self.subTest(change=change), \
                    patch.object(api, 'get', side_effect=[{'id': 42, 'full_name': auth.REPOSITORY, 'private': True},
                             {'protected': True, 'commit': {'sha': 'd'*40}}, deployment_run()]), \
                    patch.object(api, 'collection', return_value={'jobs': [job]}), self.assertRaises(ValueError):
                auth.workflow_current(api, request(), claims_for(request()), POLICY, progress=progress)
            self.assertEqual((progress.stage, progress.reason), ('github_job', reason))

    def test_api_permission_failure_has_fixed_stage_instead_of_sensitive_response(self):
        api = self.api()
        progress = auth.AuthorizationProgress()
        with patch.object(api, 'get', side_effect=urllib.error.HTTPError('https://api.github.com/synthetic', 403,
                          'synthetic-secret', {}, io.BytesIO())), self.assertRaises(urllib.error.HTTPError) as result:
            auth.workflow_current(api, request(), claims_for(request()), POLICY, progress=progress)
        result.exception.close()
        self.assertEqual(vars(progress), {'stage': 'github_repository', 'reason': 'GITHUB_REPOSITORY_UNAVAILABLE'})

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
