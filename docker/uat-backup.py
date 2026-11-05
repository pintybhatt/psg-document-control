#!/usr/bin/env python3
"""UAT-only paired backup transport; install as the existing verify-backup hook."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import uuid

FILES = ('database.sql', 'documents.tar', 'configuration.tar', 'SHA256SUMS')
CONFIG = {'app.env', 'compose.env', 'db-password', 'db-root-password', 'migration.env',
          'backup.cnf', 'current.json', 'automation.json'}
STORE = Path('/srv/dcms-uat-backups/managed-v1')
RESTORE = '/usr/local/libexec/dcms-uat-restore-check'
HASH_CHUNK_SIZE = 1024 * 1024


def sha256_stream(source):
    digest = hashlib.sha256()
    while chunk := source.read(HASH_CHUNK_SIZE):
        digest.update(chunk)
    return digest.hexdigest()


def require(condition):
    if not condition:
        raise ValueError('Backup verification refused.')


def validate(directory):
    require(set(path.name for path in directory.iterdir()) == set(FILES))
    lines = (directory/'SHA256SUMS').read_text().splitlines()
    require(len(lines) == 3)
    expected = {}
    for line in lines:
        match = re.fullmatch(r'([a-f0-9]{64})  (database.sql|documents.tar|configuration.tar)', line)
        require(match is not None and match[2] not in expected)
        expected[match[2]] = match[1]
    for name, checksum in expected.items():
        path = directory/name
        require(path.is_file() and not path.is_symlink() and path.stat().st_size > 0)
        with path.open('rb') as source:
            require(sha256_stream(source) == checksum)
    with (directory/'database.sql').open('rb') as source:
        source.seek(max(0, (directory/'database.sql').stat().st_size-4096))
        require(b'-- Dump completed on ' in source.read())
    for name in ('documents.tar', 'configuration.tar'):
        with tarfile.open(directory/name) as archive:
            members = archive.getmembers()
            require(all(not Path(item.name).is_absolute() and '..' not in Path(item.name).parts
                        and (item.isfile() or item.isdir()) for item in members))
            names = {item.name.rstrip('/') for item in members}
            if name == 'configuration.tar':
                require(names == CONFIG and all(item.isfile() and item.size > 0 for item in members))
            else:
                require({'documents', 'private'} <= names)
            # Read every archived byte; do not extract confidential inner archives here.
            for item in members:
                if item.isfile():
                    with archive.extractfile(item) as source:
                        while source.read(1024*1024):
                            pass
    return hashlib.sha256((directory/'SHA256SUMS').read_bytes()).hexdigest()


def receive(stream):
    os.umask(0o077)
    require(STORE.is_dir() and not STORE.is_symlink())
    with (STORE/'transfer.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        pending = STORE/('pending-'+uuid.uuid4().hex)
        pending.mkdir(mode=0o700)
        # Preserve partial/failed transfers for administrator inspection, never prune them.
        with tarfile.open(fileobj=stream, mode='r|') as archive:
            seen = set()
            for item in archive:
                require(item.name in FILES and item.name not in seen and item.isfile())
                seen.add(item.name)
                with archive.extractfile(item) as source, (pending/item.name).open('xb') as target:
                    shutil.copyfileobj(source, target)
                    target.flush()
                    os.fsync(target.fileno())
        digest = validate(pending)
        # Fixed, administrator-installed hook must perform a real isolated restore check.
        subprocess.run([RESTORE, str(pending)], check=True, timeout=480, stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, env={'PATH': '/usr/bin:/bin'})
        completed = STORE/('success-'+uuid.uuid4().hex)
        (pending/'success.json').write_text(json.dumps({'sha256sums': digest}))
        pending.rename(completed)
        retain()
        return digest


def retain():
    successful = []
    for path in STORE.iterdir():
        if re.fullmatch(r'success-[a-f0-9]{32}', path.name) and path.is_dir() and not path.is_symlink():
            receipt = path/'success.json'
            if receipt.is_file() and not receipt.is_symlink():
                successful.append(path)
    for path in sorted(successful, key=lambda item: item.stat().st_mtime_ns, reverse=True)[3:]:
        shutil.rmtree(path)


def send(directory):
    directory = Path(directory)
    require(directory.parent == Path('/srv/dcms-uat/backups')
            and re.fullmatch(r'gha-[0-9]+-[0-9]+', directory.name) and not directory.is_symlink())
    digest = validate(directory)
    with tempfile.TemporaryFile() as bundle:
        with tarfile.open(fileobj=bundle, mode='w') as archive:
            for name in FILES:
                archive.add(directory/name, arcname=name, recursive=False)
        bundle.seek(0)
        command = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-i', '/srv/dcms-uat/config/backup-ssh-key',
                   '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
                   '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=/srv/dcms-uat/config/backup-known-hosts',
                   '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'PasswordAuthentication=no',
                   '-o', 'KbdInteractiveAuthentication=no', '-o', 'ClearAllForwardings=yes',
                   '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3',
                   'dcms-uat-backup@192.168.0.227', 'receive-dcms-uat-backup']
        result = subprocess.run(command, stdin=bundle, capture_output=True, timeout=570,
                                env={'PATH': '/usr/bin:/bin'})
        require(result.returncode == 0 and result.stdout == ('OK '+digest+'\n').encode())


def main():
    try:
        if len(sys.argv) == 1:
            require(os.environ.get('SSH_ORIGINAL_COMMAND') == 'receive-dcms-uat-backup')
            print('OK '+receive(sys.stdin.buffer))
        else:
            require(len(sys.argv) == 2 and os.geteuid() == 0)
            send(sys.argv[1])
    except Exception:
        sys.exit('STOP: UAT paired backup transfer/verification failed; inspect protected backup storage.')


if __name__ == '__main__':
    main()
