#!/usr/bin/env python3
"""Python 3.6-compatible read-only QNAP verifier; no restore or transfer."""
import datetime
import hashlib
import json
import math
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import time

ROOT = Path('/srv/dcms-prd')
QNAP_ROOT = Path('/mnt/xppl_production_backup/XPPL DCMS')
QNAP_MOUNT = Path('/mnt/xppl_production_backup')
QNAP_SOURCE = '192.168.0.239:/XPPL-PRODUCTION-BACKUP'
PAYLOAD = {'database.sql', 'documents.tar', 'configuration.tar.enc'}
DAILY_PATTERN = r'daily-[0-9]{8}_[0-9]{6}'
LAOS = datetime.timezone(datetime.timedelta(hours=7))
MAX_AGE = 36 * 3600
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'LC_ALL': 'C'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def parse(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, 'Duplicate evidence field.')
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def protected(path, private=False):
    for parent in (path, *path.parents):
        info = parent.lstat()
        require(not stat.S_ISLNK(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                'Root custody required.')
    require(stat.S_ISREG(path.stat().st_mode) and path.stat().st_nlink == 1 and (not private or not path.stat().st_mode & 0o077),
            'Private configuration required.')
    return path


def regular(path):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, 'Regular backup file required.')
    return info


def stamp(info):
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def fresh(timestamp, now):
    require(type(timestamp) in (float, int) and math.isfinite(timestamp)
            and 0 <= now - timestamp <= MAX_AGE, 'Backup missing, stale or future dated.')


def backup_timestamp(name):
    require(re.fullmatch(DAILY_PATTERN, name), 'Daily backup directory required.')
    return datetime.datetime.strptime(name, 'daily-%Y%m%d_%H%M%S').replace(tzinfo=LAOS).timestamp()


def mounted():
    result = subprocess.run(['/usr/bin/findmnt', '--json', '--target', str(QNAP_ROOT),
                             '--output', 'SOURCE,FSTYPE,TARGET'], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=15, env=ENV)
    mounts = parse(result.stdout)['filesystems']
    require(len(mounts) == 1 and mounts[0]['source'] == QNAP_SOURCE
            and mounts[0]['fstype'] in ('nfs', 'nfs4')
            and Path(mounts[0]['target']) == QNAP_MOUNT,
            'Live QNAP network mount required; local fallback refused.')


def inspect(now):
    mounted()
    require(QNAP_ROOT.resolve(strict=True) == QNAP_ROOT, 'Symlink backup root refused.')
    directories = [path for path in QNAP_ROOT.iterdir() if re.fullmatch(DAILY_PATTERN, path.name)]
    require(directories, 'No off-host daily backup sets.')
    directory = max(directories, key=lambda path: path.name)
    require(directory.is_dir() and not directory.is_symlink(), 'Daily backup directory required; links refused.')
    created = backup_timestamp(directory.name)
    fresh(created, now)
    before = directory.stat()
    marker_path = directory/'.complete'
    marker = regular(marker_path)
    require(marker.st_size <= 128, 'Invalid completion marker.')
    marker_raw = marker_path.read_bytes()
    expected_marker = ('Completed: '+directory.name).encode('ascii')
    require(marker_raw in (expected_marker, expected_marker+b'\n'), 'Completion marker identity mismatch.')
    sums_path = directory/'SHA256SUMS'
    sums_info = regular(sums_path)
    require(0 < sums_info.st_size <= 1024*1024, 'Missing or oversized checksum manifest.')
    raw = sums_path.read_bytes()
    sums = {}
    for line in raw.decode('ascii').splitlines():
        match = re.fullmatch(r'([a-f0-9]{64}) [ *]([a-zA-Z0-9_.-]+)', line)
        require(match and match[2] not in ('.', '..') and match[2] not in sums, 'Unsafe/duplicate checksum entry.')
        sums[match[2]] = match[1]
    require(set(sums) == PAYLOAD, 'Exactly the three daily backup payload checksums required.')
    timestamps = []
    for name, expected in sums.items():
        path = directory/name
        info = regular(path)
        require(info.st_size > 0, 'Empty backup payload.')
        digest = hashlib.sha256()
        with path.open('rb') as stream:
            for chunk in iter(lambda: stream.read(1024*1024), b''):
                digest.update(chunk)
        require(digest.hexdigest() == expected and stamp(regular(path)) == stamp(info), 'QNAP checksum mismatch or changing transfer.')
        timestamps.append(info.st_mtime)
    timestamp = min(created, *timestamps)
    for value in (*timestamps, marker.st_mtime, sums_info.st_mtime):
        fresh(value, now)
    require(sums_path.read_bytes() == raw and stamp(regular(sums_path)) == stamp(sums_info)
            and marker_path.read_bytes() == marker_raw and stamp(regular(marker_path)) == stamp(marker) and stamp(directory.stat()) == stamp(before),
            'Backup set changed during verification.')
    mounted()
    return {'project': 'dcms-prd-xppl', 'database': 'dcms_prd', 'host': '192.168.4.25',
            'qnap': '192.168.0.239', 'path': str(directory), 'backup_time': timestamp,
            'sha256sums': hashlib.sha256(raw).hexdigest(), 'verified': True}


