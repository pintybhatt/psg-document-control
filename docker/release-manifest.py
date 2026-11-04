#!/usr/bin/env python3
"""Record release identity locally; never build, publish, connect or deploy."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--baseline', required=True)
parser.add_argument('--version', required=True)
parser.add_argument('--tested-image-id', required=True)
args = parser.parse_args()
if not re.fullmatch(r'ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}', args.image):
    parser.error('An immutable GHCR digest is required.')
if not all(re.fullmatch(r'[a-f0-9]{40}', value) for value in (args.source, args.baseline)):
    parser.error('Full commit identities are required.')
if not re.fullmatch(r'sha256:[a-f0-9]{64}', args.tested_image_id):
    parser.error('The smoke-tested image ID is required.')
if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[0-9]+)?', args.version):
    parser.error('A versioned release identity is required.')
if subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip() != args.source:
    parser.error('Working checkout does not match source commit.')
subprocess.run(['git', 'merge-base', '--is-ancestor', args.baseline, args.source], check=True)
files = sorted(Path('database/migrations').glob('*.php'))
manifest = {
    'schema_version': 1,
    'image': args.image,
    'source_commit': args.source,
    'baseline_commit': args.baseline,
    'version': args.version,
    'built_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'github_run_id': os.environ.get('GITHUB_RUN_ID'),
    'github_run_attempt': os.environ.get('GITHUB_RUN_ATTEMPT'),
    'repository': os.environ.get('GITHUB_REPOSITORY'),
    'release_workflow_commit': os.environ.get('GITHUB_SHA'),
    'tested_image_id': args.tested_image_id,
    'publication_approval': os.environ.get('APPROVAL_REFERENCE'),
    'platform': 'linux/amd64',
    'migration_inventory': [{'path': str(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()} for path in files],
    'migration_changes': subprocess.check_output(['git', 'diff', '--name-status', args.baseline, args.source, '--', 'database/migrations'], text=True).splitlines(),
    'migration_review': 'REQUIRED: compare inventory to target migrate:status; initial release may need all migrations',
    'uat_acceptance': None,
    'production_authorization': None,
}
print(json.dumps(manifest, indent=2))
