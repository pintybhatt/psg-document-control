#!/usr/bin/env python3
"""Production paired backup verification and restricted off-host transport. No retention deletion."""
import fcntl
import hashlib
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import uuid


def load(filename):
    spec = importlib.util.spec_from_file_location(filename.replace('-', '_'), Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


shared = load('deploy-uat.py')
require, protected = shared.require, shared.protected
ROOT = Path('/srv/dcms-prd')
REMOTE_ROOT = Path('/srv/dcms-prd-backups')
PROJECT = 'dcms-prd-xppl'
PAYLOAD = ('database.sql', 'documents.tar', 'configuration.tar', 'identity.json')
FILES = (*PAYLOAD, 'SHA256SUMS')
CONFIG = ('app.env', 'compose.env', 'db-password', 'db-root-password', 'migration.env',
          'backup.cnf', 'current.json', 'backup-target.json', 'backup-known-hosts', 'backup-ssh-key', 'registry.json',
          'compose.release.yaml', 'compose.prd.yaml', 'apache-prd.conf')
DEPLOY_CONFIG = tuple(name for name in CONFIG if name not in ('backup-target.json', 'backup-known-hosts', 'backup-ssh-key')) + (
    'qnap-readiness-key', 'qnap-readiness-known-hosts')
RELEASE_CONFIG = {'compose.release.yaml': 'compose.release.yaml', 'compose.prd.yaml': 'compose.prd.yaml',
                  'apache-prd.conf': 'docker/apache-prd.conf'}
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'LC_ALL': 'C'}


def configuration_path(name):
    require(name in set(CONFIG) | set(DEPLOY_CONFIG), 'Unknown recovery configuration file.')
    if name in RELEASE_CONFIG:
        return ROOT/'release'/RELEASE_CONFIG[name]
    return ROOT/'registry/config.json' if name == 'registry.json' else ROOT/'config'/name


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def sha256_stream(source):
    digest = hashlib.sha256()
    while chunk := source.read(1024*1024):
        digest.update(chunk)
    return digest.hexdigest()


def private(path):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and not info.st_mode & 0o077 and info.st_nlink == 1,
            'Private regular backup file required.')


def identity(data):
    require(set(data) == {'backup_id', 'project', 'database', 'image', 'source_commit'}, 'Invalid backup identity.')
    require(re.fullmatch(r'gha-[1-9][0-9]*-[1-9][0-9]*', data['backup_id'])
            and data['project'] == PROJECT and data['database'] == 'dcms_prd'
            and re.fullmatch(shared.evidence.IMAGE, data['image'])
            and re.fullmatch(r'[a-f0-9]{40}', data['source_commit']), 'Production backup identity required.')
    return data


def validate(directory, configuration=None):
    require(directory.is_dir() and not directory.is_symlink(), 'Backup directory required.')
    require({path.name for path in directory.iterdir()} == set(FILES), 'Incomplete or unexpected backup files.')
    for name in FILES:
        private(directory/name)
        require((directory/name).stat().st_size > 0, 'Empty backup file.')
    sums = {}
    for line in (directory/'SHA256SUMS').read_text().splitlines():
        match = re.fullmatch(r'([a-f0-9]{64})  ([a-z.]+)', line)
        require(match and match[2] in PAYLOAD and match[2] not in sums, 'Invalid backup checksums.')
        sums[match[2]] = match[1]
    require(set(sums) == set(PAYLOAD), 'Missing backup checksums.')
    for name, expected in sums.items():
        with (directory/name).open('rb') as source:
            require(sha256_stream(source) == expected, 'Backup checksum mismatch.')
    data = identity(shared.auth.parse_json((directory/'identity.json').read_bytes()))
    with (directory/'database.sql').open('rb') as source:
        source.seek(max(0, (directory/'database.sql').stat().st_size-4096))
        require(b'-- Dump completed on ' in source.read(), 'Incomplete database dump.')
    for name in ('documents.tar', 'configuration.tar'):
        with tarfile.open(directory/name) as archive:
            members = archive.getmembers()
            names = [item.name.rstrip('/') for item in members]
            require(len(set(names)) == len(names), 'Duplicate archive members.')
            require(all(not Path(item.name).is_absolute() and '..' not in Path(item.name).parts
                        and (item.isfile() or item.isdir()) for item in members), 'Unsafe archive member.')
            if name == 'configuration.tar':
                allowed = (configuration,) if configuration is not None else (CONFIG, DEPLOY_CONFIG)
                require(any(set(names) == set(expected) for expected in allowed)
                        and all(item.isfile() and item.size > 0 for item in members), 'Required recovery configuration missing.')
                manifest = shared.auth.parse_json(archive.extractfile('current.json').read())
                require(manifest['image'] == data['image'] and manifest['source_commit'] == data['source_commit'], 'Backup release identity mismatch.')
            else:
                require({'documents', 'private'} <= set(names)
                        and all(Path(item.name).parts[0] in ('documents', 'private') for item in members), 'Unexpected document archive root.')
            for item in members:
                if item.isfile():
                    with archive.extractfile(item) as source:
                        while source.read(1024*1024):
                            pass
    return hashlib.sha256((directory/'SHA256SUMS').read_bytes()).hexdigest(), data


