#!/usr/bin/env python3
"""Run an explicitly approved, isolated smoke test; --plan never accesses the daemon."""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import signal
import subprocess
import sys
import tempfile
import uuid

LOCAL_HOST = 'unix:///var/run/docker.sock'
RUN_LABEL = 'io.phonesack.dcms.smoke-run'
PROJECT_LABEL = 'com.docker.compose.project'
KINDS = ('container', 'network', 'volume')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def validate_caller(environment, image, plan):
    require(environment.get('DCMS_DISPOSABLE_SMOKE') == '1', 'Requires DCMS_DISPOSABLE_SMOKE=1.')
    require(plan or environment.get('DCMS_SMOKE_CLEANUP') == '1',
            'Requires DCMS_SMOKE_CLEANUP=1: explicit consent to remove only newly created, verified test resources.')
    require(environment.get('DOCKER_HOST', '') in ('', LOCAL_HOST), 'Remote/alternative DOCKER_HOST refused.')
    require(environment.get('DOCKER_CONTEXT', '') in ('', 'default'), 'Non-default DOCKER_CONTEXT refused.')
    require(not environment.get('DOCKER_CONFIG'), 'Caller DOCKER_CONFIG refused; smoke uses a private empty client config.')
    for variable in ('DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'):
        require(not environment.get(variable), 'Docker TLS overrides refused for the local Unix socket.')
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', image), 'Supply an existing local image ID (sha256 plus 64 lowercase hex digits), never a tag/registry path.')


def docker_environment(environment, config_directory):
    # Preserve normal PATH/HOME/locale/proxy/certificate settings; control Docker,
    # Compose interpolation and app/database selectors explicitly, including future variables.
    prefixes = ('DOCKER_', 'COMPOSE_', 'DCMS_', 'DB_', 'MYSQL_', 'APP_', 'SESSION_', 'CACHE_', 'QUEUE_', 'MAIL_')
    result = {key: value for key, value in environment.items() if not key.startswith(prefixes)}
    result.update(DOCKER_HOST=LOCAL_HOST, DOCKER_CONFIG=str(config_directory),
                  COMPOSE_DISABLE_ENV_FILE='1', COMPOSE_MENU='0', COMPOSE_ANSI='never')
    return result


