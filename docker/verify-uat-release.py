#!/usr/bin/env python3
"""Verify downloaded release evidence and owner approval offline. Cannot connect or deploy."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import stat


REPOSITORY = 'pintybhatt/psg-document-control'
IMAGE = r'ghcr\.io/pintybhatt/psg-document-control@sha256:[a-f0-9]{64}'


def require(condition, message):
    if not condition:
        raise ValueError(message)


def workflow_matches(run, path, event):
    return run.get('path') in (path, path+'@main', path+'@refs/heads/main') and run.get('event') == event and run.get('head_branch') == 'main'


def verify(manifest_bytes, approval, run, jobs, artifacts, now):
    manifest = json.loads(manifest_bytes)
    image, source = manifest.get('image', ''), manifest.get('source_commit', '')
    require(re.fullmatch(IMAGE, image) and re.fullmatch(r'[a-f0-9]{40}', source), 'Invalid DCMS digest/source.')
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', manifest.get('tested_image_id', '')), 'Missing tested image ID.')
    version = manifest.get('version', '')
    require(re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[0-9]+)?', version), 'Invalid release version.')
    require(manifest.get('repository') == REPOSITORY, 'Manifest repository mismatch.')
    require(manifest.get('platform') == 'linux/amd64' and manifest.get('migration_inventory'), 'Platform/migration inventory missing.')
    require(approval.get('target') == 'uat' and approval.get('project') == 'dcms-uat-xppl'
            and approval.get('decision') == 'approved', 'Explicit UAT-only approval required.')
    require(approval.get('image') == image and approval.get('source_commit') == source, 'Approval digest/source mismatch.')
    require(approval.get('manifest_sha256') == hashlib.sha256(manifest_bytes).hexdigest(), 'Approval does not identify this manifest.')
    for key in ('approval_reference', 'approved_by', 'recovery_reference', 'migration_review_reference'):
        require(isinstance(approval.get(key), str) and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', approval[key]), 'Missing/invalid approval or recovery reference.')
    previous = approval.get('previous_image')
    require('previous_image' in approval and (previous is None or re.fullmatch(IMAGE, previous)), 'Explicit previous digest or initial-release null required.')
    approved_at = datetime.datetime.fromisoformat(approval.get('approved_at', ''))
    expires_at = datetime.datetime.fromisoformat(approval.get('expires_at', ''))
    require(approved_at.tzinfo is not None and expires_at.tzinfo is not None
            and approved_at <= now < expires_at, 'Approval is expired, future-dated or lacks timezone.')
    require(str(run.get('id')) == str(approval.get('release_run_id')), 'Approved release run mismatch.')
    require(str(run.get('run_attempt')) == str(approval.get('release_run_attempt')), 'Approved release attempt mismatch.')
    return verify_publication(manifest_bytes, run, jobs, artifacts, approval.get('release_artifact_id'))


def verify_publication(manifest_bytes, run, jobs, artifacts, artifact_id):
    manifest = json.loads(manifest_bytes)
    image, source, version = manifest['image'], manifest['source_commit'], manifest['version']
    require(re.fullmatch(IMAGE, image) and re.fullmatch(r'[a-f0-9]{40}', source), 'Invalid DCMS digest/source.')
    require(re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[0-9]+)?', version), 'Invalid release version.')
    require(manifest.get('repository') == REPOSITORY and manifest.get('platform') == 'linux/amd64', 'Manifest identity mismatch.')
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', manifest.get('tested_image_id', ''))
            and manifest.get('migration_inventory'), 'Missing tested image/migration identity.')
    require(run.get('repository', {}).get('full_name') == REPOSITORY
            and run.get('head_repository', {}).get('full_name') == REPOSITORY, 'Workflow repository mismatch.')
    automatic = workflow_matches(run, '.github/workflows/ci.yml', 'push')
    legacy = workflow_matches(run, '.github/workflows/release-image.yml', 'workflow_dispatch')
    require((automatic or legacy) and run.get('head_branch') == 'main', 'Trusted main release workflow required.')
    require(run.get('status') == 'completed' and run.get('conclusion') == 'success', 'Release workflow did not succeed.')
    require(run.get('head_sha') == manifest.get('release_workflow_commit')
            and re.fullmatch(r'[a-f0-9]{40}', run.get('head_sha', '')), 'Release workflow revision mismatch.')
    require(str(run.get('id')) == str(manifest.get('github_run_id'))
            and str(run.get('id', '')).isdigit(), 'Release run identity mismatch.')
    require(str(run.get('run_attempt')) == str(manifest.get('github_run_attempt'))
            and str(run.get('run_attempt', '')).isdigit(), 'Release attempt mismatch; refresh evidence/approval after reruns.')
    if automatic:
        require(run['head_sha'] == source and version == 'v0.0.'+str(run['id'])+'-rc.'+str(run['run_attempt']),
                'Automatic publication must identify this exact main commit and attempt.')
    rows = jobs.get('jobs', [])
    require(jobs.get('total_count') == len(rows), 'Incomplete job evidence; fetch every page.')
    prefix = '' if automatic else 'ci / '
    mysql_names = [row.get('name', '') for row in rows if row.get('name', '').startswith(prefix+'MySQL')]
    shards = [prefix+'MySQL 8.4 (shard '+str(number)+'/4)' for number in range(1, 5)]
    # Historical RC evidence predates sharding. Never mix layouts or accept partial shards.
    required_mysql = ['ci / MySQL 8.4'] if legacy and mysql_names == ['ci / MySQL 8.4'] else shards
    require(sorted(mysql_names) == sorted(required_mysql), 'Incomplete or ambiguous MySQL release evidence.')
    required = ['Frontend', 'PHP / SQLite', 'Fast CI', 'Image / smoke', 'Full release validation', 'Publish release / publish'] if automatic else ['ci / Fast CI', 'ci / Image / smoke', 'ci / Full release validation', 'publish']
    for name in (*required, *required_mysql):
        matches = [row for row in rows if row.get('name') == name]
        require(len(matches) == 1 and matches[0].get('run_id') == run['id']
                and matches[0].get('run_attempt', None if automatic else run['run_attempt']) == run['run_attempt']
                and matches[0].get('status') == 'completed' and matches[0].get('conclusion') == 'success', 'Required release job absent, failed, skipped or ambiguous: '+name)
    image_job = next(row for row in rows if row['name'] == prefix+'Image / smoke')
    require(any(step.get('name') == 'Test that exact image in a disposable stack' and step.get('conclusion') == 'success'
                for step in image_job.get('steps', [])), 'Successful exact-image smoke evidence required.')
    require(artifacts.get('total_count', len(artifacts.get('artifacts', []))) == len(artifacts.get('artifacts', [])), 'Incomplete artifact evidence.')
    matches = [row for row in artifacts.get('artifacts', []) if row.get('name') == 'release-manifest-'+version+'-'+source]
    require(len(matches) == 1 and matches[0].get('expired') is False
            and matches[0].get('id') == artifact_id
            and matches[0].get('workflow_run', {}).get('id') == run['id'], 'Approved release manifest artifact mismatch/expired.')
    if automatic:
        require(matches[0]['workflow_run'].get('head_sha') == source and matches[0]['workflow_run'].get('head_branch') == 'main',
                'Artifact source must match the validated main commit.')
    return image


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('manifest', 'approval', 'run', 'jobs', 'artifacts'):
        parser.add_argument('--'+name, required=True, type=Path)
    args = parser.parse_args()
    try:
        require(not stat.S_IMODE(args.approval.stat().st_mode) & 0o077, 'Approval file must be owner-only.')
        image = verify(args.manifest.read_bytes(), json.loads(args.approval.read_text()),
                       json.loads(args.run.read_text()), json.loads(args.jobs.read_text()),
                       json.loads(args.artifacts.read_text()), datetime.datetime.now(datetime.timezone.utc))
        print('UAT release evidence verified for '+image+'. No deployment performed.')
    except (ValueError, OSError, KeyError, TypeError, AttributeError):
        # Evidence can contain arbitrary text; do not echo it or approval/secret contents.
        parser.exit(1, 'STOP: Release evidence or UAT approval invalid; review protected input files.\n')


if __name__ == '__main__':
    main()
