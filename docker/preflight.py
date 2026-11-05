#!/usr/bin/env python3
"""Validate a release plan. Optional local probes are read-only; this tool cannot deploy."""
import argparse
import base64
import ipaddress
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
parser.add_argument('--prd-profile', action='store_true', help='Validate fixed Production overlay; only dcms-prd-xppl is accepted')
parser.add_argument('--localhost-verification', action='store_true', help='UAT profile only: permit HTTP loopback technical checks, never user access')
args = parser.parse_args()

def require(condition, message):
    if not condition:
        sys.exit('STOP: '+message)

def protected_file(path):
    require(path.is_absolute() and path.is_file(), 'Required absolute configuration/secret file missing.')
    require(not stat.S_IMODE(path.stat().st_mode) & 0o077, 'Configuration/secret files must be owner-only (0600).')

if args.prd_profile:
    require(args.project == 'dcms-prd-xppl', 'Production requires project dcms-prd-xppl.')
else:
    require(re.fullmatch(r'dcms-'+args.target+r'-[a-z0-9-]+', args.project), 'Use a distinct dcms-TARGET-NAME project.')
require(not args.uat_profile or (args.target == 'uat' and args.project == 'dcms-uat-xppl'), 'UAT profile requires target uat and project dcms-uat-xppl.')
require(not args.prd_profile or (args.target == 'production' and not args.uat_profile), 'Production profile requires target production and no UAT overlay.')
require(args.target != 'production' or args.prd_profile, 'Production requires the explicit --prd-profile.')
require(not args.localhost_verification or args.uat_profile, 'Localhost verification requires the UAT profile.')
if args.prd_profile:
    production_env = args.compose_env == Path('/srv/dcms-prd/config/compose.env')
    candidate_env = re.fullmatch(r'/srv/dcms-prd/history/gha-[1-9][0-9]*-[1-9][0-9]*/compose.env', str(args.compose_env))
    require((production_env or candidate_env) and args.compose_env.resolve() == args.compose_env,
            'Production Compose configuration must use its fixed isolated path, without symlinks.')
