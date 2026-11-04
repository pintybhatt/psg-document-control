#!/usr/bin/env python3
"""Select authenticated release evidence, or dispatch the existing authorized publisher."""
import datetime
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import time
import urllib.parse
import urllib.request


SPEC = importlib.util.spec_from_file_location('uat_selection_auth', Path(__file__).with_name('github-uat-auth.py'))
auth = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(auth)
RELEASE_WORKFLOW = 'actions/workflows/release-image.yml'


def current_main(api, source):
    auth.require(re.fullmatch(r'[a-f0-9]{40}', source), 'Invalid source identity.')
    branch = api.get('branches/main')
    auth.require(branch.get('protected') is True and branch['commit']['sha'] == source,
                 'Dispatch must use current protected main; dispatch again after main changes.')


def runs(api, **filters):
    rows = []
    for page in range(1, 11):
        result = api.get(RELEASE_WORKFLOW+'/runs?'+urllib.parse.urlencode(dict(filters, per_page=100, page=page)))
        batch = result['workflow_runs']
        rows.extend(batch)
        if len(batch) < 100:
            return sorted(rows, key=lambda run: run['id'], reverse=True)
    raise ValueError('Release search exceeded its bound; review publication history.')


def eligible(api, run_id, artifact_id, source=None, now=None):
    release = api.release(run_id, artifact_id)
    manifest = auth.parse_json(release['manifest'])
    if source is not None:
        auth.require(manifest['source_commit'] == source, 'Release source differs from current main.')
    started = datetime.datetime.fromisoformat(release['run']['run_started_at'].replace('Z', '+00:00'))
    now = datetime.datetime.now(datetime.timezone.utc) if now is None else now
    # Match the default server policy; the server independently enforces its enrolled limit.
    auth.require(started.tzinfo is not None and 0 <= (now-started).total_seconds() <= 168*3600,
                 'Release exceeds the seven-day selection window.')
    for commit in (manifest['source_commit'], manifest['release_workflow_commit']):
        auth.require(api.get('compare/'+commit+'...main').get('status') in ('ahead', 'identical'),
                     'Release revision is outside main ancestry.')
    return run_id, artifact_id


def select(api, source, now=None, candidates=None):
    candidates = runs(api, branch='main', event='workflow_dispatch', status='success') if candidates is None else candidates
    for run in candidates:
        artifacts = api.collection('actions/runs/'+str(run['id'])+'/artifacts', 'artifacts')['artifacts']
        for artifact in artifacts:
            if artifact.get('expired') is not False or not artifact.get('name', '').startswith('release-manifest-') or not artifact.get('name', '').endswith('-'+source):
                continue
            try:
                return eligible(api, run['id'], artifact['id'], source, now)
            except (ValueError, KeyError, TypeError):
                # Invalid historical evidence is never used. HTTP/auth failures stop selection.
                continue
    return None


def dispatch(api, source, version, reference):
    payload = {'ref': 'main', 'inputs': {'source_commit': source, 'baseline_commit': source,
               'version': version, 'approval_reference': reference, 'allow_initial_private_package': False}}
    request = urllib.request.Request('https://api.github.com/repos/'+auth.REPOSITORY+'/'+RELEASE_WORKFLOW+'/dispatches',
              data=json.dumps(payload).encode(), method='POST', headers={
                  'Authorization': 'Bearer '+api.token, 'Accept': 'application/vnd.github+json',
                  'X-GitHub-Api-Version': '2022-11-28', 'Content-Type': 'application/json', 'User-Agent': 'XPPL-DCMS-UAT'})
    with urllib.request.build_opener(auth.NoRedirect).open(request, timeout=30) as response:
        auth.require(response.status == 204, 'Publication dispatch was not accepted.')


