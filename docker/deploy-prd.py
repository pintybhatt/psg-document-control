#!/usr/bin/env python3
"""Restricted, forward-only Production app deployment. Initial installation is deliberately separate."""
import configparser
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tarfile


spec = importlib.util.spec_from_file_location('prd_backup', Path(__file__).with_name('prd-backup.py'))
paired = importlib.util.module_from_spec(spec)
spec.loader.exec_module(paired)
shared = paired.shared
require, protected = shared.require, shared.protected
ROOT = Path('/srv/dcms-prd')
PROJECT = 'dcms-prd-xppl'
DOCKER = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock']
ENVIRONMENT = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root',
               'DOCKER_HOST': 'unix:///var/run/docker.sock', 'DOCKER_CONFIG': str(ROOT/'registry')}
WORKFLOW = '.github/workflows/deploy-prd.yml'
REQUEST_KEYS = {'deployment_run_id', 'deployment_run_attempt', 'release_run_id', 'release_run_attempt',
                'release_artifact_id', 'image', 'source_commit', 'manifest_sha256'}
STAGES = {'release_evidence', 'configuration', 'existing_runtime', 'candidate_image', 'maintenance',
          'backup_restore_verification', 'migrations', 'application_replacement', 'health', 'record_success'}
SCHEMA_PROBE = r'''
$files = glob("database/migrations/*.php"); sort($files); $inventory = [];
foreach ($files as $file) { $inventory[] = ["path" => $file, "sha256" => hash_file("sha256", $file)]; }
$db = new PDO("mysql:host=db;port=3306;dbname=dcms_prd;charset=utf8mb4", getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ran = $db->query("SELECT migration,batch FROM migrations ORDER BY migration")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(["inventory" => $inventory, "ran" => $ran]);
'''


def write_json(path, value):
    temporary = path.with_name(path.name+'.next')
    with temporary.open('x', encoding='utf-8') as output:
        json.dump(value, output, indent=2)
        output.write('\n')
        output.flush()
        os.fsync(output.fileno())
    os.replace(temporary, path)
    paired.sync_directory(path.parent)


def read_json(path):
    return shared.auth.parse_json(protected(path, True).read_bytes())


def validate_request(data):
    require(isinstance(data, dict) and set(data) == REQUEST_KEYS, 'Invalid Production request fields.')
    for key in REQUEST_KEYS-{'image', 'source_commit', 'manifest_sha256'}:
        require(type(data[key]) is int and 0 < data[key] < 10**20, 'Invalid workflow run/artifact identity.')
    for key, pattern in (('image', shared.evidence.IMAGE), ('source_commit', r'[a-f0-9]{40}'), ('manifest_sha256', r'[a-f0-9]{64}')):
        require(isinstance(data[key], str) and re.fullmatch(pattern, data[key]), 'Invalid immutable release identity.')
    return data


def request(stream):
    raw = stream.read(4097)
    require(len(raw) <= 4096, 'Oversized deployment request.')
    return validate_request(shared.auth.parse_json(raw))


def inventory(manifest):
    rows = manifest.get('migration_inventory')
    require(isinstance(rows, list) and rows, 'Migration inventory required.')
    require(all(isinstance(row, dict) and set(row) == {'path', 'sha256'}
                and re.fullmatch(r'database/migrations/[a-zA-Z0-9_]+\.php', row['path'])
                and re.fullmatch(r'[a-f0-9]{64}', row['sha256']) for row in rows), 'Invalid migration inventory.')
    names = [row['path'] for row in rows]
    require(names == sorted(set(names)), 'Unique ordered migrations required.')
    return rows


def forward(current, candidate):
    existing, selected = inventory(current), inventory(candidate)
    require(selected[:len(existing)] == existing, 'Modified/missing deployed migrations or schema downgrade refused.')


def workflow_current(api, data):
    run = api.get('actions/runs/'+str(data['deployment_run_id']))
    require(run.get('id') == data['deployment_run_id'] and run.get('run_attempt') == data['deployment_run_attempt']
            and run.get('path') in (WORKFLOW, WORKFLOW+'@refs/heads/main') and run.get('name') == 'Deploy DCMS PRD'
            and run.get('event') == 'workflow_dispatch' and run.get('head_branch') == 'main'
            and run.get('status') == 'in_progress'
            and run.get('repository', {}).get('full_name') == shared.evidence.REPOSITORY
            and run.get('head_repository', {}).get('full_name') == shared.evidence.REPOSITORY,
            'Active trusted Production deployment workflow required.')


