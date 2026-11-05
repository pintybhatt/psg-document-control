#!/usr/bin/env python3
"""Fixed UAT app replacement authorized by GitHub OIDC; paired backup and guarded forward migrations."""
import datetime
import fcntl
import hashlib
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
import tarfile
import time


ROOT = Path('/srv/dcms-uat')
PROJECT = 'dcms-uat-xppl'
ENVIRONMENT = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root',
               'DOCKER_CONFIG': str(ROOT/'registry'), 'DOCKER_HOST': 'unix:///var/run/docker.sock'}
DOCKER = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock']
SPEC = importlib.util.spec_from_file_location('github_uat_auth', Path(__file__).with_name('github-uat-auth.py'))
auth = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(auth)
evidence = auth.evidence
require = auth.require
HASH_CHUNK_SIZE = 1024 * 1024


def sha256_file(path):
    digest = hashlib.sha256()
    with path.open('rb') as source:
        while chunk := source.read(HASH_CHUNK_SIZE):
            digest.update(chunk)
    return digest.hexdigest()

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
    payload = stream.read(32769)
    require(len(payload) <= 32768, 'Oversized request.')
    envelope = auth.parse_json(payload)
    require(isinstance(envelope, dict) and set(envelope) == {'request', 'oidc_token', 'github_token'}, 'Unexpected authorization envelope.')
    auth.validate_request(envelope['request'])
    require(isinstance(envelope['oidc_token'], str) and len(envelope['oidc_token']) <= 16384, 'Invalid OIDC token.')
    return envelope


def authorize(envelope, policy, current, previous, progress=None):
    progress = auth.AuthorizationProgress() if progress is None else progress
    progress.mark('policy', 'POLICY_INVALID')
    auth.validate_policy(policy)
    request = envelope['request']
    progress.mark('request', 'REQUEST_INVALID')
    auth.validate_request(request)
    claims = auth.verify_oidc(envelope['oidc_token'], request, policy, progress=progress)
    progress.mark('github_credential', 'GITHUB_CREDENTIAL_INVALID')
    api = auth.GitHub(envelope['github_token'])
    auth.workflow_current(api, request, claims, policy, progress=progress)
    progress.mark('release_evidence', 'RELEASE_EVIDENCE_INVALID')
    release = api.release(request['release_run_id'], request['release_artifact_id'])
    progress.mark('release_validation', 'RELEASE_VALIDATION_FAILED')
    manifest = auth.validate_release(api, request, release, policy, current, previous)
    return api, claims, release, manifest


