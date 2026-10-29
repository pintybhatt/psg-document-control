#!/usr/bin/env python3
"""Retrieve current GitHub publication evidence; emit a bounded UAT request, never SSH."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile


spec = importlib.util.spec_from_file_location('evidence', Path(__file__).with_name('verify-uat-release.py'))
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)
BASE = 'repos/'+evidence.REPOSITORY+'/actions/'


def api(path, paginated=False):
    command = ['gh', 'api', BASE+path]
    if paginated:
        command += ['--paginate', '--slurp']
    result = subprocess.run(command, check=True, capture_output=True, timeout=120)
    return json.loads(result.stdout)


def collection(path, key):
    pages = api(path+'?per_page=100', True)
    rows = [row for page in pages for row in page[key]]
    evidence.require(pages and all(page['total_count'] == len(rows) for page in pages), 'Incomplete API evidence.')
    return {'total_count': len(rows), key: rows}


def prepare(request_id, run_id, artifact_id, image):
    evidence.require(re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_-]{2,63}', request_id), 'Invalid request ID.')
    evidence.require(re.fullmatch(r'[1-9][0-9]{0,19}', run_id)
                     and re.fullmatch(r'[1-9][0-9]{0,19}', artifact_id), 'Invalid release/artifact ID.')
    evidence.require(re.fullmatch(evidence.IMAGE, image), 'Immutable DCMS digest required.')
    run = api('runs/'+run_id)
    attempt = run['run_attempt']
    evidence.require(type(attempt) is int and attempt > 0, 'Invalid attempt.')
    jobs = collection('runs/'+run_id+'/attempts/'+str(attempt)+'/jobs', 'jobs')
    artifacts = collection('runs/'+run_id+'/artifacts', 'artifacts')
    evidence.require(any(row['id'] == int(artifact_id) and row.get('expired') is False
                         for row in artifacts['artifacts']), 'Artifact unavailable.')
    result = subprocess.run(['gh', 'api', BASE+'artifacts/'+artifact_id+'/zip'],
                            check=True, capture_output=True, timeout=120)
    evidence.require(len(result.stdout) <= 2*1024*1024, 'Oversized manifest archive.')
    with zipfile.ZipFile(io.BytesIO(result.stdout)) as archive:
        evidence.require(archive.namelist() == ['release-manifest.json'], 'Unexpected manifest archive members.')
        evidence.require(archive.getinfo('release-manifest.json').file_size <= 1024*1024, 'Oversized manifest.')
        manifest = archive.read('release-manifest.json')
    evidence.require(evidence.verify_publication(manifest, run, jobs, artifacts, int(artifact_id)) == image,
                     'Selected image differs from published release.')
    current = api('runs/'+run_id)
    evidence.require(current['run_attempt'] == attempt and current['status'] == 'completed'
                     and current['conclusion'] == 'success', 'Release changed while checking evidence.')
    return {'request_id': request_id, 'release_run_id': int(run_id), 'release_run_attempt': attempt,
            'release_artifact_id': int(artifact_id), 'image': image,
            'manifest_sha256': hashlib.sha256(manifest).hexdigest()}


def main():
    try:
        request = prepare(*(os.environ[name] for name in ('REQUEST_ID', 'RELEASE_RUN_ID', 'RELEASE_ARTIFACT_ID', 'IMAGE')))
        print(json.dumps(request))
    except (ValueError, KeyError, TypeError, AttributeError, OSError, subprocess.SubprocessError, zipfile.BadZipFile):
        sys.exit('STOP: Publication verification failed; no request emitted.')


if __name__ == '__main__':
    main()
