#!/usr/bin/env python3
"""Derive a release digest from GitHub evidence and bind dispatch to GitHub OIDC."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import urllib.parse


SPEC = importlib.util.spec_from_file_location('github_uat_auth', Path(__file__).with_name('github-uat-auth.py'))
auth = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(auth)


def prepare(run_id, artifact_id, operation, rollback_reference=''):
    auth.require(all(re.fullmatch(r'[1-9][0-9]{0,19}', value) for value in (run_id, artifact_id)), 'Invalid release/artifact ID.')
    auth.require(operation in ('deploy', 'rollback'), 'Invalid operation.')
    api = auth.GitHub(os.environ['GH_TOKEN'])
    release = api.release(int(run_id), int(artifact_id))
    manifest = auth.parse_json(release['manifest'])
    branch = api.get('branches/main')
    auth.require(branch.get('protected') is True and branch['commit']['sha'] == os.environ['GITHUB_SHA'],
                 'Dispatch must match current protected main.')
    if operation == 'deploy':
        auth.require(manifest['source_commit'] == os.environ['GITHUB_SHA'], 'Forward deployment must use current main source.')
    request = {'deployment_run_id': int(os.environ['GITHUB_RUN_ID']),
               'deployment_run_attempt': int(os.environ['GITHUB_RUN_ATTEMPT']),
               'release_run_id': int(run_id), 'release_run_attempt': release['run']['run_attempt'],
               'release_artifact_id': int(artifact_id), 'image': manifest['image'],
               'manifest_sha256': hashlib.sha256(release['manifest']).hexdigest(), 'operation': operation,
               'rollback_compatibility_reference': rollback_reference}
    auth.validate_request(request)
    url = os.environ['ACTIONS_ID_TOKEN_REQUEST_URL']
    parsed = urllib.parse.urlsplit(url)
    auth.require(parsed.scheme == 'https' and parsed.hostname is not None
                 and parsed.hostname.endswith('.actions.githubusercontent.com') and parsed.port in (None, 443)
                 and not parsed.username and not parsed.password and not parsed.fragment, 'Untrusted OIDC request URL.')
    token_url = url+('&' if parsed.query else '?')+urllib.parse.urlencode({'audience': auth.audience(request)})
    token = auth.parse_json(auth.read_https(token_url, os.environ['ACTIONS_ID_TOKEN_REQUEST_TOKEN'], limit=32768))['value']
    auth.require(isinstance(token, str) and len(token) <= 16384, 'Invalid OIDC response.')
    return {'request': request, 'oidc_token': token, 'github_token': os.environ['GH_TOKEN']}


def main():
    try:
        print(json.dumps(prepare(*(os.environ[name] for name in ('RELEASE_RUN_ID', 'RELEASE_ARTIFACT_ID', 'OPERATION')),
                                 os.environ.get('ROLLBACK_COMPATIBILITY_REFERENCE', ''))))
    except Exception:
        # Never log the request envelope, runtime token, downloaded content or exception body.
        sys.exit('STOP: GitHub release/OIDC verification failed; no deployment request emitted.')


if __name__ == '__main__':
    main()