def verify():
    key = protected(ROOT/'config/qnap-readiness-key', True)
    hosts = protected(ROOT/'config/qnap-readiness-known-hosts', True)
    nonce = secrets.token_hex(32)
    command = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-i', str(key), '-p', '22']
    for option in ('BatchMode=yes', 'IdentitiesOnly=yes', 'IdentityAgent=none', 'StrictHostKeyChecking=yes',
                   'UserKnownHostsFile='+str(hosts), 'GlobalKnownHostsFile=/dev/null', 'UpdateHostKeys=no',
                   'ProxyCommand=none', 'ProxyJump=none', 'ConnectionAttempts=1', 'PasswordAuthentication=no',
                   'KbdInteractiveAuthentication=no', 'ClearAllForwardings=yes', 'RequestTTY=no',
                   'ConnectTimeout=15', 'ServerAliveInterval=15', 'ServerAliveCountMax=3'):
        command += ['-o', option]
    result = subprocess.run(command+['dcms-prd-qnap-read@192.168.4.25', 'verify-dcms-prd-qnap'],
                            input=(nonce+'\n').encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=900, env=ENV)
    require(result.returncode == 0 and len(result.stdout) <= 4096, 'Live QNAP verification failed.')
    data = parse(result.stdout)
    require(set(data) == {'project', 'database', 'host', 'qnap', 'path', 'backup_time', 'sha256sums', 'verified', 'nonce'}
            and data['nonce'] == nonce and data['verified'] is True
            and data['project'] == 'dcms-prd-xppl' and data['database'] == 'dcms_prd'
            and data['host'] == '192.168.4.25' and data['qnap'] == '192.168.0.239'
            and isinstance(data['path'], str) and Path(data['path']).parent == QNAP_ROOT
            and re.fullmatch(DAILY_PATTERN, Path(data['path']).name)
            and isinstance(data['sha256sums'], str) and re.fullmatch(r'[a-f0-9]{64}', data['sha256sums']),
            'Untrusted QNAP evidence.')
    fresh(data['backup_time'], time.time())
    require(data['backup_time'] <= backup_timestamp(Path(data['path']).name), 'Backup time differs from daily identity.')
    return data


def main():
    try:
        require(len(sys.argv) == 1 and os.environ.get('SSH_ORIGINAL_COMMAND') == 'verify-dcms-prd-qnap',
                'Fixed read-only verifier command required.')
        nonce = sys.stdin.buffer.read(66).decode('ascii').strip()
        require(re.fullmatch(r'[a-f0-9]{64}', nonce), 'Fresh challenge required.')
        print(json.dumps(dict(inspect(time.time()), nonce=nonce)))
    except Exception:
        sys.exit('STOP: QNAP backup readiness failed.')


if __name__ == '__main__':
    main()
