#!/usr/bin/env python3
"""Fixed UAT app replacement. Root-enrolled approvals only; no bootstrap or migrations."""
import datetime
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import time


ROOT = Path('/srv/dcms-uat')
PROJECT = 'dcms-uat-xppl'
ENVIRONMENT = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root',
               'DOCKER_CONFIG': str(ROOT/'registry'), 'DOCKER_HOST': 'unix:///var/run/docker.sock'}
DOCKER = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock']
SPEC = importlib.util.spec_from_file_location('release_evidence', Path(__file__).with_name('verify-uat-release.py'))
evidence = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(evidence)
require = evidence.require

# Read-only query, fixed source. Never evaluates request data or executes migration classes.
SCHEMA_PROBE = r'''
$files = glob("database/migrations/*.php"); sort($files); $inventory = [];
foreach ($files as $file) { $inventory[] = ["path" => $file, "sha256" => hash_file("sha256", $file)]; }
$db = new PDO("mysql:host=db;port=3306;dbname=dcms_uat;charset=utf8mb4", getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ran = $db->query("SELECT migration FROM migrations ORDER BY migration")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(["inventory" => $inventory, "ran" => $ran]);
'''


def protected(path, private=False):
    """All parents and files must be root-owned, non-symlink and non-writable by others."""
    path = Path(path)
    require(path.is_absolute(), 'Absolute protected path required.')
    for item in [*reversed(path.parents), path]:
        info = item.lstat()
        require(not stat.S_ISLNK(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                'Unsafe root custody.')
    if private:
        require(stat.S_ISREG(info.st_mode) and not info.st_mode & 0o077, 'Private regular file required.')
    return path


def read_json(path):
    return json.loads(protected(path, True).read_bytes())


def request_from(stream):
    payload = stream.read(4097)
    require(len(payload) <= 4096, 'Oversized request.')
    request = json.loads(payload)
    require(isinstance(request, dict) and set(request) == {'request_id', 'release_run_id', 'release_run_attempt',
            'release_artifact_id', 'image', 'manifest_sha256'}, 'Unexpected request fields.')
    require(isinstance(request['request_id'], str)
            and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_-]{2,63}', request['request_id']), 'Invalid request ID.')
    for key in ('release_run_id', 'release_run_attempt', 'release_artifact_id'):
        require(type(request[key]) is int and 0 < request[key] < 10**20, 'Invalid release ID.')
    require(isinstance(request['image'], str) and re.fullmatch(evidence.IMAGE, request['image']), 'Invalid digest.')
    require(isinstance(request['manifest_sha256'], str)
            and re.fullmatch(r'[a-f0-9]{64}', request['manifest_sha256']), 'Invalid manifest hash.')
    return request


def validate_approval(request, approval, policy, current, manifest, backup):
    for key in request:
        require(approval.get(key) == request[key], 'Request does not match enrolled approval.')
    require(approval['approved_by'] in policy['owners'], 'Enrolled owner approval required.')
    require(approval.get('previous_image') == current['image']
            and current['image'] != manifest['image'], 'Previous digest mismatch or no-op.')
    require(approval.get('operation') in ('deploy', 'rollback'), 'Explicit deployment operation required.')
    require(approval.get('schema_mode') in ('unchanged', 'reviewed-applied'), 'Explicit schema review required.')
    require(approval.get('schema_compatible') is True, 'Schema compatibility approval required.')
    reference = approval.get('schema_compatibility_reference', '')
    require(isinstance(reference, str) and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', reference),
            'Compatibility reference required, including rollback.')
    if approval['schema_mode'] == 'unchanged':
        require(manifest['migration_inventory'] == current['migration_inventory'], 'Schema inventory changed.')
    else:
        for key in ('backup_reference', 'restoration_plan_reference', 'migration_authorization_reference'):
            value = approval.get(key, '')
            require(isinstance(value, str) and re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/-]{2,127}', value),
                    'Reviewed backup, restoration plan and migration authorization required.')
            require(backup.get(key) == value, 'Backup review does not match approval.')
        require(backup.get('reviewed_by') in policy['owners'] and backup.get('verified') is True
                and backup.get('paired_database_documents_private_config') is True
                and backup.get('off_host_verified') is True and backup.get('restore_drill_reference'),
                'Verified paired backup and restoration evidence required.')
        require(backup.get('previous_image') == current['image'] and backup.get('image') == manifest['image'],
                'Backup review release mismatch.')


