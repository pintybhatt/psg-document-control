#!/usr/bin/env python3
"""Fixed GitHub UAT trust boundary: authenticated evidence and RS256 OIDC verification."""
import base64
import datetime
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile


SPEC = importlib.util.spec_from_file_location('release_evidence', Path(__file__).with_name('verify-uat-release.py'))
evidence = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(evidence)
require = evidence.require
REPOSITORY = evidence.REPOSITORY
WORKFLOW = '.github/workflows/deploy-uat.yml'
ISSUER = 'https://token.actions.githubusercontent.com'
AUDIENCE = 'https://github.com/'+REPOSITORY+'/uat/'
OIDC_FUTURE_SKEW_SECONDS = 30
REQUEST_KEYS = {'deployment_run_id', 'deployment_run_attempt', 'release_run_id', 'release_run_attempt',
                'release_artifact_id', 'image', 'manifest_sha256', 'operation', 'rollback_compatibility_reference'}


class SchemaReviewRequired(ValueError):
    """Schema refusal with a fixed public diagnostic; never display exception contents."""


class AuthorizationProgress:
    """Fixed diagnostics only; never store claims, requests, credentials or exceptions."""

    REASONS = {
        'request': {'REQUEST_INVALID'},
        'entrypoint': {'ROOT_ENTRYPOINT_REQUIRED'},
        'custody': {'ROOT_CUSTODY_INVALID'},
        'lock': {'DEPLOYMENT_LOCK_UNAVAILABLE'},
        'recovery': {'RECOVERY_REQUIRED'},
        'configuration': {'CONFIGURATION_INVALID', 'HEALTH_URL_INVALID', 'AUTOMATION_DISABLED'},
        'policy': {'POLICY_INVALID'},
        'oidc_header': {'OIDC_HEADER_INVALID'},
        'oidc_jwks': {'OIDC_JWKS_UNAVAILABLE', 'OIDC_SIGNING_KEY_INVALID'},
        'oidc_signature': {'OIDC_SIGNATURE_INVALID'},
        'oidc_time': {'OIDC_TIME_INVALID', 'OIDC_EXPIRED', 'OIDC_ISSUED_IN_FUTURE',
                      'OIDC_NOT_YET_VALID', 'OIDC_TOO_OLD', 'OIDC_LIFETIME_INVALID'},
        'oidc_identity': {
            'OIDC_ISSUER_MISMATCH', 'OIDC_AUDIENCE_MISMATCH', 'OIDC_REPOSITORY_MISMATCH',
            'OIDC_REPOSITORY_ID_MISMATCH', 'OIDC_EVENT_MISMATCH', 'OIDC_REF_MISMATCH',
            'OIDC_REF_TYPE_MISMATCH', 'OIDC_VISIBILITY_MISMATCH', 'OIDC_SUBJECT_MISMATCH',
            'OIDC_RUNNER_ENVIRONMENT_MISMATCH', 'OIDC_WORKFLOW_REF_MISMATCH',
            'OIDC_RUN_ID_MISMATCH', 'OIDC_RUN_ATTEMPT_MISMATCH', 'OIDC_OPERATOR_MISMATCH',
            'OIDC_JOB_WORKFLOW_REF_MISMATCH', 'OIDC_JOB_WORKFLOW_SHA_MISMATCH',
            'OIDC_WORKFLOW_REVISION_INVALID', 'OIDC_CHECK_RUN_ID_INVALID',
        },
        'github_credential': {'GITHUB_CREDENTIAL_INVALID'},
        'github_repository': {'GITHUB_REPOSITORY_UNAVAILABLE'},
        'github_main': {'GITHUB_MAIN_UNAVAILABLE', 'PROTECTED_MAIN_MISMATCH'},
        'github_run': {'GITHUB_RUN_UNAVAILABLE', 'DEPLOYMENT_RUN_MISMATCH'},
        'github_job': {'GITHUB_JOBS_UNAVAILABLE', 'CHECK_RUN_MATCH_INVALID', 'DEDICATED_RUNNER_MISMATCH'},
        'release_evidence': {'RELEASE_EVIDENCE_INVALID'},
        'release_validation': {'RELEASE_VALIDATION_FAILED'},
        'history': {'HISTORY_RESERVATION_FAILED'},
        'deployment': {'DEPLOYMENT_FAILED'},
    }

    def __init__(self):
        self.mark('request', 'REQUEST_INVALID')

    def mark(self, stage, reason):
        require(reason in self.REASONS.get(stage, set()), 'Unknown diagnostic code.')
        self.stage, self.reason = stage, reason


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate JSON key.')
        result[key] = value
    return result