def authorize(data, current):
    # Read credential belongs to the server. No workflow credential/path is accepted.
    api = shared.auth.GitHub(protected(ROOT/'config/github-read-token', True).read_text().strip())
    workflow_current(api, data)
    release = api.release(data['release_run_id'], data['release_artifact_id'])
    manifest = shared.auth.parse_json(release['manifest'])
    require(manifest['image'] == data['image'] and manifest['source_commit'] == data['source_commit']
            and hashlib.sha256(release['manifest']).hexdigest() == data['manifest_sha256']
            and release['run']['id'] == data['release_run_id']
            and release['run']['run_attempt'] == data['release_run_attempt'], 'Request/publication image/source mismatch.')
    shared.evidence.verify_publication(release['manifest'], release['run'], release['jobs'], release['artifacts'], data['release_artifact_id'])
    require(api.get('compare/'+current['source_commit']+'...'+manifest['source_commit']).get('status') in ('ahead', 'identical'), 'Source downgrade/divergence refused.')
    require(api.get('compare/'+manifest['source_commit']+'...main').get('status') in ('ahead', 'identical'), 'Published source must belong to main history.')
    require(manifest['image'] != current['image'], 'Selected release is already deployed.')
    forward(current, manifest)
    return api, release, manifest


class Deployment:
    # These reused methods select services through self.dc and contain no UAT paths/identity.
    image = shared.Deployment.image
    running_image = shared.Deployment.running_image
    invariant = shared.Deployment.invariant
    maintenance_active = shared.Deployment.maintenance_active
    replace = shared.Deployment.replace
    wait_healthy = shared.Deployment.wait_healthy
    health = shared.Deployment.health

    def __init__(self, history, compose_env):
        self.history, self.sequence = history, 0
        self.environment = dict(ENVIRONMENT)
        self.compose = DOCKER+['compose', '--project-name', PROJECT, '--env-file', str(compose_env),
                              '-f', str(ROOT/'release/compose.release.yaml'), '-f', str(ROOT/'release/compose.prd.yaml')]

    def run(self, command, timeout=180):
        self.sequence += 1
        # Stream diagnostics privately, including on timeout; never echo command output to CI.
        log = self.history/(str(self.sequence)+'.log')
        with log.open('xb') as errors:
            result = subprocess.run(command, env=self.environment, cwd=ROOT/'release', stdout=subprocess.PIPE,
                                    stderr=errors, timeout=timeout)
            errors.write(result.stdout)
        require(result.returncode == 0, 'Production command failed; inspect private stage/history.')
        return result.stdout.decode()

    def dc(self, *arguments, timeout=180):
        return self.run(self.compose+list(arguments), timeout)

    def container(self, service):
        require(service in ('app', 'db'), 'Unexpected service.')
        identities = self.dc('ps', '--all', '-q', service).split()
        require(len(identities) == 1 and re.fullmatch(r'[a-f0-9]{64}', identities[0]), 'Initialized Production service required.')
        info = json.loads(self.run(DOCKER+['container', 'inspect', identities[0]]))[0]
        labels = info['Config']['Labels']
        require(labels.get('com.docker.compose.project') == PROJECT and labels.get('com.docker.compose.service') == service
                and not labels.get('com.docker.compose.oneoff', 'False').lower() == 'true'
                and info['State']['Running'], 'Foreign/unexpected or stopped Production container refused.')
        return info

    def topology(self, app, db):
        require(set(self.dc('ps', '--all', '-q').split()) == {app['Id'], db['Id']}, 'Unexpected Production containers refused.')
        for info, networks in ((app, {PROJECT+'_ingress', PROJECT+'_backend'}), (db, {PROJECT+'_backend'})):
            require(set(info['NetworkSettings']['Networks']) == networks, 'Foreign runtime networks refused.')
        for name in ('ingress', 'backend'):
            network = json.loads(self.run(DOCKER+['network', 'inspect', PROJECT+'_'+name]))[0]
            require(network['Labels'].get('com.docker.compose.project') == PROJECT
                    and network['Driver'] == 'bridge' and network['Internal'] == (name == 'backend'), 'Foreign runtime network refused.')
        db_env = dict(value.split('=', 1) for value in db['Config']['Env'] if '=' in value)
        require(db_env.get('MYSQL_DATABASE') == 'dcms_prd' and db_env.get('MYSQL_USER') == 'dcms_prd'
                and not db['HostConfig'].get('PortBindings'), 'Production database runtime identity/ports required.')
        app_env = dict(value.split('=', 1) for value in app['Config']['Env'] if '=' in value)
        expected = {'APP_ENV': 'production', 'APP_URL': 'https://dcms.phonesackgroup.com:9444',
                    'DB_DATABASE': 'dcms_prd', 'DB_USERNAME': 'dcms_prd', 'DB_HOST': 'db'}
        require(all(app_env.get(key) == value for key, value in expected.items())
                and app['HostConfig'].get('PortBindings') == {'8080/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18085'}]},
                'Production application runtime identity/ports required.')
        self.storage(app, db)

    def storage(self, app, db):
        for info, expected in ((app, {'/var/www/html/storage/app/document-vault': 'documents',
                                      '/var/www/html/storage/app/private': 'private', '/var/www/html/storage/framework': 'framework'}),
                               (db, {'/var/lib/mysql': 'database'})):
            mounts = info['Mounts']
            require(len({mount['Destination'] for mount in mounts}) == len(mounts), 'Duplicate runtime mounts refused.')
            for destination, name in expected.items():
                found = [mount for mount in mounts if mount['Destination'] == destination]
                require(len(found) == 1 and found[0].get('Type') == 'volume' and found[0].get('Name') == PROJECT+'_'+name,
                        'Production volume identity required.')
                volume = json.loads(self.run(DOCKER+['volume', 'inspect', PROJECT+'_'+name]))[0]
                require(volume['Driver'] == 'local' and volume.get('Scope', 'local') == 'local' and not volume.get('Options')
                        and volume['Labels'].get('com.docker.compose.project') == PROJECT
                        and volume['Labels'].get('com.docker.compose.volume') == name, 'External/host-mounted/foreign volume refused.')
            for mount in mounts:
                if mount['Destination'] in expected:
                    continue
                if info is app and mount['Destination'] in ('/tmp', '/var/www/html/bootstrap/cache'):
                    require(mount['Type'] == 'tmpfs', 'Only bounded runtime tmpfs allowed.')
                else:
                    sources = ({'/etc/apache2/conf-enabled/zz-dcms-prd.conf': ROOT/'release/docker/apache-prd.conf'} if info is app else
                               {'/run/secrets/db_password': ROOT/'config/db-password', '/run/secrets/db_root_password': ROOT/'config/db-root-password'})
                    require(mount['Type'] == 'bind' and not mount['RW'] and mount['Destination'] in sources
                            and mount['Source'] == str(sources[mount['Destination']]), 'Unapproved host mount refused.')

    def preflight(self, manifest, compose_env):
        self.run(['/usr/bin/python3', '-I', '-B', str(ROOT/'release/docker/preflight.py'), '--target', 'production',
                  '--prd-profile', '--project', PROJECT, '--compose-env', str(compose_env), '--manifest', str(manifest)])

    def schema(self, manifest, baseline=None, previous_rows=None):
        state = json.loads(self.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php', 'app', '-r', SCHEMA_PROBE))
        require(state['inventory'] == inventory(manifest), 'Candidate migration files differ from published inventory.')
        rows = state['ran']
        expected = sorted(Path(row['path']).stem for row in inventory(baseline or manifest))
        require(sorted(row['migration'] for row in rows) == expected and all(type(row['batch']) is int and row['batch'] > 0 for row in rows),
                'Incompatible live migration state; review required.')
        if previous_rows is not None:
            current = {row['migration']: row['batch'] for row in rows}
            require(all(current.get(row['migration']) == row['batch'] for row in previous_rows), 'Existing migration history changed.')
            previous = {row['migration'] for row in previous_rows}
            require(all(row['batch'] > max(item['batch'] for item in previous_rows) for row in rows if row['migration'] not in previous),
                    'New migrations must advance the recorded batch history.')
        return rows

    def credentials(self, config):
        migration = protected(ROOT/'config/migration.env', True).read_text().splitlines()
        values = dict(line.split('=', 1) for line in migration if line and not line.startswith('#'))
        require(set(values) == {'DB_USERNAME', 'DB_PASSWORD'} and values['DB_USERNAME'] == 'dcms_prd_migrate'
                and values['DB_PASSWORD'] and len(values) == len([line for line in migration if line and not line.startswith('#')]),
                'Dedicated Production migration credentials required.')
        backup = configparser.ConfigParser(interpolation=None)
        backup.read_string(protected(ROOT/'config/backup.cnf', True).read_text())
        require(backup.sections() == ['client'] and not backup.defaults() and set(backup['client']) == {'user', 'password'}
                and backup['client']['user'] == 'dcms_prd_backup' and backup['client']['password'], 'Dedicated Production backup credentials required.')
        passwords = [values['DB_PASSWORD'], backup['client']['password'], config['services']['app']['environment']['DB_PASSWORD'],
                     protected(ROOT/'config/db-root-password', True).read_text().rstrip('\n')]
        require(len(set(passwords)) == 4, 'Application, backup, migration and root credentials must differ.')
        paired.target()

    def stream(self, command, destination):
        self.sequence += 1
        with destination.open('xb') as output, (self.history/(str(self.sequence)+'.log')).open('xb') as errors:
            result = subprocess.run(command, env=self.environment, cwd=ROOT/'release', stdout=output, stderr=errors, timeout=600)
            output.flush()
            os.fsync(output.fileno())
        require(result.returncode == 0 and destination.stat().st_size > 0, 'Backup incomplete; preserve recovery evidence.')

    def backup(self, current, db):
        destination = protected(ROOT/'backups')/self.history.name
        destination.mkdir(mode=0o700)
        paired.sync_directory(destination.parent)
        client = protected(ROOT/'config/backup.cnf', True)
        self.run(DOCKER+['cp', str(client), db['Id']+':/tmp/dcms-prd-backup.cnf'])
        self.stream(self.compose+['exec', '-T', 'db', 'sh', '-eu', '-c',
                    'chmod 600 /tmp/dcms-prd-backup.cnf; trap "rm -f /tmp/dcms-prd-backup.cnf" EXIT; '
                    'mysqldump --defaults-extra-file=/tmp/dcms-prd-backup.cnf --host=localhost '
                    '--single-transaction --no-tablespaces --triggers --routines --events --hex-blob '
                    '--set-gtid-purged=OFF --databases dcms_prd'], destination/'database.sql')
        self.stream(DOCKER+['run', '--rm', '--pull', 'never', '--network', 'none', '--read-only', '--user', '33:33',
                    '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--entrypoint', 'tar',
                    '--mount', 'type=volume,src='+PROJECT+'_documents,dst=/documents,readonly',
                    '--mount', 'type=volume,src='+PROJECT+'_private,dst=/private,readonly', current['image'],
                    '-cpf', '-', '-C', '/', 'documents', 'private'], destination/'documents.tar')
        with tarfile.open(destination/'configuration.tar', 'x') as archive:
            for filename in paired.CONFIG:
                source = paired.configuration_path(filename)
                archive.add(protected(source, filename not in paired.RELEASE_CONFIG), arcname=filename, recursive=False)
        with (destination/'configuration.tar').open('rb') as archive:
            os.fsync(archive.fileno())
        write_json(destination/'identity.json', {'backup_id': self.history.name, 'project': PROJECT, 'database': 'dcms_prd',
                                               'image': current['image'], 'source_commit': current['source_commit']})
        sums = [shared.sha256_file(destination/name)+'  '+name for name in paired.PAYLOAD]
        with (destination/'SHA256SUMS').open('x') as output:
            output.write('\n'.join(sums)+'\n')
            output.flush()
            os.fsync(output.fileno())
        receipt = paired.send(destination)
        write_json(self.history/'backup.json', dict(receipt, directory=str(destination)))

    def migrate(self):
        self.dc('run', '--rm', '--no-deps', '--pull', 'never', '-T', '--entrypoint', 'php',
                '--env-from-file', str(ROOT/'config/migration.env'), 'app', 'artisan', 'migrate', '--force', '--no-interaction', timeout=600)

    def endpoints(self):
        for origin in ('http://127.0.0.1:18085', 'https://dcms.phonesackgroup.com:9444'):
            base = ['/usr/bin/curl', '--silent', '--show-error', '--max-time', '10', '--output', '/dev/null', '--write-out', '%{http_code}']
            code = self.run(base+['--fail', '--retry', '5', '--retry-all-errors', '--retry-delay', '2', '--retry-max-time', '80', origin+'/up'], timeout=100)
            require(code == '200', 'Production health endpoint failed.')
            for path in ('/storage/private-check', '/document-vault/private-check', '/private/private-check', '/.env', '/.git/config'):
                require(self.run(base+[origin+path]) in ('403', '404'), 'Protected attachment/configuration path exposed.')


def execute(data):
    validate_request(data)
    require(os.geteuid() == 0, 'Restricted root entry point required.')
    os.umask(0o077)
    for name in ('release/docker/deploy-prd.py', 'release/docker/prd-backup.py', 'release/docker/deploy-uat.py',
                 'release/docker/github-uat-auth.py', 'release/docker/verify-uat-release.py',
                 'history', 'backups', 'config', 'registry/config.json'):
        protected(ROOT/name)
    lock_path = protected(ROOT/'deployment.lock', True)
    with lock_path.open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        flag = ROOT/'config/recovery-required.json'
        require(not flag.exists(), 'Previous Production deployment requires recovery review.')
        history = ROOT/'history'/('gha-'+str(data['deployment_run_id'])+'-'+str(data['deployment_run_attempt']))
        history.mkdir(mode=0o700)
        paired.sync_directory(history.parent)
        stage, maintenance, deployment = 'release_evidence', False, None
        write_json(history/'request.json', data)

        def mark(value):
            nonlocal stage
            require(value in STAGES, 'Unknown deployment stage.')
            stage = value
            write_json(history/'result.json', {'status': 'running', 'stage': stage, 'backup_id': history.name})
            if maintenance:
                write_json(flag, {'backup_id': history.name, 'stage': stage})

        try:
            mark('release_evidence')
            current = read_json(ROOT/'config/current.json')
            api, release, manifest = authorize(data, current)
            for name in ('run', 'jobs', 'artifacts'):
                write_json(history/(name+'.json'), release[name])
            write_json(history/'manifest.json', manifest)
            write_json(history/'previous-manifest.json', current)
            mark('configuration')
            for name in ('compose.release.yaml', 'compose.prd.yaml', 'docker/apache-prd.conf', 'docker/preflight.py'):
                protected(ROOT/'release'/name)
            config_path = protected(ROOT/'config/compose.env', True)
            lines = config_path.read_text().splitlines()
            require(lines.count('DCMS_IMAGE='+current['image']) == 1 and sum(line.startswith('DCMS_IMAGE=') for line in lines) == 1, 'Current Production config image mismatch.')
            candidate = history/'compose.env'
            with candidate.open('x') as output:
                output.write('\n'.join('DCMS_IMAGE='+manifest['image'] if line.startswith('DCMS_IMAGE=') else line for line in lines)+'\n')
                output.flush()
                os.fsync(output.fileno())
            paired.sync_directory(history)
            deployment = Deployment(history, candidate)
            deployment.preflight(ROOT/'config/current.json', config_path)
            deployment.preflight(history/'manifest.json', candidate)
            config = json.loads(deployment.dc('config', '--format', 'json'))
            deployment.credentials(config)
            snapshot = {paired.configuration_path(name): (shared.sha256_file(protected(paired.configuration_path(name), name not in paired.RELEASE_CONFIG)),
                                                         name not in paired.RELEASE_CONFIG)
                        for name in paired.CONFIG}

            def unchanged():
                require(all(shared.sha256_file(protected(path, private)) == digest for path, (digest, private) in snapshot.items()), 'Production configuration changed during deployment.')
                workflow_current(api, data)
                api.release_current(release['run'])

            mark('existing_runtime')
            database, app = deployment.container('db'), deployment.container('app')
            require(database['State']['Health']['Status'] == 'healthy' and app['State']['Health']['Status'] == 'healthy', 'Healthy initialized Production required; bootstrap is separate.')
            require(database['Config']['Image'] == config['services']['db']['image'], 'Database runtime image mismatch; routine deployment cannot replace MySQL.')
            deployment.topology(app, database)
            actual_env = dict(value.split('=', 1) for value in app['Config']['Env'] if '=' in value)
            require(all(actual_env.get(key) == config['services']['app']['environment'][key] for key in ('APP_KEY', 'DB_PASSWORD')),
                    'Existing Production key/credentials differ from protected configuration.')
            deployment.running_image(app, current)
            mark('candidate_image')
            deployment.run(DOCKER+['pull', manifest['image']], timeout=600)
            deployment.image(manifest)
            rows = deployment.schema(manifest, baseline=current)
            unchanged()
            mark('maintenance')
            write_json(flag, {'backup_id': history.name, 'previous_image': current['image'], 'image': manifest['image'], 'stage': stage})
            maintenance = True
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
            deployment.maintenance_active()
            deployment.dc('stop', '--timeout', '30', 'app')
            mark('backup_restore_verification')
            deployment.backup(current, database)
            deployment.invariant(database)
            unchanged()
            mark('migrations')
            if manifest['migration_inventory'] != current['migration_inventory']:
                deployment.migrate()
            deployment.schema(manifest, previous_rows=rows)
            mark('application_replacement')
            deployment.replace()
            replacement = deployment.container('app')
            deployment.running_image(replacement, manifest)
            require(sorted(replacement['Mounts'], key=lambda row: row['Destination']) == sorted(app['Mounts'], key=lambda row: row['Destination']), 'Production application mounts changed.')
            deployment.topology(replacement, deployment.container('db'))
            deployment.maintenance_active()
            mark('health')
            deployment.health()
            deployment.schema(manifest, previous_rows=rows)
            deployment.invariant(database)
            unchanged()
            deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'up', '--no-interaction')
            deployment.endpoints()
            deployment.wait_healthy()
            deployment.invariant(database)
            unchanged()
            mark('record_success')
            write_json(ROOT/'config/previous.json', current)
            write_json(ROOT/'config/current.json', manifest)
            os.replace(candidate, config_path)
            paired.sync_directory(config_path.parent)
            write_json(history/'result.json', {'status': 'deployed', 'stage': 'complete', 'image': manifest['image'], 'backup_id': history.name,
                                              'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            flag.unlink()
            paired.sync_directory(flag.parent)
        except (Exception, KeyboardInterrupt) as error:
            signal.alarm(0)
            error.production_stage = stage
            if maintenance:
                try:
                    deployment.dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--no-interaction')
                except Exception:
                    pass
                try:
                    write_json(flag, {'backup_id': history.name, 'stage': stage, 'review_required': True})
                except OSError:
                    pass
            try:
                write_json(history/'result.json', {'status': 'failed', 'stage': stage, 'recovery_required': maintenance,
                                                  'time': datetime.datetime.now(datetime.timezone.utc).isoformat()})
            except OSError:
                pass
            raise


def main():
    def interrupted(*_):
        raise RuntimeError('Interrupted deployment.')
    for signum in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT, signal.SIGALRM):
        signal.signal(signum, interrupted)
    try:
        require(len(sys.argv) == 1, 'No arguments accepted.')
        signal.alarm(15)
        data = request(sys.stdin.buffer)
        signal.alarm(3600)
        execute(data)
        signal.alarm(0)
        print('DCMS PRD deployment completed; verified backup and health recorded.')
    except (Exception, KeyboardInterrupt) as error:
        signal.alarm(0)
        stage = getattr(error, 'production_stage', None)
        diagnostic = ' at '+stage if stage in STAGES else ''
        sys.exit('STOP: DCMS PRD deployment refused/failed'+diagnostic+'. Inspect private history/result.json and recovery-required.json; no automatic schema rollback or database restore.')


if __name__ == '__main__':
    main()
