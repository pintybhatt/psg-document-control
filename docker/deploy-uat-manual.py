#!/usr/bin/env python3
"""Manual UAT entry point; existing fixed SSH/sudo boundary and DCMS runtime utilities."""
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import fcntl

spec = importlib.util.spec_from_file_location('uat_runtime', Path(__file__).with_name('deploy-uat.py'))
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
ROOT = runtime.ROOT
require = runtime.require


def request(stream):
    raw = stream.read(4097)
    require(len(raw) <= 4096, 'Oversized request.')
    data = runtime.auth.parse_json(raw)
    require(set(data) == {'image', 'source_commit', 'run'}, 'Invalid request.')
    require(isinstance(data['image'], str) and re.fullmatch(runtime.evidence.IMAGE, data['image']), 'Immutable DCMS image required.')
    require(isinstance(data['source_commit'], str) and re.fullmatch(r'[a-f0-9]{40}', data['source_commit']), 'Source commit required.')
    require(isinstance(data['run'], str) and re.fullmatch(r'[0-9]+-[0-9]+', data['run']), 'Run identity required.')
    return data


def local_backup(deployment, current, database):
    spec = importlib.util.spec_from_file_location('paired_backup', ROOT/'release/docker/uat-backup.py')
    paired = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(paired)
    deployment.backup(current, database, verifier=paired.validate)


def execute(data):
    require(os.geteuid() == 0, 'Root entry point required.')
    os.umask(0o077)
    for name in ('release/docker/deploy-uat-manual.py', 'release/docker/deploy-uat.py',
                 'release/docker/uat-backup.py', 'history', 'backups'):
        runtime.protected(ROOT/name)
    with runtime.protected(ROOT/'deployment.lock', True).open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        flag = ROOT/'config/recovery-required.json'
        require(not flag.exists(), 'Administrator recovery inspection required.')
        current = runtime.read_json(ROOT/'config/current.json')
        policy = runtime.read_json(ROOT/'config/automation.json')
        require(policy.get('enabled') is True and policy.get('target') == 'uat' and policy.get('project') == runtime.PROJECT, 'UAT disabled.')
        require(re.fullmatch(r'https://[a-z0-9]+(?:[.-][a-z0-9]+)*:8444/up', policy.get('health_url', '')), 'UAT health URL required.')
        history = ROOT/'history'/('gha-'+data['run'])
        history.mkdir(mode=0o700)
        config = runtime.protected(ROOT/'config/compose.env', True)
        lines = config.read_text().splitlines()
        require(lines.count('DCMS_IMAGE='+current['image']) == 1, 'Current image mismatch.')
        candidate = history/'compose.env'
        candidate.write_text('\n'.join('DCMS_IMAGE='+data['image'] if line.startswith('DCMS_IMAGE=') else line for line in lines)+'\n')
        deployment = runtime.Deployment(history, candidate)
        database = deployment.container('db')
        app = deployment.container('app')
        require(database['State']['Health']['Status'] == 'healthy', 'Healthy database required.')
        deployment.storage(app, database)
        require(app['Config']['Image'] == current['image'], 'Current runtime image mismatch.')
        deployment.run(runtime.DOCKER+['pull', data['image']], timeout=600)
        image = json.loads(deployment.run(runtime.DOCKER+['image', 'inspect', data['image']]))[0]
        require(image['Os'] == 'linux' and image['Architecture'] == 'amd64' and image['Config']['Labels'].get('org.opencontainers.image.revision') == data['source_commit']
                and image['Config']['Labels'].get('org.opencontainers.image.source') == 'https://github.com/'+runtime.evidence.REPOSITORY,
                'Image must match dispatched main source.')
        status = json.loads(deployment.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php', 'app', '-r', runtime.SCHEMA_PROBE))
        manifest = {'image': data['image'], 'source_commit': data['source_commit'], 'migration_inventory': status['inventory'],
                    'repository': runtime.evidence.REPOSITORY, 'platform': 'linux/amd64'}
        existing = current['migration_inventory']
        require(existing and status['inventory'][:len(existing)] == existing, 'Deployed migrations must remain immutable.')
        deployment.schema(manifest, baseline=current)
        manifest_path = history/'manifest.json'
        runtime.write_json(manifest_path, manifest)
        deployment.preflight(manifest_path, candidate)
        for name in ('backup.cnf', 'migration.env'):
            runtime.protected(ROOT/'config'/name, True)
        runtime.write_json(flag, data)
        try:
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
            deployment.maintenance_active()
            deployment.dc('stop', '--timeout', '30', 'app')
            local_backup(deployment, current, database)
            deployment.migrate()
            deployment.schema(manifest)
            deployment.replace()
            require(sorted(deployment.container('app')['Mounts'], key=lambda row: row['Destination']) == sorted(app['Mounts'], key=lambda row: row['Destination']), 'Persistent mounts changed.')
            deployment.health()
            deployment.invariant(database)
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'up', '--no-interaction')
            for url in ('http://127.0.0.1:18084/up', policy['health_url']):
                code = deployment.run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--retry', '6', '--retry-all-errors', '--max-time', '10', '--output', '/dev/null', '--write-out', '%{http_code}', url])
                require(code == '200', 'Health check failed.')
            require(deployment.run(['/usr/bin/curl', '--silent', '--show-error', '--max-time', '10', '--output', '/dev/null', '--write-out', '%{http_code}', 'http://127.0.0.1:18084/storage/private-check']) == '403', 'Private storage must remain blocked.')
            deployment.wait_healthy()
            deployment.invariant(database)
            runtime.write_json(ROOT/'config/current.json.next', manifest)
            os.replace(ROOT/'config/current.json.next', ROOT/'config/current.json')
            os.replace(candidate, config)
            runtime.write_json(history/'result.json', {'status': 'deployed'})
            flag.unlink()
        except BaseException:
            try:
                deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
            except Exception:
                pass
            raise


if __name__ == '__main__':
    try:
        require(len(sys.argv) == 1, 'No arguments allowed.')
        execute(request(sys.stdin.buffer))
        print('DCMS UAT deployment completed.')
    except Exception:
        sys.exit('STOP: DCMS UAT deployment failed; inspect root-only history and recovery state.')
