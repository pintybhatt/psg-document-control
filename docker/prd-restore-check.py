#!/usr/bin/env python3
"""Restore one Production backup into an ephemeral socket-only MySQL; never connect to a live DB."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import signal
import subprocess
import sys
import tarfile
import tempfile
import time

source = Path(__file__).with_name('prd-backup.py')
if not source.exists():
    source = Path(__file__).with_name('dcms-prd-backup')
spec = importlib.util.spec_from_loader('paired_backup', importlib.machinery.SourceFileLoader('paired_backup', str(source)))
paired = importlib.util.module_from_spec(spec)
spec.loader.exec_module(paired)


def require(condition):
    paired.require(condition, 'Isolated Production restore verification failed.')


MYSQLD = '/usr/sbin/mysqld'
MYSQL = '/usr/bin/mysql'

ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'LC_ALL': 'C'}


def command(args, timeout=90, **kwargs):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          env=ENV, timeout=timeout, **kwargs).stdout.decode()


def quoted(name):
    require(re.fullmatch(r'[a-zA-Z0-9_]+', name) is not None)
    return '`'+name+'`'


def verify_database(query, directory):
    require(query('SELECT DATABASE()') == [['dcms_prd']])
    require(query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','mysql','performance_schema','sys','dcms_prd')") == [])
    require(query('SELECT COUNT(*) FROM migrations WHERE batch<1') == [['0']])
    with tarfile.open(directory/'configuration.tar') as config:
        manifest = json.load(config.extractfile('current.json'))
        app = config.extractfile('app.env').read().decode()
        require(re.search(r'^APP_KEY=.+$', app, re.M) is not None)
    expected = sorted(Path(row['path']).stem for row in manifest['migration_inventory'])
    require(expected and query('SELECT migration FROM migrations ORDER BY migration') == [[name] for name in expected])
    tables = query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")
    for (table,) in tables:
        rows = query('CHECK TABLE '+quoted(table))
        require(rows and all(row[-2:] == ['status', 'OK'] for row in rows))
        query('SELECT COUNT(*) FROM '+quoted(table))
    # mysqldump disables FK checks during import; enabling them does not revalidate rows.
    keys = query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")
    groups = {}
    for table, key, column, parent, parent_column in keys:
        groups.setdefault((table, key, parent), []).append((column, parent_column))
    for (table, key, parent), columns in groups.items():
        joined = ' AND '.join('c.'+quoted(c)+'=p.'+quoted(p) for c, p in columns)
        nonnull = ' AND '.join('c.'+quoted(c)+' IS NOT NULL' for c, _ in columns)
        require(query('SELECT COUNT(*) FROM '+quoted(table)+' c LEFT JOIN '+quoted(parent)+' p ON '+joined+' WHERE '+nonnull+' AND p.'+quoted(columns[0][1])+' IS NULL') == [['0']])
    require(query('SELECT COUNT(*) FROM issued_document_numbers i LEFT JOIN numbering_counters c ON c.series_id=i.series_id AND c.period=i.period WHERE c.series_id IS NULL OR i.sequence<1 OR i.sequence>c.value') == [['0']])
    require(query('SELECT COUNT(*) FROM xppl_numbering_allocations a LEFT JOIN xppl_numbering_counters c ON c.namespace_id=a.namespace_id AND c.period=a.period WHERE c.namespace_id IS NULL OR a.sequence<=c.baseline OR a.sequence>c.value') == [['0']])
    require(query('SELECT COUNT(*) FROM xppl_numbering_counters WHERE value<baseline') == [['0']])
    triggers = query("SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")
    for table in ('issued_document_numbers', 'xppl_numbering_allocations', 'attachment_versions'):
        for event in ('UPDATE', 'DELETE'):
            require([table, event] in triggers)
    verify_documents(query('SELECT id,size,sha256 FROM attachment_versions'), directory/'documents.tar')


def verify_documents(rows, path):
    with tarfile.open(path) as archive:
        members = archive.getmembers()
        require(len({item.name for item in members}) == len(members))
        objects = {item.name: item for item in members if item.isfile()}
        for identity, size, checksum in rows:
            require(re.fullmatch(r'[a-fA-F0-9-]{36}', identity) is not None)
            item = objects.get('documents/objects/'+identity)
            require(item is not None and item.size == int(size))
            with archive.extractfile(item) as content:
                require(paired.sha256_stream(content) == checksum)


def restore(directory):
    paired.validate(directory)
    _, work = paired.receiver_paths()
    require(re.search(r'\bVer 8\.4\.', command([MYSQLD, '--no-defaults', '--version'])) is not None)
    require(work.is_dir() and not work.is_symlink())
    with tempfile.TemporaryDirectory(prefix='v-', dir=work) as temporary:
        root = Path(temporary)
        data, socket = root/'data', root/'mysql.sock'
        require(len(str(socket)) < 100)
        command([MYSQLD, '--no-defaults', '--initialize-insecure', '--datadir='+str(data)])
        server = subprocess.Popen([MYSQLD, '--no-defaults', '--datadir='+str(data), '--socket='+str(socket),
                                   '--pid-file='+str(root/'pid'), '--skip-networking', '--mysqlx=OFF',
                                   '--skip-log-bin', '--event-scheduler=OFF', '--local-infile=OFF',
                                   '--secure-file-priv='+str(root), '--innodb-buffer-pool-size=64M',
                                   '--max-connections=5'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=ENV)
        client = [MYSQL, '--no-defaults', '--no-login-paths', '--protocol=SOCKET', '--socket='+str(socket), '--user=root',
                  '--batch', '--raw', '--skip-column-names', '--local-infile=0', '--binary-mode']
        try:
            for _ in range(150):
                require(server.poll() is None)
                if socket.exists():
                    break
                time.sleep(0.2)
            require(socket.exists())
            # No --force: any restore error is fatal. There is no host/port fallback.
            with (directory/'database.sql').open('rb') as dump:
                command(client, stdin=dump, timeout=600)
            def query(sql):
                output = command(client+['--database=dcms_prd', '--execute='+sql]).rstrip('\n')
                return [line.split('\t') for line in output.splitlines()]
            verify_database(query, directory)
        finally:
            server.terminate()
            try:
                server.wait(timeout=15)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)


def main():
    try:
        require(len(sys.argv) == 2 and pwd.getpwuid(os.geteuid()).pw_name == 'dcms-prd-backup')
        directory = Path(sys.argv[1])
        require(directory.parent == paired.receiver_paths()[0] and re.fullmatch(r'pending-[a-f0-9]{32}', directory.name)
                and not directory.is_symlink())
        signal.signal(signal.SIGALRM, lambda *_: (_ for _ in ()).throw(TimeoutError()))
        signal.alarm(1000)
        restore(directory)
        signal.alarm(0)
    except Exception:
        sys.exit('STOP: Isolated Production restore verification failed.')


if __name__ == '__main__':
    main()