def parse_json(data):
    return json.loads(data, object_pairs_hook=unique_object)


def validate_request(request):
    require(isinstance(request, dict) and set(request) == REQUEST_KEYS, 'Unexpected request fields.')
    for key in ('deployment_run_id', 'deployment_run_attempt', 'release_run_id', 'release_run_attempt', 'release_artifact_id'):
        require(type(request[key]) is int and 0 < request[key] < 10**20, 'Invalid run/artifact identity.')
    require(isinstance(request['image'], str) and re.fullmatch(evidence.IMAGE, request['image']), 'Invalid image digest.')
    require(isinstance(request['manifest_sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', request['manifest_sha256']), 'Invalid manifest hash.')
    require(request['operation'] in ('deploy', 'rollback'), 'Invalid operation.')
    reference = request['rollback_compatibility_reference']
    require(isinstance(reference, str) and ((request['operation'] == 'deploy' and reference == '')
            or (request['operation'] == 'rollback' and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', reference))),
            'Rollback requires an explicit operator compatibility review reference.')


def audience(request):
    validate_request(request)
    return AUDIENCE+hashlib.sha256(json.dumps(request, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        return None


def read_https(url, token=None, limit=2*1024*1024):
    headers = {'Accept': 'application/json', 'User-Agent': 'XPPL-DCMS-UAT'}
    if url.startswith('https://api.github.com/'):
        headers.update({'Accept': 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28'})
    if token is not None:
        headers['Authorization'] = 'Bearer '+token
    request = urllib.request.Request(url, headers=headers)
    with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
        data = response.read(limit+1)
    require(len(data) <= limit, 'Oversized HTTPS evidence.')
    return data


class GitHub:
    def __init__(self, token):
        require(isinstance(token, str) and re.fullmatch(r'[A-Za-z0-9_.-]{10,2048}', token), 'Invalid GitHub read credential.')
        self.token = token

    def get(self, path):
        return parse_json(read_https('https://api.github.com/repos/'+REPOSITORY+('/'+path if path else ''), self.token))

    def collection(self, path, key):
        rows = []
        total = None
        for page in range(1, 11):
            result = self.get(path+'?per_page=100&page='+str(page))
            require(type(result.get('total_count')) is int and result['total_count'] >= 0, 'Invalid API result count.')
            total = result['total_count'] if total is None else total
            require(result['total_count'] == total, 'API evidence changed during pagination.')
            rows += result[key]
            if len(rows) >= result['total_count']:
                require(len(rows) == result['total_count'], 'Inconsistent API pagination.')
                return {'total_count': len(rows), key: rows}
            require(len(result[key]) == 100, 'Incomplete API pagination.')
        raise ValueError('Too many evidence pages.')

    def manifest(self, artifact_id):
        # GitHub returns an authenticated redirect to a short-lived artifact URL.
        # Never forward Authorization to the download host or accept arbitrary redirects.
        try:
            read_https('https://api.github.com/repos/'+REPOSITORY+'/actions/artifacts/'+str(artifact_id)+'/zip', self.token)
        except urllib.error.HTTPError as error:
            with error:
                require(error.code == 302, 'Artifact download unavailable.')
                location = error.headers.get('Location', '')
        else:
            raise ValueError('Expected GitHub artifact redirect.')
        url = urllib.parse.urlsplit(location)
        require(url.scheme == 'https' and not url.username and not url.password and url.port in (None, 443)
                and url.hostname is not None and (url.hostname.endswith('.blob.core.windows.net')
                or url.hostname.endswith('.actions.githubusercontent.com') or url.hostname == 'objects.githubusercontent.com'),
                'Untrusted artifact download host.')
        archive_bytes = read_https(location)
        with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
            require(archive.namelist() == ['release-manifest.json'], 'Unexpected manifest archive members.')
            require(archive.getinfo('release-manifest.json').file_size <= 1024*1024, 'Oversized manifest.')
            return archive.read('release-manifest.json')

    def release(self, run_id, artifact_id):
        run = self.get('actions/runs/'+str(run_id))
        attempt = run['run_attempt']
        require(type(attempt) is int and attempt > 0, 'Invalid release attempt.')
        jobs = self.collection('actions/runs/'+str(run_id)+'/attempts/'+str(attempt)+'/jobs', 'jobs')
        artifacts = self.collection('actions/runs/'+str(run_id)+'/artifacts', 'artifacts')
        require(any(row['id'] == artifact_id and row.get('expired') is False for row in artifacts['artifacts']), 'Artifact unavailable.')
        manifest = self.manifest(artifact_id)
        evidence.verify_publication(manifest, run, jobs, artifacts, artifact_id)
        self.release_current(run)
        return {'manifest': manifest, 'run': run, 'jobs': jobs, 'artifacts': artifacts}

    def release_current(self, run):
        latest = self.get('actions/runs/'+str(run['id']))
        require(latest['run_attempt'] == run['run_attempt'] and latest['status'] == 'completed'
                and latest['conclusion'] == 'success' and latest['head_sha'] == run['head_sha'], 'Stale or changed release run.')


def decode64(value):
    require(isinstance(value, str) and re.fullmatch(r'[A-Za-z0-9_-]+', value), 'Invalid base64url.')
    return base64.b64decode(value+'='*((-len(value)) % 4), altchars=b'-_', validate=True)


def der(tag, value):
    size = len(value)
    if size < 128:
        length = bytes([size])
    else:
        raw = size.to_bytes((size.bit_length()+7)//8, 'big')
        length = bytes([0x80+len(raw)])+raw
    return bytes([tag])+length+value


def public_key(jwk):
    require(jwk.get('kty') == 'RSA' and jwk.get('alg') == 'RS256' and jwk.get('use') == 'sig', 'Invalid OIDC key type.')
    numbers = []
    for name in ('n', 'e'):
        number = decode64(jwk[name]).lstrip(b'\0')
        require(number and len(number) <= 1024, 'Invalid RSA public parameter.')
        if name == 'n':
            require(len(number) >= 256, 'OIDC RSA key too small.')
        numbers.append(der(2, (b'\0' if number[0] & 0x80 else b'')+number))
    rsa = der(0x30, b''.join(numbers))
    return der(0x30, bytes.fromhex('300d06092a864886f70d0101010500')+der(3, b'\0'+rsa))


def verify_oidc(token, request, policy, now=None, progress=None):
    progress = AuthorizationProgress() if progress is None else progress
    progress.mark('oidc_header', 'OIDC_HEADER_INVALID')
    require(isinstance(token, str) and len(token) <= 16384, 'Invalid OIDC token.')
    header64, claims64, signature64 = token.split('.')
    header = parse_json(decode64(header64))
    require(header.get('alg') == 'RS256' and header.get('typ') == 'JWT'
            and set(header) <= {'alg', 'typ', 'kid', 'x5t'} and isinstance(header.get('kid'), str), 'Untrusted JWT header.')
    progress.mark('oidc_jwks', 'OIDC_JWKS_UNAVAILABLE')
    jwks = parse_json(read_https(ISSUER+'/.well-known/jwks', limit=128*1024))
    progress.mark('oidc_jwks', 'OIDC_SIGNING_KEY_INVALID')
    keys = [key for key in jwks['keys'] if key.get('kid') == header['kid']]
    require(len(keys) == 1, 'Unknown/ambiguous OIDC signing key.')
    progress.mark('oidc_signature', 'OIDC_SIGNATURE_INVALID')
    with tempfile.TemporaryDirectory(prefix='dcms-oidc-') as directory:
        key_file, signature_file = Path(directory)/'public.der', Path(directory)/'signature'
        key_file.write_bytes(public_key(keys[0]))
        signature_file.write_bytes(decode64(signature64))
        result = subprocess.run(['/usr/bin/openssl', 'dgst', '-sha256', '-verify', str(key_file), '-keyform', 'DER',
                                 '-signature', str(signature_file)], input=(header64+'.'+claims64).encode('ascii'),
                                capture_output=True, timeout=10, env={'PATH': '/usr/bin:/bin'})
        require(result.returncode == 0, 'OIDC signature verification failed.')
    progress.mark('oidc_time', 'OIDC_TIME_INVALID')
    now = time.time() if now is None else now
    claims = parse_json(decode64(claims64))
    for key in ('iat', 'nbf', 'exp'):
        require(type(claims.get(key)) is int, 'Missing OIDC time claim.')
    progress.mark('oidc_time', 'OIDC_LIFETIME_INVALID')
    require(claims['iat'] < claims['exp'] <= claims['iat']+600 and claims['nbf'] < claims['exp'],
            'Invalid OIDC lifetime.')
    # Future-only skew allowance: expiry and the maximum admission age have no leeway.
    progress.mark('oidc_time', 'OIDC_EXPIRED')
    require(now < claims['exp'], 'Expired OIDC authorization.')
    progress.mark('oidc_time', 'OIDC_ISSUED_IN_FUTURE')
    require(claims['iat'] <= now+OIDC_FUTURE_SKEW_SECONDS, 'OIDC issued too far in future.')
    progress.mark('oidc_time', 'OIDC_NOT_YET_VALID')
    require(claims['nbf'] <= now+OIDC_FUTURE_SKEW_SECONDS, 'OIDC not yet valid.')
    progress.mark('oidc_time', 'OIDC_TOO_OLD')
    require(now-300 <= claims['iat'], 'OIDC authorization too old.')
    expected = {'iss': ISSUER, 'aud': audience(request), 'repository': REPOSITORY,
                'repository_id': str(policy['repository_id']), 'event_name': 'workflow_dispatch',
                'ref': 'refs/heads/main', 'ref_type': 'branch', 'repository_visibility': 'private',
                'sub': 'repo:pintybhatt@8923839/psg-document-control@1435463595:environment:uat',
                'runner_environment': 'self-hosted',
                'workflow_ref': REPOSITORY+'/'+WORKFLOW+'@refs/heads/main',
                'run_id': str(request['deployment_run_id']), 'run_attempt': str(request['deployment_run_attempt'])}
    reasons = ('OIDC_ISSUER_MISMATCH', 'OIDC_AUDIENCE_MISMATCH', 'OIDC_REPOSITORY_MISMATCH',
               'OIDC_REPOSITORY_ID_MISMATCH', 'OIDC_EVENT_MISMATCH', 'OIDC_REF_MISMATCH',
               'OIDC_REF_TYPE_MISMATCH', 'OIDC_VISIBILITY_MISMATCH', 'OIDC_SUBJECT_MISMATCH',
               'OIDC_RUNNER_ENVIRONMENT_MISMATCH', 'OIDC_WORKFLOW_REF_MISMATCH',
               'OIDC_RUN_ID_MISMATCH', 'OIDC_RUN_ATTEMPT_MISMATCH')
    for (key, value), reason in zip(expected.items(), reasons, strict=True):
        progress.mark('oidc_identity', reason)
        require(claims.get(key) == value, 'Unauthorized GitHub workflow identity.')
    progress.mark('oidc_identity', 'OIDC_OPERATOR_MISMATCH')
    require(claims.get('actor_id') in [str(value) for value in policy['operator_ids']], 'Unauthorized operator.')
    progress.mark('oidc_identity', 'OIDC_WORKFLOW_REVISION_INVALID')
    require(re.fullmatch(r'[a-f0-9]{40}', claims.get('sha', '')) and claims.get('workflow_sha') == claims['sha'],
            'Incomplete workflow revision/job identity.')
    # Optional job claims must identify this same main-only deployment, never another called workflow.
    if 'job_workflow_ref' in claims:
        progress.mark('oidc_identity', 'OIDC_JOB_WORKFLOW_REF_MISMATCH')
        require(claims['job_workflow_ref'] == expected['workflow_ref'], 'Unauthorized job workflow identity.')
    if 'job_workflow_sha' in claims:
        progress.mark('oidc_identity', 'OIDC_JOB_WORKFLOW_SHA_MISMATCH')
        require(claims['job_workflow_sha'] == claims['workflow_sha'], 'Unauthorized job workflow revision.')
    progress.mark('oidc_identity', 'OIDC_CHECK_RUN_ID_INVALID')
    require(re.fullmatch(r'[1-9][0-9]{0,19}', claims.get('check_run_id', '')), 'Incomplete workflow revision/job identity.')
    return claims


def validate_policy(policy):
    require(policy.get('authorization') == 'github-oidc' and type(policy.get('repository_id')) is int
            and policy['repository_id'] > 0, 'Pinned GitHub repository identity required.')
    for key in ('operator_ids', 'runner_ids'):
        require(isinstance(policy.get(key), list) and policy[key]
                and all(type(value) is int and value > 0 for value in policy[key]), 'Pinned operator/runner IDs required.')
    require(type(policy.get('maximum_release_age_hours')) is int and 0 < policy['maximum_release_age_hours'] <= 8760,
            'Bounded release age policy required.')
    require(isinstance(policy.get('recovery_reference'), str)
            and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', policy['recovery_reference']), 'Established UAT recovery procedure required.')


def workflow_current(api, request, claims, policy, progress=None):
    progress = AuthorizationProgress() if progress is None else progress
    progress.mark('github_repository', 'GITHUB_REPOSITORY_UNAVAILABLE')
    repository = api.get('')
    progress.mark('github_main', 'GITHUB_MAIN_UNAVAILABLE')
    branch = api.get('branches/main')
    progress.mark('github_run', 'GITHUB_RUN_UNAVAILABLE')
    run = api.get('actions/runs/'+str(request['deployment_run_id']))
    progress.mark('github_main', 'PROTECTED_MAIN_MISMATCH')
    require(repository.get('id') == policy['repository_id'] and repository.get('full_name') == REPOSITORY and repository.get('private') is True
            and branch.get('protected') is True and branch['commit']['sha'] == claims['sha'], 'Protected current main required.')
    progress.mark('github_run', 'DEPLOYMENT_RUN_MISMATCH')
    require(run.get('id') == request['deployment_run_id'] and run.get('path') == WORKFLOW
            and run.get('event') == 'workflow_dispatch' and run.get('head_branch') == 'main'
            and run.get('head_sha') == claims['sha'] and run.get('status') == 'in_progress'
            and run.get('run_attempt') == request['deployment_run_attempt']
            and run.get('repository', {}).get('id') == policy['repository_id']
            and run.get('head_repository', {}).get('id') == policy['repository_id']
            and str(run.get('actor', {}).get('id')) == claims['actor_id']
            and run.get('triggering_actor', {}).get('id') in policy['operator_ids'], 'Deployment workflow is stale/unauthorized.')
    progress.mark('github_job', 'GITHUB_JOBS_UNAVAILABLE')
    jobs = api.collection('actions/runs/'+str(request['deployment_run_id'])+'/attempts/'+str(request['deployment_run_attempt'])+'/jobs', 'jobs')['jobs']
    progress.mark('github_job', 'CHECK_RUN_MATCH_INVALID')
    matches = [job for job in jobs if job.get('check_run_url') == 'https://api.github.com/repos/'+REPOSITORY+'/check-runs/'+claims['check_run_id']]
    require(len(matches) == 1, 'Deployment job identity missing/ambiguous.')
    job = matches[0]
    progress.mark('github_job', 'DEDICATED_RUNNER_MISMATCH')
    require(job.get('name') == 'deploy' and job.get('status') == 'in_progress'
            and job.get('run_id') == request['deployment_run_id'] and job.get('run_attempt') == request['deployment_run_attempt']
            and job.get('runner_id') in policy['runner_ids']
            and {'self-hosted', 'linux', 'x64', 'dcms-uat-runner'} <= {label.lower() for label in job.get('labels', [])},
            'Dedicated trusted deployment runner required.')


def validate_release(api, request, release, policy, current, previous, now=None):
    manifest = parse_json(release['manifest'])
    require(manifest['image'] == request['image'] and hashlib.sha256(release['manifest']).hexdigest() == request['manifest_sha256']
            and release['run']['id'] == request['release_run_id']
            and release['run']['run_attempt'] == request['release_run_attempt'], 'Request/release evidence mismatch.')
    evidence.verify_publication(release['manifest'], release['run'], release['jobs'], release['artifacts'], request['release_artifact_id'])
    started = datetime.datetime.fromisoformat(release['run']['run_started_at'].replace('Z', '+00:00'))
    now = datetime.datetime.now(datetime.timezone.utc) if now is None else now
    require(started.tzinfo is not None and 0 <= (now-started).total_seconds() <= policy['maximum_release_age_hours']*3600,
            'Stale release; select a recent successful publication.')
    for commit in (manifest['source_commit'], manifest['release_workflow_commit']):
        require(api.get('compare/'+commit+'...main').get('status') in ('ahead', 'identical'), 'Release must belong to main ancestry.')
    require(manifest['image'] != current['image'], 'Release is already current.')
    if manifest['migration_inventory'] != current['migration_inventory']:
        existing = current['migration_inventory']
        candidate = manifest['migration_inventory']
        if (request['operation'] != 'deploy' or policy.get('automatic_migrations') is not True
                or not existing or candidate[:len(existing)] != existing
                or len(candidate) <= len(existing)
                or len({row['path'] for row in candidate}) != len(candidate)
                or any(not re.fullmatch(r'database/migrations/[a-zA-Z0-9_]+\.php', row['path'])
                       or not re.fullmatch(r'[a-f0-9]{64}', row['sha256']) for row in candidate)
                or [row['path'] for row in candidate] != sorted(row['path'] for row in candidate)):
            raise SchemaReviewRequired('Schema inventory differs; separate controlled migration required.')
    if request['operation'] == 'rollback':
        require(previous is not None and manifest == previous, 'Rollback limited to the recorded previous release.')
    else:
        require(api.get('compare/'+current['source_commit']+'...'+manifest['source_commit']).get('status') in ('ahead', 'identical'),
                'Source downgrade refused; use an eligible previous-release rollback.')
    return manifest