class Deployment:
    def __init__(self, history, compose_env):
        self.history = history
        self.environment = dict(ENVIRONMENT)
        self.compose = DOCKER+['compose', '--project-name', PROJECT, '--env-file', str(compose_env),
                              '-f', str(ROOT/'release/compose.release.yaml'), '-f', str(ROOT/'release/compose.uat.yaml')]
        self.sequence = 0

    def run(self, command, timeout=180):
        self.sequence += 1
        result = subprocess.run(command, env=self.environment, cwd=ROOT/'release',
                                capture_output=True, timeout=timeout)
        # Root-only logs; never print subprocess output (Compose may include secret values).
        (self.history/(str(self.sequence)+'.log')).write_bytes(result.stdout+b'\n'+result.stderr)
        require(result.returncode == 0, 'Command failed; inspect root-only history.')
        return result.stdout.decode()

    def dc(self, *arguments, timeout=180):
        return self.run(self.compose+list(arguments), timeout)

    def container(self, service):
        identities = self.dc('ps', '-q', service).split()
        require(len(identities) == 1 and re.fullmatch(r'[a-f0-9]{64}', identities[0]), 'Existing UAT service required.')
        info = json.loads(self.run(DOCKER+['container', 'inspect', identities[0]]))[0]
        labels = info['Config']['Labels']
        require(labels.get('com.docker.compose.project') == PROJECT
                and labels.get('com.docker.compose.service') == service, 'Foreign container refused.')
        require(info['State']['Running'], 'UAT service must be running.')
        return info

    def image(self, manifest):
        info = json.loads(self.run(DOCKER+['image', 'inspect', manifest['image']]))[0]
        require(info['Id'] == manifest['tested_image_id'] and manifest['image'] in info['RepoDigests'],
                'Image differs from smoke-tested publication.')
        require(info['Os'] == 'linux' and info['Architecture'] == 'amd64'
                and info['Config']['Labels'].get('org.opencontainers.image.revision') == manifest['source_commit']
                and info['Config']['Labels'].get('org.opencontainers.image.source') == 'https://github.com/'+evidence.REPOSITORY,
                'Image platform/source mismatch.')

    def schema(self, manifest, approval):
        status = json.loads(self.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php',
                                   'app', '-r', SCHEMA_PROBE))
        require(status['inventory'] == manifest['migration_inventory'], 'Image migration hashes differ from manifest.')
        expected = [Path(row['path']).stem for row in manifest['migration_inventory']]
        if approval['operation'] == 'rollback' and approval['schema_mode'] == 'reviewed-applied':
            reviewed = approval.get('live_migrations')
            require(isinstance(reviewed, list) and all(isinstance(name, str) and re.fullmatch(r'[a-zA-Z0-9_]+', name) for name in reviewed)
                    and len(set(reviewed)) == len(reviewed) and set(expected) <= set(reviewed),
                    'Explicit resulting schema inventory required for rollback.')
            expected = reviewed
        require(sorted(status['ran']) == sorted(expected), 'Pending, missing or unexpected live migrations; manual review required.')

    def storage(self, app, db):
        expected = {'/var/www/html/storage/app/document-vault': 'documents',
                    '/var/www/html/storage/app/private': 'private',
                    '/var/www/html/storage/framework': 'framework'}
        for info, mounts in ((app, expected), (db, {'/var/lib/mysql': 'database'})):
            actual = {mount['Destination']: mount for mount in info['Mounts']}
            for destination, name in mounts.items():
                mount = actual.get(destination, {})
                require(mount.get('Type') == 'volume' and mount.get('Name') == PROJECT+'_'+name,
                        'Persistent volume identity mismatch.')
                volume = json.loads(self.run(DOCKER+['volume', 'inspect', PROJECT+'_'+name]))[0]
                require(volume['Labels'].get('com.docker.compose.project') == PROJECT
                        and volume['Labels'].get('com.docker.compose.volume') == name,
                        'Foreign or missing persistent volume.')
        bind = [mount for mount in app['Mounts'] if mount['Destination'] == '/etc/apache2/conf-enabled/zz-dcms-uat.conf']
        require(len(bind) == 1 and bind[0]['Type'] == 'bind' and not bind[0]['RW']
                and bind[0]['Source'] == str(ROOT/'release/docker/apache-uat.conf'), 'Apache mount mismatch.')

    def invariant(self, before):
        after = self.container('db')
        require(after['Id'] == before['Id'] and after['State']['StartedAt'] == before['State']['StartedAt']
                and after['Mounts'] == before['Mounts'] and after['State']['Health']['Status'] == 'healthy',
                'Database identity/health changed; incident review required.')

    def preflight(self, manifest_file, compose_env):
        self.run(['/usr/bin/python3', '-I', '-B', str(ROOT/'release/docker/preflight.py'),
                  '--target', 'uat', '--project', PROJECT, '--uat-profile',
                  '--compose-env', str(compose_env), '--manifest', str(manifest_file)])

    def maintenance_active(self):
        state = self.dc('exec', '-T', 'app', 'php', '-r',
                        'echo is_file("storage/framework/down") ? "down" : "up";')
        require(state == 'down', 'Shared file maintenance marker required.')

    def replace(self):
        self.dc('up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--force-recreate', 'app')

    def wait_healthy(self):
        for attempt in range(60):
            app = self.container('app')
            if app['State'].get('Health', {}).get('Status') == 'healthy':
                return
            time.sleep(2)
        raise ValueError('Application health timeout.')

    def health(self):
        self.dc('exec', '-T', 'app', 'apache2ctl', '-t')
        self.dc('exec', '-T', 'app', 'sh', '-eu', '-c',
                'test -w storage/app/document-vault; test -w storage/app/private; test -w storage/framework; test ! -e public/storage')
        self.dc('exec', '-T', 'app', 'php', 'artisan', 'migrate:status', '--no-interaction')


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2)+'\n')


