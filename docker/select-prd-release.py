#!/usr/bin/env python3
"""Read-only Production ref/publication selection. Never build, publish or contact Production."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import urllib.error
import urllib.parse


spec = importlib.util.spec_from_file_location('prd_release_contract', Path(__file__).with_name('deploy-prd.py'))
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
auth = runtime.shared.auth
SHA = r'[a-f0-9]{40}'
ERRORS = {
    'context': 'Run Deploy DCMS PRD from this repository on main with confirmation DEPLOY_PRD.',
    'workflow': 'The deployment must use the current protected main workflow definition and active run attempt.',
    'reference': 'Use main, an existing eligible tag (optionally refs/tags/NAME), or a full 40-character commit SHA; branches and short SHAs are refused.',
    'ancestry': 'The requested commit or release workflow revision is outside approved main history.',
    'search': 'Publication history changed or exceeded the bounded search; no Production connection was attempted.',
    'missing': 'No published release evidence exists for the exact requested commit; no image will be built or substituted.',
    'ambiguous': 'Published release artifacts are ambiguous; no Production connection was attempted.',
    'expired': 'The selected publication artifact is expired or missing; no Production connection was attempted.',
    'publication': 'The selected publication failed, is incomplete or cannot be verified; inspect its CI/publication evidence.',
    'binding': 'Publication source, manifest, artifact or run attempt differs from the selected release.',
    'moved': 'The requested reference changed during selection; dispatch again after reviewing the reference.',
}


class SelectionError(ValueError):
    """Only fixed diagnostic codes may cross the workflow logging boundary."""


def check(condition, reason):
    if not condition:
        raise SelectionError(reason)


def context(environment):
    check(environment.get('GITHUB_EVENT_NAME') == 'workflow_dispatch'
          and environment.get('GITHUB_REPOSITORY') == auth.REPOSITORY
          and environment.get('GITHUB_REF') == 'refs/heads/main'
          and environment.get('CONFIRM_DEPLOY') == 'DEPLOY_PRD'
          and re.fullmatch(SHA, environment.get('GITHUB_SHA', ''))
          and environment.get('WORKFLOW_REF') == auth.REPOSITORY+'/'+runtime.WORKFLOW+'@refs/heads/main'
          and environment.get('WORKFLOW_SHA') == environment['GITHUB_SHA'], 'context')
    for key in ('GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'):
        check(re.fullmatch(r'[1-9][0-9]{0,19}', environment.get(key, '')), 'context')
    return {'deployment_run_id': int(environment['GITHUB_RUN_ID']),
            'deployment_run_attempt': int(environment['GITHUB_RUN_ATTEMPT'])}


def approved_workflow(api, environment, identities):
    try:
        runtime.workflow_current(api, identities)
        run = api.get('actions/runs/'+str(identities['deployment_run_id']))
        branch = api.get('branches/main')
        repository = api.get('')
        check(repository.get('full_name') == auth.REPOSITORY and repository.get('private') is True
              and branch.get('protected') is True and branch['commit']['sha'] == environment['GITHUB_SHA']
              and run.get('head_sha') == environment['GITHUB_SHA'], 'workflow')
    except (ValueError, KeyError, TypeError):
        raise SelectionError('workflow') from None


def ancestry(api, source, main):
    result = api.get('compare/'+source+'...'+main)
    check(result.get('status') in ('ahead', 'identical')
          and result.get('merge_base_commit', {}).get('sha') == source, 'ancestry')


def resolve(api, reference, main):
    check(isinstance(reference, str) and 0 < len(reference) <= 200, 'reference')
    if reference == 'main':
        source = main
    elif re.fullmatch(r'[a-fA-F0-9]{40}', reference):
        source = reference.lower()
    else:
        explicit = reference.startswith('refs/tags/')
        tag = reference[len('refs/tags/'):] if explicit else reference
        check((explicit or not reference.startswith('refs/'))
              and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]*', tag)
              and (explicit or not re.fullmatch(r'[a-fA-F0-9]+', tag))
              and '..' not in tag and '//' not in tag and not tag.endswith(('/', '.'))
              and all(not part.startswith('.') and not part.endswith('.lock') for part in tag.split('/')), 'reference')
        try:
            result = api.get('git/ref/tags/'+urllib.parse.quote(tag, safe='/'))
            check(isinstance(result, dict) and result.get('ref') == 'refs/tags/'+tag, 'reference')
            item = result['object']
            seen = set()
            for _ in range(10):
                check(re.fullmatch(SHA, item.get('sha', '')) and item['sha'] not in seen, 'reference')
                seen.add(item['sha'])
                if item.get('type') == 'commit':
                    break
                check(item.get('type') == 'tag', 'reference')
                annotated = api.get('git/tags/'+item['sha'])
                check(annotated.get('sha') == item['sha'], 'reference')
                item = annotated['object']
            else:
                raise SelectionError('reference')
            source = item['sha']
        except urllib.error.HTTPError as error:
            with error:
                if error.code == 404:
                    raise SelectionError('reference') from None
                raise
    check(re.fullmatch(SHA, source), 'reference')
    commit = api.get('git/commits/'+source)
    check(commit.get('sha') == source, 'reference')
    ancestry(api, source, main)
    return source


def runs(api, workflow, **filters):
    """Use the existing selector's bounded, newest-first REST search convention; GET only."""
    rows, total = [], None
    for page in range(1, 11):
        result = api.get('actions/workflows/'+workflow+'/runs?'+urllib.parse.urlencode(dict(filters, per_page=100, page=page)))
        check(type(result.get('total_count')) is int and result['total_count'] >= 0, 'search')
        total = result['total_count'] if total is None else total
        check(result['total_count'] == total, 'search')
        batch = result['workflow_runs']
        rows.extend(batch)
        if len(rows) >= total:
            check(len(rows) == total and all(type(row.get('id')) is int and row['id'] > 0 for row in rows)
                  and len({row['id'] for row in rows}) == len(rows), 'search')
            return sorted(rows, key=lambda row: row['id'], reverse=True)
        check(len(batch) == 100, 'search')
    raise SelectionError('search')