def audit_failure(progress):
    """Append only fixed stage/reason codes and UTC time to a root-only audit file."""
    require(os.geteuid() == 0, 'Root audit writer required.')
    require(progress.reason in auth.AuthorizationProgress.REASONS.get(progress.stage, set()), 'Unknown diagnostic code.')
    protected(ROOT)
    descriptor = os.open(ROOT/'authorization-audit.jsonl',
                         os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, 0o600)
    try:
        info = os.fstat(descriptor)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600
                and info.st_nlink == 1, 'Unsafe authorization audit file.')
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        record = {'time': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  'stage': progress.stage, 'reason': progress.reason}
        data = (json.dumps(record, separators=(',', ':'))+'\n').encode('ascii')
        require(os.write(descriptor, data) == len(data), 'Incomplete authorization audit write.')
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def record_failure(progress):
    """Audit unavailability must never turn an authorization refusal into success."""
    try:
        audit_failure(progress)
    except (Exception, KeyboardInterrupt):
        print('STOP: Root-only authorization audit unavailable; deployment remains refused/failed.', file=sys.stderr)


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
        require(re.fullmatch(evidence.IMAGE, manifest['image'])
                and re.fullmatch(r'sha256:[a-f0-9]{64}', manifest['tested_image_id'])
                and manifest.get('platform') == 'linux/amd64', 'Invalid immutable image identity/platform.')
        # Buildx uses the protected DOCKER_CONFIG credentials. Hash the exact raw bytes,
        # never formatted JSON or a tag, before trusting the configuration descriptor.
        raw = self.run(DOCKER+['buildx', 'imagetools', 'inspect', '--raw', manifest['image']])
        repository_digest = manifest['image'].split('@', 1)[1]
        require('sha256:'+hashlib.sha256(raw.encode('utf-8')).hexdigest() == repository_digest,
                'Registry manifest digest mismatch.')
        registry = json.loads(raw)
        require(registry.get('schemaVersion') == 2 and registry.get('mediaType') in (
                    'application/vnd.oci.image.manifest.v1+json',
                    'application/vnd.docker.distribution.manifest.v2+json')
                and 'manifests' not in registry, 'Single-platform image manifest required.')
        config = registry.get('config', {})
        require(config.get('mediaType') in ('application/vnd.oci.image.config.v1+json',
                                           'application/vnd.docker.container.image.v1+json')
                and config.get('digest') == manifest['tested_image_id'],
                'Registry configuration differs from smoke-tested publication.')
        info = json.loads(self.run(DOCKER+['image', 'inspect', manifest['image']]))[0]
        # Classic stores identify images by config; containerd stores may use the manifest.
        require(info['Id'] in (config['digest'], repository_digest) and manifest['image'] in info['RepoDigests'],
                'Image differs from smoke-tested publication.')
        require(info['Os'] == 'linux' and info['Architecture'] == 'amd64'
                and info['Config']['Labels'].get('org.opencontainers.image.revision') == manifest['source_commit']
                and info['Config']['Labels'].get('org.opencontainers.image.source') == 'https://github.com/'+evidence.REPOSITORY,
                'Image platform/source mismatch.')
        return info['Id']

    def running_image(self, container, manifest):
        local_id = self.image(manifest)
        labels = container['Config']['Labels']
        require(container['Config']['Image'] == manifest['image'] and container['Image'] == local_id
                and labels.get('org.opencontainers.image.revision') == manifest['source_commit']
                and labels.get('org.opencontainers.image.source') == 'https://github.com/'+evidence.REPOSITORY,
                'Running container image/source mismatch.')

    def schema(self, manifest, baseline=None):
        status = json.loads(self.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php',
                                   'app', '-r', SCHEMA_PROBE))
        require(status['inventory'] == manifest['migration_inventory'], 'Image migration hashes differ from manifest.')
        expected = [Path(row['path']).stem for row in (baseline or manifest)['migration_inventory']]
        if sorted(status['ran']) != sorted(expected):
            raise auth.SchemaReviewRequired('Live schema mismatch; separate controlled migration/recovery required.')

    def stream(self, command, destination):
        """Stream confidential backups to exclusive private files, never CI output or RAM."""
        self.sequence += 1
        with destination.open('xb') as output, (self.history/(str(self.sequence)+'.log')).open('xb') as errors:
            result = subprocess.run(command, env=self.environment, cwd=ROOT/'release',
                                    stdout=output, stderr=errors, timeout=600)
            output.flush()
            os.fsync(output.fileno())
        require(result.returncode == 0 and destination.stat().st_size > 0,
                'Backup failed; preserve partial evidence and maintenance.')

    def backup(self, current, db, verifier=None):
        destination = protected(ROOT/'backups')/self.history.name
        destination.mkdir(mode=0o700)
        client = protected(ROOT/'config/backup.cnf', True)
        self.run(DOCKER+['cp', str(client), db['Id']+':/tmp/dcms-uat-backup.cnf'])
        self.stream(self.compose+['exec', '-T', 'db', 'sh', '-eu', '-c',
                    'chmod 600 /tmp/dcms-uat-backup.cnf; '
                    'trap "rm -f /tmp/dcms-uat-backup.cnf" EXIT; '
                    'mysqldump --defaults-extra-file=/tmp/dcms-uat-backup.cnf '
                    '--host=localhost --single-transaction --no-tablespaces --triggers --routines --events '
                    '--hex-blob --set-gtid-purged=OFF --databases dcms_uat'], destination/'database.sql')
        self.stream(DOCKER+['run', '--rm', '--pull', 'never', '--network', 'none', '--read-only',
                    '--user', '33:33', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                    '--entrypoint', 'tar',
                    '--mount', 'type=volume,src='+PROJECT+'_documents,dst=/documents,readonly',
                    '--mount', 'type=volume,src='+PROJECT+'_private,dst=/private,readonly',
                    current['image'], '-cpf', '-', '-C', '/', 'documents', 'private'], destination/'documents.tar')
        with tarfile.open(destination/'configuration.tar', 'x') as archive:
            for filename in ('app.env', 'compose.env', 'db-password', 'db-root-password',
                             'migration.env', 'backup.cnf', 'current.json', 'automation.json'):
                archive.add(protected(ROOT/'config'/filename, True), arcname=filename, recursive=False)
        sums = []
        for filename in ('database.sql', 'documents.tar', 'configuration.tar'):
            sums.append(sha256_file(destination/filename)+'  '+filename)
        (destination/'SHA256SUMS').write_text('\n'.join(sums)+'\n')
        # UAT recovery policy requires verified paired off-host backups before migration.
        # The enrolled root-owned hook must fail unless that policy has been satisfied.
        if verifier is None:
            self.run([str(protected(ROOT/'config/verify-backup')), str(destination)], timeout=600)
        else:
            verifier(destination)
        write_json(self.history/'backup.json', {'directory': str(destination), 'verified': True})

    def migrate(self):
        credentials = protected(ROOT/'config/migration.env', True)
        values = dict(line.split('=', 1) for line in credentials.read_text().splitlines()
                      if line and not line.startswith('#'))
        require(set(values) == {'DB_USERNAME', 'DB_PASSWORD'}
                and values['DB_USERNAME'] == 'dcms_uat_migrate' and values['DB_PASSWORD'],
                'Separate UAT migration credentials required.')
        self.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php',
                '--env-from-file', str(credentials), 'app', 'artisan', 'migrate', '--force', '--no-interaction', timeout=600)

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