def execute(request):
    require(os.geteuid() == 0 and len(sys.argv) == 1, 'Fixed root entry point required.')
    os.umask(0o077)
    protected(ROOT/'release/docker/deploy-uat.py')
    protected(ROOT/'history')
    protected(ROOT/'requests')
    protected(ROOT/'deployment.lock', True)
    with (ROOT/'deployment.lock').open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        bundle = protected(ROOT/'requests'/request['request_id'])
        manifest_file = protected(bundle/'manifest.json', True)
        manifest_bytes = manifest_file.read_bytes()
        manifest = json.loads(manifest_bytes)
        approval = read_json(bundle/'approval.json')
        policy = read_json(ROOT/'config/automation.json')
        current = read_json(ROOT/'config/current.json')
        require(isinstance(policy.get('owners'), list) and policy['owners'], 'Owner allowlist required.')
        require(re.fullmatch(r'https://[a-z0-9]+(?:[.-][a-z0-9]+)*:8444/up', policy.get('health_url', '')), 'Approved HTTPS UAT health URL required.')
        require(policy.get('enabled') is True and policy.get('target') == 'uat'
                and policy.get('project') == PROJECT, 'UAT automation is not enrolled/enabled.')
        backup = read_json(bundle/'backup-review.json') if approval.get('schema_mode') == 'reviewed-applied' else {}
        validate_approval(request, approval, policy, current, manifest, backup)

        def verify():
            evidence.verify(manifest_bytes, approval, read_json(bundle/'run.json'), read_json(bundle/'jobs.json'),
                            read_json(bundle/'artifacts.json'), datetime.datetime.now(datetime.timezone.utc))

        verify()
        # mkdir is an atomic single-use reservation. Failed attempts need a new owner-enrolled ID.
        history = ROOT/'history'/request['request_id']
        history.mkdir(mode=0o700)
        for filename in ('manifest.json', 'approval.json', 'run.json', 'jobs.json', 'artifacts.json'):
            shutil.copyfile(bundle/filename, history/filename)
        if backup:
            shutil.copyfile(bundle/'backup-review.json', history/'backup-review.json')
        write_json(history/'previous-manifest.json', current)
        write_json(history/'request.json', request)
        write_json(history/'result.json', {'status': 'started', 'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
        deployment = None
        maintenance = False
        try:
            # All executable/config inputs stay root-managed. Request cannot select any path or env value.
            for filename in ('compose.release.yaml', 'compose.uat.yaml', 'docker/apache-uat.conf',
                             'docker/preflight.py', 'docker/verify-uat-release.py'):
                protected(ROOT/'release'/filename)
            compose_env = protected(ROOT/'config/compose.env', True)
            for filename in ('app.env', 'db-password', 'db-root-password'):
                protected(ROOT/'config'/filename, True)
            protected(ROOT/'registry/config.json', True)
            candidate_env = history/'compose.env'
            lines = compose_env.read_text().splitlines()
            require(sum(line.startswith('DCMS_IMAGE=') for line in lines) == 1, 'Single explicit image setting required.')
            candidate_env.write_text('\n'.join('DCMS_IMAGE='+manifest['image'] if line.startswith('DCMS_IMAGE=') else line for line in lines)+'\n')
            deployment = Deployment(history, candidate_env)
            deployment.preflight(manifest_file, candidate_env)
            # Fixed enrollment paths: no config/secret indirection to another service.
            variables = dict(line.split('=', 1) for line in lines if line and not line.startswith('#') and '=' in line)
            for variable, filename in [('DCMS_APP_ENV_FILE', 'app.env'), ('DCMS_DB_PASSWORD_FILE', 'db-password'),
                                       ('DCMS_DB_ROOT_PASSWORD_FILE', 'db-root-password')]:
                require(variables.get(variable) == str(ROOT/'config'/filename), 'Unenrolled configuration path.')
            require(variables.get('DCMS_IMAGE') == current['image'], 'Current config digest mismatch.')
            require(type(policy.get('minimum_free_memory_mib')) is int and policy['minimum_free_memory_mib'] > 0
                    and type(policy.get('minimum_free_disk_bytes')) is int and policy['minimum_free_disk_bytes'] > 0,
                    'Reviewed capacity thresholds required.')
            memory = next(int(line.split()[1]) for line in Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:'))
            docker_root = deployment.run(DOCKER+['info', '--format', '{{.DockerRootDir}}']).strip()
            require(memory >= policy['minimum_free_memory_mib']*1024
                    and shutil.disk_usage(docker_root).free >= policy['minimum_free_disk_bytes']
                    and shutil.disk_usage(ROOT).free >= policy['minimum_free_disk_bytes'], 'Capacity below approved threshold.')
            before = deployment.container('db')
            require(before['State']['Health']['Status'] == 'healthy', 'Healthy existing database required.')
            old_app = deployment.container('app')
            require(old_app['Config']['Image'] == current['image'] and old_app['Image'] == current['tested_image_id'],
                    'Running previous image mismatch.')
            deployment.storage(old_app, before)
            if approval['schema_mode'] == 'reviewed-applied':
                deployment.maintenance_active()
            deployment.run(DOCKER+['pull', manifest['image']], timeout=600)
            deployment.image(manifest)
            deployment.schema(manifest, approval)
            verify()
            maintenance = True
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
            deployment.maintenance_active()
            deployment.replace()
            new_app = deployment.container('app')
            require(new_app['Config']['Image'] == manifest['image'] and new_app['Image'] == manifest['tested_image_id'],
                    'Replacement image mismatch.')
            require(sorted(new_app['Mounts'], key=lambda row: row['Destination']) == sorted(old_app['Mounts'], key=lambda row: row['Destination']), 'Application persistent mounts changed.')
            deployment.maintenance_active()
            deployment.health()
            deployment.schema(manifest, approval)
            deployment.invariant(before)
            verify()
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'up', '--no-interaction')
            # curl retries cover app readiness; no TLS bypass and no redirect following.
            for url in ('http://127.0.0.1:18084/up', policy['health_url']):
                require(url == 'http://127.0.0.1:18084/up' or re.fullmatch(r'https://[a-z0-9]+(?:[.-][a-z0-9]+)*:8444/up', url), 'Approved UAT health URL required.')
                code = deployment.run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--retry', '6',
                                '--retry-all-errors', '--retry-delay', '2', '--max-time', '10', '--output', '/dev/null', '--write-out', '%{http_code}', url])
                require(code == '200', 'Health endpoint must return HTTP 200.')
            denied = deployment.run(['/usr/bin/curl', '--silent', '--show-error', '--max-time', '10',
                                     '--output', '/dev/null', '--write-out', '%{http_code}',
                                     'http://127.0.0.1:18084/storage/private-check'])
            require(denied == '403', 'Public storage path must remain denied.')
            deployment.wait_healthy()
            deployment.invariant(before)
            verify()
            # Commit state only after verification. No secret/key/env rewrite.
            staged = ROOT/'config/current.json.next'
            write_json(staged, manifest)
            os.replace(staged, ROOT/'config/current.json')
            os.replace(candidate_env, compose_env)
            write_json(history/'result.json', {'status': 'deployed', 'image': manifest['image'],
                                              'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            print('UAT app deployment completed. Formal XPPL acceptance remains separate.')
        except (Exception, KeyboardInterrupt):
            signal.alarm(0)
            if maintenance and deployment:
                try:
                    deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
                except Exception:
                    pass
            write_json(history/'result.json', {'status': 'failed', 'maintenance_requested': maintenance,
                                              'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            raise


def interrupted(signum, frame):
    raise RuntimeError('Deployment interrupted.')


def main():
    for signum in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT, signal.SIGALRM):
        signal.signal(signum, interrupted)
    try:
        signal.alarm(15)
        request = request_from(sys.stdin.buffer)
        signal.alarm(900)
        execute(request)
        signal.alarm(0)
    except (Exception, KeyboardInterrupt):
        # Do not expose arbitrary request, Docker, application or secret material over SSH.
        sys.exit('STOP: UAT deployment refused/failed. Administrator must inspect protected history and maintenance state.')


if __name__ == '__main__':
    main()