def publish(api, source, run_id, attempt, timeout=4800):
    auth.require(all(re.fullmatch(r'[1-9][0-9]{0,19}', value) for value in (run_id, attempt)), 'Invalid dispatch identity.')
    version = 'v0.0.'+run_id+'-rc.'+attempt
    reference = 'DCMS-UAT-'+run_id+'-'+attempt
    title = 'DCMS release '+version+' '+source
    current_main(api, source)
    dispatch(api, source, version, reference)
    deadline = time.monotonic()+timeout
    observed = None
    while time.monotonic() < deadline:
        current_main(api, source)
        matches = [run for run in runs(api, branch='main', event='workflow_dispatch', head_sha=source)
                   if run.get('display_title') == title]
        auth.require(len(matches) <= 1, 'Ambiguous automatic publication; deployment refused.')
        if matches:
            run = matches[0]
            auth.require(observed in (None, run['id']), 'Publication run changed.')
            if observed is None:
                print('Publication evidence: https://github.com/'+auth.REPOSITORY+'/actions/runs/'+str(run['id']), flush=True)
            observed = run['id']
            if run['status'] == 'completed':
                auth.require(run['conclusion'] == 'success', 'Authorized publication failed, was disabled or cancelled; see release run.')
                result = select(api, source, candidates=[run])
                auth.require(result is not None, 'Publication has no eligible authenticated release evidence.')
                return result
        time.sleep(15)
    raise ValueError('Timed out waiting for authorized publication. No deployment attempted; inspect the release run before retrying.')


def output(selection):
    with open(os.environ['GITHUB_OUTPUT'], 'a') as stream:
        stream.write('release_run_id='+str(selection[0] if selection else '')+'\n')
        stream.write('release_artifact_id='+str(selection[1] if selection else '')+'\n')
        stream.write('needs_publication='+str(selection is None).lower()+'\n')


def main():
    try:
        api = auth.GitHub(os.environ['GH_TOKEN'])
        source = os.environ['GITHUB_SHA']
        current_main(api, source)
        if sys.argv[1:] == ['publish']:
            result = publish(api, source, os.environ['GITHUB_RUN_ID'], os.environ['GITHUB_RUN_ATTEMPT'])
        else:
            auth.require(not sys.argv[1:], 'Unexpected selector arguments.')
            operation = os.environ['OPERATION']
            run_id, artifact_id = (os.environ.get(name, '') for name in ('RELEASE_RUN_ID', 'RELEASE_ARTIFACT_ID'))
            reference = os.environ.get('ROLLBACK_COMPATIBILITY_REFERENCE', '')
            auth.require(operation in ('deploy', 'rollback'), 'Invalid deployment operation.')
            if operation == 'rollback':
                auth.require(all(re.fullmatch(r'[1-9][0-9]{0,19}', value) for value in (run_id, artifact_id))
                             and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', reference),
                             'Advanced rollback requires previous release IDs and a compatibility review reference.')
                result = eligible(api, int(run_id), int(artifact_id))
            else:
                auth.require(not run_id and not artifact_id and not reference, 'Normal deployment does not accept manual release overrides.')
                result = select(api, source)
        output(result)
        print('Authenticated release selected.' if result else 'No eligible image for current main; authorized publication is required.')
    except Exception as error:
        # Only fixed diagnostics from this module may be displayed; never HTTP bodies or credentials.
        message = str(error) if type(error) is ValueError else ''
        # Keep external evidence and imported verifier exceptions out of the log.
        if message not in SAFE_ERRORS:
            message = 'Authenticated release selection/publication failed; no deployment authorized.'
        sys.exit('::error::'+message)


SAFE_ERRORS = {
    'Dispatch must use current protected main; dispatch again after main changes.',
    'Advanced rollback requires previous release IDs and a compatibility review reference.',
    'Normal deployment does not accept manual release overrides.',
    'Authorized publication failed, was disabled or cancelled; see release run.',
    'Publication has no eligible authenticated release evidence.',
    'Timed out waiting for authorized publication. No deployment attempted; inspect the release run before retrying.',
}


if __name__ == '__main__':
    main()