def execute(envelope, progress=None):
    progress = auth.AuthorizationProgress() if progress is None else progress
    progress.mark('entrypoint', 'ROOT_ENTRYPOINT_REQUIRED')
    require(os.geteuid() == 0 and len(sys.argv) == 1, 'Fixed root entry point required.')
    os.umask(0o077)
    progress.mark('custody', 'ROOT_CUSTODY_INVALID')
    protected(ROOT/'release/docker/deploy-uat.py')
    protected(ROOT/'history')
    protected(ROOT/'deployment.lock', True)
    progress.mark('lock', 'DEPLOYMENT_LOCK_UNAVAILABLE')
    with (ROOT/'deployment.lock').open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        progress.mark('recovery', 'RECOVERY_REQUIRED')
        recovery_flag = ROOT/'config/recovery-required.json'
        require(not recovery_flag.exists(), 'Previous deployment requires administrator recovery inspection.')
        progress.mark('configuration', 'CONFIGURATION_INVALID')
        policy = read_json(ROOT/'config/automation.json')
        current = read_json(ROOT/'config/current.json')
        previous_file = ROOT/'config/previous.json'
        previous = read_json(previous_file) if previous_file.exists() else None
        progress.mark('configuration', 'HEALTH_URL_INVALID')
        require(re.fullmatch(r'https://[a-z0-9]+(?:[.-][a-z0-9]+)*:8444/up', policy.get('health_url', '')), 'Approved HTTPS UAT health URL required.')
        progress.mark('configuration', 'AUTOMATION_DISABLED')
        require(policy.get('enabled') is True and policy.get('target') == 'uat'
                and policy.get('project') == PROJECT, 'UAT automation is not enrolled/enabled.')
        progress.mark('custody', 'ROOT_CUSTODY_INVALID')
        protected(ROOT/'release/docker/github-uat-auth.py')
        api, claims, release, manifest = authorize(envelope, policy, current, previous, progress=progress)
        request = envelope['request']
        request_id = 'gha-'+str(request['deployment_run_id'])+'-'+str(request['deployment_run_attempt'])

        def verify():
            require(read_json(ROOT/'config/automation.json') == policy, 'Server authorization policy changed; stop and review.')
            auth.workflow_current(api, request, claims, policy)
            api.release_current(release['run'])
            artifact = api.get('actions/artifacts/'+str(request['release_artifact_id']))
            require(artifact.get('id') == request['release_artifact_id'] and artifact.get('expired') is False
                    and artifact.get('workflow_run', {}).get('id') == request['release_run_id'], 'Release artifact expired/removed.')

        # A dispatch run/attempt is single-use, including after partial failure.
        progress.mark('history', 'HISTORY_RESERVATION_FAILED')
        history = ROOT/'history'/request_id
        history.mkdir(mode=0o700)
        progress.mark('deployment', 'DEPLOYMENT_FAILED')
        manifest_file = history/'manifest.json'
        manifest_file.write_bytes(release['manifest'])
        for name in ('run', 'jobs', 'artifacts'):
            write_json(history/(name+'.json'), release[name])
        write_json(history/'previous-manifest.json', current)
        write_json(history/'request.json', request)
        write_json(history/'authorization.json', claims)
        write_json(history/'policy.json', policy)
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
            deployment.running_image(old_app, current)
            deployment.storage(old_app, before)
            deployment.run(DOCKER+['pull', manifest['image']], timeout=600)
            deployment.image(manifest)
            deployment.schema(manifest, baseline=current)
            verify()
            write_json(recovery_flag, {'request_id': request_id, 'previous_image': current['image'], 'image': manifest['image']})
            for filename in ('backup.cnf', 'migration.env'):
                protected(ROOT/'config'/filename, True)
            hook = protected(ROOT/'config/verify-backup')
            require(hook.is_file() and os.access(hook, os.X_OK), 'Enrolled recovery verification hook required.')
            protected(ROOT/'backups')
            maintenance = True
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
            deployment.maintenance_active()
            verify()
            # The enrolled profile has synchronous queues and no external writers. Stop web
            # workers before taking the paired database/file snapshot. Never stop the DB.
            deployment.dc('stop', '--timeout', '30', 'app')
            deployment.backup(current, before)
            verify()
            if manifest['migration_inventory'] != current['migration_inventory']:
                deployment.migrate()
                deployment.schema(manifest)
            deployment.replace()
            new_app = deployment.container('app')
            deployment.running_image(new_app, manifest)
            require(sorted(new_app['Mounts'], key=lambda row: row['Destination']) == sorted(old_app['Mounts'], key=lambda row: row['Destination']), 'Application persistent mounts changed.')
            deployment.maintenance_active()
            deployment.health()
            deployment.schema(manifest)
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
            prior = ROOT/'config/previous.json.next'
            write_json(prior, current)
            os.replace(prior, ROOT/'config/previous.json')
            staged = ROOT/'config/current.json.next'
            write_json(staged, manifest)
            os.replace(staged, ROOT/'config/current.json')
            os.replace(candidate_env, compose_env)
            write_json(history/'result.json', {'status': 'deployed', 'image': manifest['image'],
                                              'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            recovery_flag.unlink()
            print('UAT deployment completed. Formal XPPL acceptance remains separate.')
        except (Exception, KeyboardInterrupt):
            signal.alarm(0)
            if maintenance and deployment:
                try:
                    # The shared marker was established before stopping the old app.
                    deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
                except Exception:
                    pass
            write_json(history/'result.json', {'status': 'failed', 'maintenance_requested': maintenance,
                                              'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            raise


def interrupted(signum, frame):
    raise RuntimeError('Deployment interrupted.')


def main():
    progress = auth.AuthorizationProgress()
    for signum in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT, signal.SIGALRM):
        signal.signal(signum, interrupted)
    try:
        signal.alarm(15)
        envelope = request_from(sys.stdin.buffer)
        signal.alarm(2400)
        execute(envelope, progress=progress)
        signal.alarm(0)
    except auth.SchemaReviewRequired:
        signal.alarm(0)
        record_failure(progress)
        sys.exit('STOP: UAT schema mismatch. A separate controlled migration/recovery is required. '
                 'No automatic migrations were run. Inspect protected history and maintenance state.')
    except (Exception, KeyboardInterrupt):
        signal.alarm(0)
        record_failure(progress)
        # Do not expose arbitrary request, Docker, application or secret material over SSH.
        sys.exit('STOP: UAT deployment refused/failed. Administrator must inspect root-only authorization audit, '
                 'protected history and maintenance state.')


if __name__ == '__main__':
    main()