class SmokeRun:
    def __init__(self, image, environment):
        self.image = image
        self.token = uuid.uuid4().hex
        self.project = 'dcms-smoke-'+self.token
        require(re.fullmatch(r'dcms-smoke-[a-f0-9]{32}', self.project), 'Invalid generated project name.')
        self.directory = Path(tempfile.mkdtemp(prefix=self.project+'-', dir='/tmp'))
        self.directory.chmod(0o700)
        self.repository = Path(__file__).resolve().parent.parent
        self.config_directory = self.directory/'docker-config'
        self.config_directory.mkdir(mode=0o700)
        self.environment = docker_environment(environment, self.config_directory)
        self.executable = '/usr/bin/docker'
        require(os.access(self.executable, os.X_OK), 'The reviewed /usr/bin/docker CLI is required.')
        self.baseline = None
        self.changed = False
        self.redactions = []
        self.summary = {'project': self.project, 'run_label': self.token, 'image': image, 'result': 'pending', 'cleanup': 'not-needed'}

    def write(self, name, content):
        path = self.directory/name
        path.write_text(content)
        path.chmod(0o600)

    def record(self, text):
        for value in self.redactions:
            text = text.replace(value, '[REDACTED]')
        with (self.directory/'runtime.log').open('a') as log:
            log.write(text+'\n')
        (self.directory/'runtime.log').chmod(0o600)

    def docker(self, *arguments, log=False):
        result = subprocess.run([self.executable, '--host', LOCAL_HOST, '--config', str(self.config_directory), *arguments],
                                cwd=self.directory, env=self.environment, text=True, capture_output=True, timeout=300)
        # Never record rendered config or container environments. Command output is
        # private and synthetic values are redacted before logs are retained.
        if log or result.returncode:
            self.record(result.stdout+'\n'+result.stderr)
        require(result.returncode == 0, 'Docker step failed: '+arguments[0]+' (see private runtime.log).')
        return result.stdout

    def compose(self, *arguments, log=False):
        return self.docker('compose', '--project-name', self.project, '--project-directory', str(self.directory),
                           '--env-file', str(self.directory/'compose.env'), '-f', str(self.repository/'compose.release.yaml'),
                           '-f', str(self.directory/'smoke.json'), *arguments, log=log)

    def prepare(self):
        password, root_password = secrets.token_hex(32), secrets.token_hex(32)
        key = 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode()
        self.redactions = [password, root_password, key]
        self.write('password', password)
        self.write('root-password', root_password)
        self.write('app.env', '\n'.join([
            'APP_ENV=testing', 'APP_DEBUG=false', 'APP_KEY='+key, 'APP_URL=http://localhost',
            'DB_CONNECTION=mysql', 'DB_HOST=db', 'DB_PORT=3306', 'DB_DATABASE=dcms_smoke',
            'DB_USERNAME=dcms_smoke', 'DB_PASSWORD='+password, 'DB_URL=', 'DB_SOCKET=',
            'QUEUE_CONNECTION=sync', 'CACHE_STORE=database', 'SESSION_DRIVER=database',
            'LOG_CHANNEL=stderr', 'MAIL_MAILER=array',
        ])+'\n')
        self.write('compose.env', '\n'.join([
            'DCMS_ENVIRONMENT=testing', 'DCMS_IMAGE='+self.image, 'DCMS_BIND_ADDRESS=127.0.0.1', 'DCMS_HTTP_PORT=0',
            'DCMS_APP_ENV_FILE='+str(self.directory/'app.env'), 'DCMS_DB_DATABASE=dcms_smoke',
            'DCMS_DB_USERNAME=dcms_smoke', 'DCMS_DB_PASSWORD_FILE='+str(self.directory/'password'),
            'DCMS_DB_ROOT_PASSWORD_FILE='+str(self.directory/'root-password'),
        ])+'\n')
        labels = {RUN_LABEL: self.token}
        override = {
            'services': {
                'app': {'labels': labels, 'pull_policy': 'never', 'restart': 'no'},
                'db': {'labels': labels, 'restart': 'no', 'command': ['--skip-log-bin', '--mysqlx=OFF',
                       '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci']},
            },
            'volumes': {name: {'labels': labels} for name in ('database', 'documents', 'private', 'framework')},
            'networks': {name: {'labels': labels} for name in ('ingress', 'backend')},
        }
        self.write('smoke.json', json.dumps(override))
        config = json.loads(self.compose('config', '--format', 'json'))
        app, db = config['services']['app'], config['services']['db']
        require(set(config['services']) == {'app', 'db'} and config['name'] == self.project, 'Unexpected Compose services/project.')
        require(app['image'] == self.image and app['pull_policy'] == 'never', 'Application image selection changed.')
        require(app['environment']['DB_HOST'] == 'db' and app['environment']['DB_DATABASE'] == 'dcms_smoke'
                and app['environment']['DB_USERNAME'] == 'dcms_smoke' and not app['environment']['DB_URL'], 'Database isolation failed.')
        require(not db.get('ports'), 'MySQL must not publish a host port.')
        require(len(app['ports']) == 1 and app['ports'][0]['host_ip'] == '127.0.0.1'
                and str(app['ports'][0]['published']) == '0', 'Only an ephemeral loopback ingress is allowed.')
        for service in (app, db):
            require(all(mount['type'] == 'volume' for mount in service.get('volumes', [])), 'Host data bind mounts refused.')
        for kind in ('volumes', 'networks'):
            for name, resource in config[kind].items():
                require(resource.get('name') == self.project+'_'+name and not resource.get('external')
                        and resource['labels'].get(RUN_LABEL) == self.token, 'Shared/foreign resource refused.')
        self.summary['plan_validated'] = True

    def resources(self, kind, owned=False):
        arguments = [kind, 'ls', '--quiet']
        if kind == 'container':
            arguments += ['--all', '--no-trunc']
        if kind == 'network':
            arguments += ['--no-trunc']
        if owned:
            arguments += ['--filter', 'label='+PROJECT_LABEL+'='+self.project]
        return set(self.docker(*arguments).split())

    def approved_cleanup_targets(self):
        require(self.baseline is not None, 'No baseline inventory; cleanup refused.')
        targets = {}
        for kind in KINDS:
            targets[kind] = sorted(self.resources(kind, owned=True))
            for identity in targets[kind]:
                require(identity not in self.baseline[kind], 'Pre-existing resource; cleanup refused.')
                resource = json.loads(self.docker(kind, 'inspect', identity))[0]
                labels = resource.get('Config', {}).get('Labels', {}) if kind == 'container' else resource.get('Labels', {})
                require(labels and labels.get(PROJECT_LABEL) == self.project and labels.get(RUN_LABEL) == self.token,
                        'Foreign/unverified resource; cleanup refused before deleting anything.')
                if kind == 'container':
                    service = labels.get('com.docker.compose.service')
                    require(service in ('app', 'db') and resource['Name'].lstrip('/').startswith(self.project+'-'+service+'-')
                            and resource['Id'] == identity, 'Unexpected container identity; cleanup refused.')
                else:
                    names = ('database', 'documents', 'private', 'framework') if kind == 'volume' else ('ingress', 'backend')
                    require(resource['Name'] in {self.project+'_'+name for name in names}, 'Unexpected resource name; cleanup refused.')
                    require((resource['Name'] if kind == 'volume' else resource['Id']) == identity, 'Resource identity mismatch; cleanup refused.')
        return targets

    def cleanup(self):
        targets = self.approved_cleanup_targets()
        for kind, identities in targets.items():
            for identity in identities:
                # Delete by inspected container/network ID (volume names have no ID),
                # never by an unrelated project's name or a broad prune operation.
                arguments = [kind, 'rm']+(['--force'] if kind == 'container' else [])+[identity]
                self.docker(*arguments, log=True)
        require(not any(self.resources(kind, owned=True) for kind in KINDS), 'Disposable resources remain after cleanup.')
        self.summary['cleanup'] = 'verified-empty'
        self.summary['removed'] = targets

    def execute(self):
        info = json.loads(self.docker('image', 'inspect', self.image))[0]
        require(info['Id'] == self.image, 'Local image identity mismatch.')
        self.summary['source_commit'] = info.get('Config', {}).get('Labels', {}).get('org.opencontainers.image.revision')
        self.baseline = {kind: self.resources(kind) for kind in KINDS}
        require(not any(self.resources(kind, owned=True) for kind in KINDS), 'Generated project already exists; refusing reuse.')
        for kind in KINDS:
            names = self.docker(kind, 'ls', *(['--all'] if kind == 'container' else []), '--format', '{{.Name}}' if kind != 'container' else '{{.Names}}').split()
            require(not any(name.startswith(self.project+'-') or name.startswith(self.project+'_') for name in names),
                    'Pre-existing resource name collision; refusing reuse.')
        self.changed = True
        self.compose('up', '-d', '--wait', '--wait-timeout', '180', 'db', log=True)
        self.compose('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'migrate', '--force', '--no-interaction', log=True)
        self.compose('up', '-d', '--wait', '--wait-timeout', '120', 'app', log=True)
        self.compose('exec', '-T', 'app', 'php', 'artisan', 'migrate:status', '--no-interaction', log=True)
        self.compose('exec', '-T', 'app', 'sh', '-eu', '-c', '''
            test ! -e .env
            test ! -e public/storage
            test -s public/build/manifest.json
            test ! -d node_modules
            test ! -d vendor/phpunit
            test ! -x /usr/local/bin/composer
            test "$(id -u)" = 33
            test -z "$(find storage/app/document-vault -type f -print -quit)"
            printf "disposable-private-probe" > storage/app/document-vault/smoke-probe
            curl --fail --silent --output /dev/null http://127.0.0.1:8080/up
            code=$(curl --silent --output /dev/null --write-out "%{http_code}" http://127.0.0.1:8080/storage/smoke-probe)
            test "$code" = 403
        ''', log=True)
        self.compose('up', '-d', '--no-deps', '--force-recreate', '--wait', '--wait-timeout', '120', 'app', log=True)
        self.compose('exec', '-T', 'app', 'sh', '-eu', '-c',
                     'test "$(cat storage/app/document-vault/smoke-probe)" = disposable-private-probe; rm storage/app/document-vault/smoke-probe', log=True)
        self.compose('stop', log=True)
        self.summary['result'] = 'passed'

    def finish(self, failed):
        if self.changed:
            try:
                self.compose('logs', '--no-color', '--tail', '100', log=True)
            except Exception:
                self.record('Could not collect service logs; cleanup will still be attempted.')
                failed = True
            try:
                self.cleanup()
            except Exception as error:
                self.summary['cleanup'] = 'FAILED: '+str(error)
                failed = True
        if not self.changed or self.summary['cleanup'] == 'verified-empty':
            for name in ('password', 'root-password', 'app.env', 'compose.env'):
                (self.directory/name).unlink(missing_ok=True)
        if failed:
            self.summary['result'] = 'failed'
        self.write('summary.json', json.dumps(self.summary, indent=2)+'\n')
        print('Smoke evidence: '+str(self.directory/'summary.json'))
        print('Result: '+self.summary['result']+'; cleanup: '+self.summary['cleanup'])
        return int(failed)


def interrupted(*_):
    raise InterruptedError('Terminated')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('image', help='Existing local sha256 image ID; never pulled')
    parser.add_argument('--plan', action='store_true', help='Render/validate only; no daemon access or Docker mutations')
    args = parser.parse_args()
    try:
        validate_caller(os.environ, args.image, args.plan)
        run = SmokeRun(args.image, os.environ)
    except RuntimeError as error:
        print('STOP: '+str(error), file=sys.stderr)
        return 1
    print('Disposable project: '+run.project)
    print('Private evidence directory: '+str(run.directory), flush=True)
    run.write('summary.json', json.dumps(run.summary, indent=2)+'\n')
    failed = False
    try:
        signal.signal(signal.SIGTERM, interrupted)
        run.prepare()
        if args.plan:
            run.summary['result'] = 'plan-only'
        else:
            run.execute()
    except (Exception, KeyboardInterrupt) as error:
        run.record(str(error))
        print('STOP: '+str(error), file=sys.stderr)
        failed = True
    finally:
        # Complete cleanup even after one interruption; SIGKILL cannot be handled.
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        signal.signal(signal.SIGINT, signal.SIG_IGN)
    return run.finish(failed)


if __name__ == '__main__':
    sys.exit(main())