protected_file(args.compose_env)
# Never source shell code. Validate Production paths before Compose can read env_file/secrets.
variables = dict(line.split('=', 1) for line in args.compose_env.read_text().splitlines() if line and not line.startswith('#') and '=' in line)
if args.prd_profile:
    required_variables = {
        'DCMS_ENVIRONMENT': 'production', 'DCMS_BIND_ADDRESS': '127.0.0.1', 'DCMS_HTTP_PORT': '18085',
        'DCMS_DB_DATABASE': 'dcms_prd', 'DCMS_DB_USERNAME': 'dcms_prd',
        'DCMS_APP_ENV_FILE': '/srv/dcms-prd/config/app.env',
        'DCMS_DB_PASSWORD_FILE': '/srv/dcms-prd/config/db-password',
        'DCMS_DB_ROOT_PASSWORD_FILE': '/srv/dcms-prd/config/db-root-password',
    }
    require(all(variables.get(key) == value for key, value in required_variables.items()), 'Fixed Production environment, database, port and isolated paths required.')
    for key in ('DCMS_APP_ENV_FILE', 'DCMS_DB_PASSWORD_FILE', 'DCMS_DB_ROOT_PASSWORD_FILE'):
        path = Path(variables[key])
        require(path.resolve() == path, 'Production configuration/secret symlinks refused.')
        protected_file(path)
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
if args.prd_profile:
    compose += ['-f', str(repository/'compose.prd.yaml')]

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
if args.prd_profile:
    require(config.get('name') == args.project and set(config['services']) == {'app', 'db'}, 'Only the fixed Production app/database project is allowed.')
    require(re.fullmatch(r'ghcr\.io/pintybhatt/psg-document-control@sha256:[a-f0-9]{64}', image), 'Only the approved DCMS repository image is accepted.')
    require(app_env.get('APP_URL') == 'https://dcms.phonesackgroup.com:9444', 'Fixed Production HTTPS URL required.')
    require(app_env.get('DB_DATABASE') == 'dcms_prd' and app_env.get('DB_USERNAME') == 'dcms_prd', 'Production database identity required.')
    require(not app_env.get('DB_URL') and not app_env.get('DB_SOCKET') and str(app_env.get('DB_PORT')) == '3306', 'Production database overrides refused.')
    require(app_env.get('SESSION_COOKIE') == 'dcms_prd_session' and app_env.get('CACHE_PREFIX') == 'dcms_prd_', 'Production session/cache isolation required.')
    require(str(app_env.get('SESSION_ENCRYPT')).lower() == 'true' and str(app_env.get('SESSION_HTTP_ONLY')).lower() == 'true'
            and app_env.get('SESSION_SAME_SITE') == 'lax' and app_env.get('SESSION_DOMAIN') in (None, '', 'null')
            and app_env.get('SESSION_PATH') == '/', 'Encrypted, host-only, HTTP-only Production sessions required.')
    require(app_env.get('FILESYSTEM_DISK') == 'local' and str(app_env.get('XPPL_UAT_IMPORT_ENABLED')).lower() == 'false', 'Private storage and disabled UAT import required.')
    require(not any(app_env.get(key) for key in ('SESSION_CONNECTION', 'SESSION_STORE', 'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'APP_PREVIOUS_KEYS')), 'Shared session/cache connections and previous environment keys refused.')
    try:
        valid_key = len(base64.b64decode(app_env['APP_KEY'][7:], validate=True)) == 32
        proxies = [ipaddress.ip_address(value.strip()) for value in app_env.get('TRUSTED_PROXIES', '').split(',')]
        valid_proxies = all(not address.is_unspecified and not address.is_multicast for address in proxies)
    except ValueError:
        valid_key, valid_proxies = False, False
    require(valid_key and valid_proxies, 'Valid Production APP_KEY and explicit ingress IP addresses required; broad proxy networks refused.')
    db = config['services']['db']
    require(re.fullmatch(r'mysql:8\.4\.\d+@sha256:[a-f0-9]{64}', db.get('image', '')), 'Pinned MySQL 8.4 required.')
    require(db.get('environment') == {'MYSQL_DATABASE': 'dcms_prd', 'MYSQL_USER': 'dcms_prd',
            'MYSQL_PASSWORD_FILE': '/run/secrets/db_password', 'MYSQL_ROOT_PASSWORD_FILE': '/run/secrets/db_root_password'}, 'Isolated MySQL credentials required; inline overrides refused.')
    require(set(config.get('secrets', {})) == {'db_password', 'db_root_password'}, 'Only Production database secrets allowed.')
    for name, variable in (('db_password', 'DCMS_DB_PASSWORD_FILE'), ('db_root_password', 'DCMS_DB_ROOT_PASSWORD_FILE')):
        secret = config['secrets'][name]
        require(secret.get('file') == variables[variable] and not secret.get('external')
                and secret.get('name', args.project+'_'+name) == args.project+'_'+name, 'Only fixed Production secret files allowed.')
    require({secret['source'] for secret in db.get('secrets', [])} == {'db_password', 'db_root_password'}
            and not app.get('secrets'), 'Database secrets must remain on the database service.')
    password = Path(variables['DCMS_DB_PASSWORD_FILE']).read_text().rstrip('\n')
    root_password = Path(variables['DCMS_DB_ROOT_PASSWORD_FILE']).read_text().rstrip('\n')
    require(password and root_password and password != root_password
            and not any(marker in value.lower() for value in (password, root_password) for marker in ('replace', 'changeme', 'placeholder')),
            'Distinct non-placeholder Production database passwords required.')
    ports = app.get('ports', [])
    require(len(ports) == 1 and ports[0].get('host_ip') == '127.0.0.1' and ports[0].get('target') == 8080
            and str(ports[0].get('published')) == '18085' and ports[0].get('protocol') == 'tcp', 'Only Production loopback TCP port 18085 is allowed.')
    require(not db.get('ports'), 'Production database must not publish ports.')
    require(set(app.get('networks', {})) == {'ingress', 'backend'} and set(db.get('networks', {})) == {'backend'}
            and config['networks']['backend'].get('internal') and not config['networks']['ingress'].get('internal'), 'Production ingress/internal database boundary required.')
    for kind, names in (('volumes', {'database', 'documents', 'private', 'framework'}), ('networks', {'ingress', 'backend'})):
        require(set(config.get(kind, {})) == names, 'Unexpected Production storage/network resource.')
        for name, resource in config[kind].items():
            require(resource.get('name') == args.project+'_'+name and not resource.get('external') and not resource.get('driver_opts')
                    and resource.get('driver', 'local' if kind == 'volumes' else 'bridge') == ('local' if kind == 'volumes' else 'bridge'),
                    'Only exact project-owned local volumes and bridge networks allowed.')
    expected_mounts = {
        'app': {'/var/www/html/storage/app/document-vault': 'documents', '/var/www/html/storage/app/private': 'private',
                '/var/www/html/storage/framework': 'framework', '/etc/apache2/conf-enabled/zz-dcms-prd.conf': None},
        'db': {'/var/lib/mysql': 'database'},
    }
    for name, expected in expected_mounts.items():
        mounts = config['services'][name].get('volumes', [])
        require(len(mounts) == len(expected) and {mount['target'] for mount in mounts} == set(expected), 'Unexpected Production storage mount.')
        for mount in mounts:
            source = expected[mount['target']]
            if source is None:
                require(mount['type'] == 'bind' and mount.get('read_only') and mount['source'] == str(repository/'docker/apache-prd.conf')
                        and not mount.get('bind', {}).get('create_host_path', False), 'Only the reviewed read-only Production Apache file may be bind mounted.')
            else:
                require(mount['type'] == 'volume' and mount['source'] == source and not mount.get('volume', {}).get('subpath'), 'Expected isolated Production persistent volume.')
    require(app.get('read_only') and app.get('cap_drop') == ['ALL'] and not app.get('cap_add')
            and 'no-new-privileges:true' in app.get('security_opt', []), 'Production runtime protections required.')
    require(set(app.get('tmpfs', [])) == {'/tmp:uid=33,gid=33,mode=1770,size=64m', '/var/www/html/bootstrap/cache:uid=33,gid=33,mode=0750,size=16m'}, 'Bounded Production writable tmpfs required.')
    require(app_env.get('PHP_MEMORY_LIMIT') == '256M', 'Reviewed Production PHP memory limit required.')
    for name, memory, cpus, pids in (('app', 2048*1024*1024, 1.0, 128), ('db', 1536*1024*1024, 0.5, 256)):
        service = config['services'][name]
        require(int(service.get('mem_limit', 0)) == memory and int(service.get('memswap_limit', 0)) == memory
                and float(service.get('cpus', 0)) == cpus and service.get('pids_limit') == pids, 'Fixed Production resource limits required.')
        require(not any(service.get(key) for key in ('privileged', 'network_mode', 'container_name', 'pid', 'ipc', 'devices', 'volumes_from', 'build', 'user', 'entrypoint', 'cap_add')),
                'Host/privileged/shared container or image runtime overrides refused.')
    require(not app.get('command') and db.get('healthcheck') and not db['healthcheck'].get('disable'), 'Image application command and database healthcheck required.')
# Protect all referenced files without displaying their content or rendered environment.
for secret in config['secrets'].values():
    protected_file(Path(secret['file']))
# Compose resolves env_file content; protect the original application file too.
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