def publication(api, source, main):
    candidates = []
    searches = ((runs(api, 'ci.yml', branch='main', event='push', head_sha=source), '.github/workflows/ci.yml', 'push'),
                (runs(api, 'release-image.yml', branch='main', event='workflow_dispatch'), '.github/workflows/release-image.yml', 'workflow_dispatch'))
    for history, workflow, event in searches:
        for run in history:
            check(auth.evidence.workflow_matches(run, workflow, event)
                  and (event != 'push' or run.get('head_sha') == source), 'publication')
            artifacts = api.collection('actions/runs/'+str(run['id'])+'/artifacts', 'artifacts')['artifacts']
            matches = [row for row in artifacts if row.get('name', '').startswith('release-manifest-')
                       and row['name'].endswith('-'+source)]
            if matches:
                candidates.append((run, matches))
    check(candidates, 'missing')
    # Choose one publication deterministically. Never fall back from its invalid/expired evidence.
    run, matches = max(candidates, key=lambda candidate: candidate[0]['id'])
    check(len(matches) == 1, 'ambiguous')
    artifact = matches[0]
    check(artifact.get('expired') is False, 'expired')
    check(run.get('status') == 'completed' and run.get('conclusion') == 'success', 'publication')
    try:
        release = api.release(run['id'], artifact['id'])
        auth.evidence.verify_publication(release['manifest'], release['run'], release['jobs'], release['artifacts'], artifact['id'])
        manifest = auth.parse_json(release['manifest'])
        runtime.inventory(manifest)
    except (ValueError, KeyError, TypeError):
        raise SelectionError('publication') from None
    check(manifest['source_commit'] == source and release['run']['id'] == run['id']
          and release['run']['run_attempt'] == run['run_attempt'], 'binding')
    ancestry(api, manifest['release_workflow_commit'], main)
    return release, manifest, artifact['id']


def deployment_request(api, reference, environment):
    identities = context(environment)
    approved_workflow(api, environment, identities)
    source = resolve(api, reference, environment['GITHUB_SHA'])
    release, manifest, artifact_id = publication(api, source, environment['GITHUB_SHA'])
    api.release_current(release['run'])
    artifact = api.get('actions/artifacts/'+str(artifact_id))
    check(artifact.get('id') == artifact_id and artifact.get('expired') is False
          and artifact.get('workflow_run', {}).get('id') == release['run']['id'], 'expired')
    approved_workflow(api, environment, identities)
    check(resolve(api, reference, environment['GITHUB_SHA']) == source, 'moved')
    data = dict(identities, release_run_id=release['run']['id'], release_run_attempt=release['run']['run_attempt'],
                release_artifact_id=artifact_id, image=manifest['image'], source_commit=source,
                manifest_sha256=hashlib.sha256(release['manifest']).hexdigest())
    return runtime.validate_request(data)


def main():
    path = Path(os.environ['RUNNER_TEMP'])/'prd-request.json'
    try:
        path.unlink(missing_ok=True)
        check(not sys.argv[1:], 'context')
        context(os.environ)
        api = auth.GitHub(os.environ['GH_TOKEN'])
        data = deployment_request(api, os.environ.get('DEPLOY_REF', ''), os.environ)
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            json.dump(data, output)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        print('Verified Production release '+data['image']+' for '+data['source_commit']+'.')
    except (Exception, KeyboardInterrupt) as error:
        path.unlink(missing_ok=True)
        message = ERRORS.get(str(error)) if type(error) is SelectionError else None
        sys.exit('::error::'+(message or 'Production release selection failed; no Production connection was attempted. Inspect reference and authenticated release evidence.'))


if __name__ == '__main__':
    main()