def target():
    data = shared.auth.parse_json(protected(ROOT/'config/backup-target.json', True).read_bytes())
    require(set(data) == {'host', 'port'} and isinstance(data['host'], str)
            and re.fullmatch(r'[a-z0-9]+(?:[.-][a-z0-9]+)*', data['host'])
            and data['host'] not in ('localhost', '127.0.0.1', '0.0.0.0')
            and not data['host'].endswith('.invalid') and type(data['port']) is int and 1 <= data['port'] <= 65535,
            'Configured Production off-host backup destination required.')
    require(data['host'] != 'dcms.phonesackgroup.com', 'Backup destination must be off the Production host.')
    if re.fullmatch(r'[0-9.]+', data['host']):
        address = ipaddress.ip_address(data['host'])
        require(not (address.is_loopback or address.is_unspecified or address.is_multicast or address.is_link_local), 'Off-host unicast backup destination required.')
    for name in ('backup-ssh-key', 'backup-known-hosts'):
        protected(ROOT/'config'/name, True)
    return data


def send(directory):
    directory = Path(directory)
    require(directory.parent == ROOT/'backups' and not directory.is_symlink(), 'Only Production backup paths allowed.')
    protected(directory)
    digest, data = validate(directory)
    require(directory.name == data['backup_id'], 'Backup/deployment identity mismatch.')
    destination = target()
    with tempfile.TemporaryFile(dir=ROOT/'backups') as bundle:
        with tarfile.open(fileobj=bundle, mode='w') as archive:
            for name in FILES:
                archive.add(directory/name, arcname=name, recursive=False)
        bundle.seek(0)
        command = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-i', str(ROOT/'config/backup-ssh-key'),
                   '-p', str(destination['port']), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                   '-o', 'IdentityAgent=none', '-o', 'StrictHostKeyChecking=yes',
                   '-o', 'UserKnownHostsFile='+str(ROOT/'config/backup-known-hosts'),
                   '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'PasswordAuthentication=no',
                   '-o', 'KbdInteractiveAuthentication=no', '-o', 'ClearAllForwardings=yes',
                   '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3',
                   'dcms-prd-backup@'+destination['host'], 'receive-dcms-prd-backup']
        result = subprocess.run(command, stdin=bundle, capture_output=True, timeout=1200, env=ENV)
        require(result.returncode == 0 and result.stdout == ('OK '+data['backup_id']+' '+digest+'\n').encode(),
                'Off-host Production backup/restore acknowledgement failed.')
    return {'backup_id': data['backup_id'], 'sha256sums': digest, 'verified': True}


def receiver_paths():
    config = shared.auth.parse_json(protected(REMOTE_ROOT/'config/receiver.json').read_bytes())
    require(set(config) == {'store', 'restore_work'}, 'Invalid Production receiver configuration.')
    paths = []
    for key in ('store', 'restore_work'):
        path = Path(config[key])
        require(path.is_absolute() and REMOTE_ROOT in path.parents and path.resolve() == path
                and path.is_dir(), 'Isolated Production backup destination required.')
        protected(path.parent)
        info = path.stat()
        require(info.st_uid == os.geteuid() and not info.st_mode & 0o077, 'Private backup account storage required.')
        paths.append(path)
    require(paths[0] != paths[1] and paths[0] not in paths[1].parents and paths[1] not in paths[0].parents,
            'Separate backup and disposable restore directories required.')
    return paths


def receive(stream):
    os.umask(0o077)
    require(pwd.getpwuid(os.geteuid()).pw_name == 'dcms-prd-backup', 'Dedicated Production backup account required.')
    store, _ = receiver_paths()
    descriptor = os.open(store/'transfer.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        private(store/'transfer.lock')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        pending = store/('pending-'+uuid.uuid4().hex)
        pending.mkdir(mode=0o700)
        sync_directory(store)
        with tarfile.open(fileobj=stream, mode='r|') as archive:
            seen = set()
            for item in archive:
                require(item.name in FILES and item.name not in seen and item.isfile(), 'Unsafe backup transfer.')
                seen.add(item.name)
                with archive.extractfile(item) as source, (pending/item.name).open('xb') as output:
                    shutil.copyfileobj(source, output)
                    output.flush()
                    os.fsync(output.fileno())
        digest, data = validate(pending)
        subprocess.run(['/usr/bin/python3', '-I', '-B', str(protected(Path(__file__).with_name('prd-restore-check.py'))), str(pending)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=1100, env=ENV)
        receipt = {'backup_id': data['backup_id'], 'sha256sums': digest, 'verified': True}
        with (pending/'receipt.json').open('x') as output:
            output.write(json.dumps(receipt)+'\n')
            output.flush()
            os.fsync(output.fileno())
        sync_directory(pending)
        pending.rename(store/('success-'+data['backup_id']+'-'+uuid.uuid4().hex))
        sync_directory(store)
        return 'OK '+data['backup_id']+' '+digest


def main():
    try:
        require(len(sys.argv) == 1 and os.environ.get('SSH_ORIGINAL_COMMAND') == 'receive-dcms-prd-backup', 'Fixed receiver command required.')
        print(receive(sys.stdin.buffer))
    except (Exception, KeyboardInterrupt):
        sys.exit('STOP: Production backup transfer/restore verification failed; inspect private backup evidence.')


if __name__ == '__main__':
    main()
