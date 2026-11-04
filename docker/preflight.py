#!/usr/bin/env python3
"""Validate a release plan. Optional local probes are read-only; this tool cannot deploy."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--target', choices=['uat', 'production'], required=True)
parser.add_argument('--project', required=True)
parser.add_argument('--compose-env', type=Path, required=True)
parser.add_argument('--manifest', type=Path, required=True)
parser.add_argument('--probe-local', action='store_true', help='Read local daemon/image/current stack; never pull, start, migrate or deploy')
parser.add_argument('--uat-profile', action='store_true', help='Validate shared-host UAT overlay; only dcms-uat-xppl is accepted')
parser.add_argument('--localhost-verification', action='store_true', help='UAT profile only: permit HTTP loopback technical checks, never user access')
args = parser.parse_args()

def require(condition, message):
    if not condition:
        sys.exit('STOP: '+message)

def protected_file(path):
    require(path.is_absolute() and path.is_file(), 'Required absolute configuration/secret file missing.')
    require(not stat.S_IMODE(path.stat().st_mode) & 0o077, 'Configuration/secret files must be owner-only (0600).')

require(re.fullmatch(r'dcms-'+args.target+r'-[a-z0-9-]+', args.project), 'Use a distinct dcms-TARGET-NAME project.')
require(not args.uat_profile or (args.target == 'uat' and args.project == 'dcms-uat-xppl'), 'UAT profile requires target uat and project dcms-uat-xppl.')
require(not args.localhost_verification or args.uat_profile, 'Localhost verification requires the UAT profile.')
protected_file(args.compose_env)
manifest = json.loads(args.manifest.read_text())
image = manifest.get('image', '')
require(re.fullmatch(r'ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}', image), 'Immutable GHCR digest required.')
require(re.fullmatch(r'[a-f0-9]{40}', manifest.get('source_commit', '')), 'Full source commit required.')
require(manifest.get('migration_inventory'), 'Migration inventory required.')
require(shutil.which('docker'), 'Docker CLI required.')
# Ignore ambient Compose interpolation and remote Docker context; use only explicit inputs.
environment = {key: value for key, value in os.environ.items() if not key.startswith(('DCMS_', 'COMPOSE_', 'DOCKER_'))}
environment['DOCKER_HOST'] = 'unix:///var/run/docker.sock'
repository = Path(__file__).resolve().parent.parent
compose = ['docker', '--host', 'unix:///var/run/docker.sock', 'compose', '--project-name', args.project,
           '--env-file', str(args.compose_env), '-f', str(repository/'compose.release.yaml')]
if args.uat_profile:
    compose += ['-f', str(repository/'compose.uat.yaml')]

def run(command):
    result = subprocess.run(command, env=environment, text=True, capture_output=True)
    require(result.returncode == 0, 'A check failed; inspect privately without printing secrets: '+command[0])
    return result.stdout

config = json.loads(run(compose+['config', '--format', 'json']))
app = config['services']['app']
app_env = app['environment']
require(app['image'] == image, 'Compose image does not match release manifest.')
require(app_env['APP_ENV'] == args.target and str(app_env['APP_DEBUG']).lower() == 'false', 'Environment/debug mismatch.')
require(app_env.get('APP_KEY', '').startswith('base64:'), 'Inject an environment-specific APP_KEY.')
require(app_env.get('DB_HOST') == 'db' and app_env.get('DB_CONNECTION') == 'mysql', 'Expected isolated Compose database.')
require(app_env.get('DB_PASSWORD') and app_env.get('DB_USERNAME') != 'root', 'Non-root database credentials required.')
require(app_env.get('SESSION_DRIVER') == 'database' and app_env.get('CACHE_STORE') == 'database'
        and app_env.get('QUEUE_CONNECTION') == 'sync', 'Reviewed session/cache/queue configuration required.')
require(app_env.get('LOG_CHANNEL') == 'stderr', 'Use stderr logging with read-only runtime.')
if args.localhost_verification:
    require(app_env.get('APP_URL') == 'http://localhost:18084', 'Technical verification requires the documented loopback URL.')
    require(str(app_env.get('SESSION_SECURE_COOKIE')).lower() == 'false', 'Technical HTTP sessions require temporary secure-cookie=false.')
else:
    require(str(app_env.get('SESSION_SECURE_COOKIE')).lower() == 'true' and app_env.get('APP_URL', '').startswith('https://'), 'Approved HTTPS ingress required.')
require('*' not in app_env.get('TRUSTED_PROXIES', ''), 'Explicit ingress addresses required; wildcard proxies refused.')
if args.uat_profile:
    require(re.fullmatch(r'ghcr\.io/pintybhatt/psg-document-control@sha256:[a-f0-9]{64}', image), 'Only the approved DCMS repository image is accepted.')
    require(app_env.get('DB_DATABASE') == 'dcms_uat' and app_env.get('DB_USERNAME') == 'dcms_uat', 'UAT database identity required.')
    require(not app_env.get('DB_URL') and not app_env.get('DB_SOCKET') and str(app_env.get('DB_PORT')) == '3306', 'Database overrides refused.')
    require(app_env.get('SESSION_COOKIE') == 'dcms_uat_session' and app_env.get('CACHE_PREFIX') == 'dcms_uat_', 'UAT session/cache isolation required.')
    require(str(app_env.get('SESSION_ENCRYPT')).lower() == 'true' and app_env.get('FILESYSTEM_DISK') == 'local', 'Encrypted sessions and private local storage required.')
    if not args.localhost_verification:
        require(re.fullmatch(r'https://[a-z0-9]+(?:[.-][a-z0-9]+)*:8444', app_env.get('APP_URL', ''))
                and app_env.get('TRUSTED_PROXIES', '').strip(), 'UAT requires the approved hostname on HTTPS 8444 and an explicit proxy address.')
    require(set(config['services']) == {'app', 'db'}, 'Only UAT app and database services are allowed.')
    ports = app.get('ports', [])
    require(len(ports) == 1 and ports[0].get('host_ip') == '127.0.0.1' and ports[0]['target'] == 8080
            and str(ports[0].get('published')) == '18084', 'Only documented loopback application port 18084 is allowed.')
    require(not config['services']['db'].get('ports'), 'Database must not publish ports.')
    require(set(app.get('networks', {})) == {'ingress', 'backend'}
            and set(config['services']['db'].get('networks', {})) == {'backend'}
            and config['networks']['backend'].get('internal'), 'Reviewed ingress/internal database network boundary required.')
    expected_mounts = {
        'app': {'/var/www/html/storage/app/document-vault': 'documents', '/var/www/html/storage/app/private': 'private',
                '/var/www/html/storage/framework': 'framework', '/etc/apache2/conf-enabled/zz-dcms-uat.conf': None},
        'db': {'/var/lib/mysql': 'database'},
    }
    for name, expected in expected_mounts.items():
        mounts = config['services'][name].get('volumes', [])
        require(len(mounts) == len(expected) and {mount['target'] for mount in mounts} == set(expected), 'Unexpected UAT storage mount.')
        for mount in mounts:
            source = expected[mount['target']]
            if source is None:
                require(mount['type'] == 'bind' and mount.get('read_only') and mount['source'] == str(repository/'docker/apache-uat.conf'), 'Only the reviewed read-only Apache file may be bind mounted.')
            else:
                require(mount['type'] == 'volume' and mount['source'] == source, 'Expected project-owned persistent volume.')
    require(app.get('read_only') and 'ALL' in app.get('cap_drop', [])
            and 'no-new-privileges:true' in app.get('security_opt', []), 'Application runtime protections required.')
    approved_resources = {
        ((640*1024*1024, 0.5), (768*1024*1024, 0.5)),
        ((2048*1024*1024, 2.0), (1536*1024*1024, 0.5)),
    }
    resources = tuple((int(config['services'][name].get('mem_limit', 0)), float(config['services'][name].get('cpus', 0)))
                      for name in ('app', 'db'))
    require(resources in approved_resources, 'Reviewed UAT resource limits required.')
    for name in ('app', 'db'):
        service = config['services'][name]
        require(int(service.get('memswap_limit', 0)) == int(service['mem_limit']), 'Reviewed UAT memory/swap limits required.')
        require(not service.get('privileged') and not service.get('network_mode') and not service.get('container_name'), 'Host/privileged/shared container settings refused.')
    for kind in ('volumes', 'networks'):
        for resource in config[kind].values():
            require(not resource.get('external') and resource.get('name', '').startswith(args.project+'_'), 'Only project-owned storage/networks allowed.')
# Protect all referenced files without displaying their content or rendered environment.
for secret in config['secrets'].values():
    protected_file(Path(secret['file']))
# Compose config resolves env_file content; recover its path from raw interpolation, never source shell code.
variables = dict(line.split('=', 1) for line in args.compose_env.read_text().splitlines() if line and not line.startswith('#') and '=' in line)
if args.uat_profile:
    require(variables.get('DCMS_ENVIRONMENT') == 'uat', 'UAT environment selection required.')
protected_file(Path(variables.get('DCMS_APP_ENV_FILE', '')))
require(app_env['DB_PASSWORD'] == Path(variables['DCMS_DB_PASSWORD_FILE']).read_text().rstrip('\n'), 'Application and database passwords differ.')
require(app_env['DB_DATABASE'] == variables['DCMS_DB_DATABASE'] and app_env['DB_USERNAME'] == variables['DCMS_DB_USERNAME'], 'Database identity mismatch.')
print('Plan validated for '+args.project+' at '+manifest['source_commit']+'. No deployment performed.')
print('Manual gates remain: authorization, capacity, mount ownership, paired backup, migration review, previous digest and restore evidence.')
if not args.probe_local:
    print('Daemon probes not requested. Use --probe-local only on an approved host.')
    sys.exit(0)
run(['docker', '--host', 'unix:///var/run/docker.sock', 'info', '--format', '{{.ServerVersion}}'])
info = json.loads(run(['docker', '--host', 'unix:///var/run/docker.sock', 'image', 'inspect', image]))[0]
require(info['Config']['Labels'].get('org.opencontainers.image.revision') == manifest['source_commit'], 'Image source label mismatch.')
free_memory_kib = next(int(line.split()[1]) for line in Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:'))
print('Available host memory MiB: '+str(free_memory_kib//1024))
print('Available bytes on Docker root filesystem: '+str(shutil.disk_usage(run(['docker', '--host', 'unix:///var/run/docker.sock', 'info', '--format', '{{.DockerRootDir}}']).strip()).free))
services = run(compose+['ps', '--format', 'json'])
require(services.strip(), 'No existing stack: use the separately approved initial-bootstrap procedure.')
# Compose v2/v5 emits one JSON object per service.
rows = json.loads(services) if services.lstrip().startswith('[') else [json.loads(line) for line in services.splitlines() if line]
require(any(row.get('Service') == 'db' and row.get('Health') == 'healthy' for row in rows), 'Database must be healthy.')
run(compose+['exec', '-T', 'app', 'sh', '-eu', '-c', 'test -w storage/app/document-vault; test -w storage/framework; test ! -e public/storage'])
print(run(compose+['exec', '-T', 'app', 'php', 'artisan', 'migrate:status', '--no-interaction']))
print('Read-only probes completed against CURRENT image. Compare candidate inventory manually; no compatibility or authorization inferred.')
