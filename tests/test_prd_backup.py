"""Production backup and disposable restore checks using synthetic archives and mocked transport/MySQL."""
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch

from test_release_preparation import load
from test_prd_deployment import ONE, image

backup = load('prd_backup_tests', 'prd-backup.py')
restore = load('prd_restore_tests', 'prd-restore-check.py')


def archive_file(archive, name, content):
    item = tarfile.TarInfo(name)
    item.size = len(content)
    archive.addfile(item, io.BytesIO(content))


class ProductionBackupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dcms-prd-backup-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root/'backups/gha-789-1'
        self.source.mkdir(parents=True)
        self.identity = {'backup_id': 'gha-789-1', 'project': 'dcms-prd-xppl', 'database': 'dcms_prd',
                         'image': image('sha256:'+'b'*64)[0], 'source_commit': 'a'*40}
        self.manifest = dict(self.identity, migration_inventory=[ONE])
        (self.source/'database.sql').write_text('synthetic SQL\n-- Dump completed on synthetic\n')
        (self.source/'identity.json').write_text(json.dumps(self.identity))
        with tarfile.open(self.source/'configuration.tar', 'w') as archive:
            for name in backup.CONFIG:
                content = json.dumps(self.manifest).encode() if name == 'current.json' else b'APP_KEY=base64:synthetic\n'
                archive_file(archive, name, content)
        with tarfile.open(self.source/'documents.tar', 'w') as archive:
            for name in ('documents', 'private'):
                item = tarfile.TarInfo(name)
                item.type = tarfile.DIRTYPE
                archive.addfile(item)
            archive_file(archive, 'documents/objects/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', b'test')
            archive_file(archive, 'private/private-test.bin', b'private bytes')
        self.checksums()
        self.store, self.work = self.root/'store', self.root/'work'
        self.store.mkdir(mode=0o700)
        self.work.mkdir(mode=0o700)

    def checksums(self):
        (self.source/'SHA256SUMS').write_text(''.join(hashlib.sha256((self.source/name).read_bytes()).hexdigest()+'  '+name+'\n' for name in backup.PAYLOAD))
        for path in self.source.iterdir(): path.chmod(0o600)

    def bundle(self):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w') as archive:
            for name in backup.FILES:
                archive_file(archive, name, (self.source/name).read_bytes())
        stream.seek(0)
        return stream

    def test_valid_paired_backup_verifies_every_checksum_and_deployment_identity(self):
        digest, identity = backup.validate(self.source)
        self.assertEqual(digest, hashlib.sha256((self.source/'SHA256SUMS').read_bytes()).hexdigest())
        self.assertEqual(identity, self.identity)

    def test_tampering_missing_members_public_permissions_and_incomplete_dump_are_refused(self):
        original = (self.source/'database.sql').read_bytes()
        (self.source/'database.sql').write_bytes(b'corrupt')
        with self.assertRaises(ValueError): backup.validate(self.source)
        self.checksums()
        with self.assertRaises(ValueError): backup.validate(self.source)
        (self.source/'database.sql').write_bytes(original)
        self.checksums()
        (self.source/'database.sql').chmod(0o644)
        with self.assertRaises(ValueError): backup.validate(self.source)
        (self.source/'database.sql').chmod(0o600)
        (self.source/'identity.json').unlink()
        with self.assertRaises(ValueError): backup.validate(self.source)

    def test_uat_backup_identity_is_refused_even_with_correct_checksums(self):
        for key, value in (('project', 'dcms-uat-xppl'), ('database', 'dcms_uat'), ('image', 'ghcr.io/other/app@sha256:'+'b'*64)):
            data = dict(self.identity, **{key: value})
            (self.source/'identity.json').write_text(json.dumps(data))
            self.checksums()
            with self.subTest(key=key), self.assertRaises(ValueError): backup.validate(self.source)

    def test_archive_traversal_links_duplicate_members_and_unrelated_roots_are_refused(self):
        for name, kind in (('../escape', 'file'), ('documents/link', 'link'), ('uat/secret', 'file'), ('documents/repeated', 'duplicate')):
            with tarfile.open(self.source/'documents.tar', 'w') as archive:
                for root in ('documents', 'private'):
                    item = tarfile.TarInfo(root)
                    item.type = tarfile.DIRTYPE
                    archive.addfile(item)
                if kind == 'link':
                    item = tarfile.TarInfo(name)
                    item.type, item.linkname = tarfile.SYMTYPE, '/srv/dcms-uat'
                    archive.addfile(item)
                else:
                    archive_file(archive, name, b'test')
                    if kind == 'duplicate': archive_file(archive, name, b'test')
            self.checksums()
            with self.subTest(name=name), self.assertRaises(ValueError): backup.validate(self.source)

    def test_offhost_transport_uses_only_production_credentials_pinned_host_and_matching_receipt(self):
        digest, _ = backup.validate(self.source)
        with patch.object(backup, 'ROOT', self.root), patch.object(backup, 'protected', side_effect=lambda path, *args: path), \
                patch.object(backup, 'target', return_value={'host': 'backup.example.test', 'port': 2222}), \
                patch.object(backup.subprocess, 'run') as ssh:
            ssh.return_value = subprocess.CompletedProcess([], 0, ('OK gha-789-1 '+digest+'\n').encode(), b'')
            self.assertTrue(backup.send(self.source)['verified'])
            command = ssh.call_args.args[0]
            self.assertIn('dcms-prd-backup@backup.example.test', command)
            self.assertEqual(command[-1], 'receive-dcms-prd-backup')
            self.assertIn('StrictHostKeyChecking=yes', command)
            self.assertIn(str(self.root/'config/backup-ssh-key'), command)
            self.assertNotIn('dcms-uat', ' '.join(command))
            for code, receipt in ((1, b''), (0, b'OK other-run wrong-hash\n')):
                ssh.return_value = subprocess.CompletedProcess([], code, receipt, b'synthetic secret must not be displayed')
                with self.assertRaisesRegex(ValueError, 'acknowledgement'): backup.send(self.source)
        with self.assertRaises(ValueError): backup.send(Path('/srv/dcms-uat/backups/gha-789-1'))

    def test_failed_restore_preserves_pending_data_and_never_acknowledges_success(self):
        with patch.object(backup, 'receiver_paths', return_value=(self.store, self.work)), \
                patch.object(backup.pwd, 'getpwuid', return_value=Mock(pw_name='dcms-prd-backup')), \
                patch.object(backup, 'protected', side_effect=lambda path, *args: path), \
                patch.object(backup.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, ['synthetic-restore'])):
            with self.assertRaises(subprocess.CalledProcessError): backup.receive(self.bundle())
        pending = list(self.store.glob('pending-*'))
        self.assertEqual(len(pending), 1)
        self.assertTrue((pending[0]/'database.sql').exists())
        self.assertFalse(list(self.store.glob('success-*')))
        self.assertFalse((pending[0]/'receipt.json').exists())

    def test_success_acknowledges_only_after_isolated_restore_and_retains_all_previous_sets(self):
        with patch.object(backup, 'receiver_paths', return_value=(self.store, self.work)), \
                patch.object(backup.pwd, 'getpwuid', return_value=Mock(pw_name='dcms-prd-backup')), \
                patch.object(backup, 'protected', side_effect=lambda path, *args: path), \
                patch.object(backup.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as check:
            for _ in range(4):
                self.assertTrue(backup.receive(self.bundle()).startswith('OK gha-789-1 '))
            self.assertEqual(check.call_count, 4)
            self.assertIn('prd-restore-check.py', check.call_args.args[0][-2])
        completed = list(self.store.glob('success-*'))
        self.assertEqual(len(completed), 4)
        self.assertTrue(all(json.loads((path/'receipt.json').read_text())['verified'] for path in completed))

    def test_receiver_rejects_unexpected_account_and_transfer_members(self):
        with patch.object(backup.pwd, 'getpwuid', return_value=Mock(pw_name='dcms-uat-backup')):
            with self.assertRaises(ValueError): backup.receive(io.BytesIO())
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w') as archive:
            archive_file(archive, '../escape', b'data')
        stream.seek(0)
        with patch.object(backup, 'receiver_paths', return_value=(self.store, self.work)), \
                patch.object(backup.pwd, 'getpwuid', return_value=Mock(pw_name='dcms-prd-backup')):
            with self.assertRaises(ValueError): backup.receive(stream)
        self.assertFalse((self.root/'escape').exists())

    def test_backup_target_cannot_select_localhost_uat_accounts_or_arbitrary_options(self):
        config = self.root/'config'
        config.mkdir()
        target = config/'backup-target.json'
        for name in ('backup-ssh-key', 'backup-known-hosts'):
            (config/name).write_text('synthetic')
        cases = [{'host': host, 'port': 22} for host in ('localhost', '127.0.0.2', '127.1', 'dcms.phonesackgroup.com', 'REPLACE.invalid')]
        cases += [{'host': 'backup.example.test', 'port': 22, 'user': 'dcms-uat-backup'}, {'host': 'backup.example.test', 'port': True}]
        with patch.object(backup, 'ROOT', self.root), patch.object(backup, 'protected', side_effect=lambda path, *args: path):
            for data in cases:
                target.write_text(json.dumps(data))
                with self.subTest(data=data), self.assertRaises(ValueError): backup.target()

    def test_receiver_paths_are_separate_and_cannot_point_to_uat_live_storage_or_symlinks(self):
        config = self.root/'config'
        config.mkdir()
        receiver = config/'receiver.json'
        with patch.object(backup, 'REMOTE_ROOT', self.root), patch.object(backup, 'protected', side_effect=lambda path, *args: path):
            receiver.write_text(json.dumps({'store': str(self.store), 'restore_work': str(self.work)}))
            self.assertEqual(backup.receiver_paths(), [self.store, self.work])
            for store, work in (('/srv/dcms-uat/backups', str(self.work)), ('/srv/dcms-prd', str(self.work)),
                                (str(self.store), str(self.store))):
                receiver.write_text(json.dumps({'store': store, 'restore_work': work}))
                with self.assertRaises(ValueError): backup.receiver_paths()
            link = self.root/'linked-store'
            link.symlink_to(self.store, target_is_directory=True)
            receiver.write_text(json.dumps({'store': str(link), 'restore_work': str(self.work)}))
            with self.assertRaises(ValueError): backup.receiver_paths()

    def test_disposable_mysql_uses_new_socket_no_network_and_is_stopped_after_restore_failure(self):
        calls = []
        server = Mock()
        server.poll.return_value = None
        def command(args, **kwargs):
            calls.append(args)
            if '--version' in args: return 'mysqld Ver 8.4.13'
            if '--initialize-insecure' in args:
                datadir = next(value.split('=', 1)[1] for value in args if value.startswith('--datadir='))
                Path(datadir).parent.joinpath('mysql.sock').touch()
                return ''
            raise subprocess.CalledProcessError(1, args)
        with patch.object(restore.paired, 'receiver_paths', return_value=(self.store, self.work)), \
                patch.object(restore, 'command', side_effect=command), patch.object(restore.subprocess, 'Popen', return_value=server) as start:
            with self.assertRaises(subprocess.CalledProcessError): restore.restore(self.source)
            arguments = start.call_args.args[0]
            self.assertIn('--skip-networking', arguments)
            self.assertIn('--mysqlx=OFF', arguments)
            self.assertIn('--event-scheduler=OFF', arguments)
            self.assertIn('--protocol=SOCKET', calls[-1])
            self.assertIn('--no-defaults', calls[-1])
            self.assertIn('--no-login-paths', calls[-1])
            self.assertFalse(any(value.startswith(('--host', '--port')) for value in calls[-1]))
            self.assertTrue(any(value.startswith('--socket='+str(self.work)) for value in calls[-1]))
            server.terminate.assert_called_once()
        self.assertEqual(list(self.work.iterdir()), [])

    def query(self, sql):
        if sql == 'SELECT DATABASE()': return [['dcms_prd']]
        if sql.startswith('SELECT SCHEMA_NAME'): return []
        if sql.startswith('SELECT migration'): return [[Path(ONE['path']).stem]]
        if sql.startswith('SELECT TABLE_NAME FROM'): return [['attachment_versions']]
        if sql.startswith('CHECK TABLE'): return [['dcms_prd.attachment_versions', 'check', 'status', 'OK']]
        if sql.startswith('SELECT COUNT(*)'): return [['0']]
        if sql.startswith('SELECT EVENT_OBJECT_TABLE'):
            return [[table, event] for table in ('issued_document_numbers', 'xppl_numbering_allocations', 'attachment_versions') for event in ('UPDATE', 'DELETE')]
        if sql.startswith('SELECT id,size,sha256'): return [['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '4', hashlib.sha256(b'test').hexdigest()]]
        return []

    def test_restore_checks_database_migrations_tables_history_guards_and_attachment_bytes(self):
        restore.verify_database(self.query, self.source)
        cases = [('SELECT DATABASE()', [['dcms_uat']]), ('SELECT migration', [['unknown']]),
                 ('CHECK TABLE', [['dcms_prd.attachment_versions', 'check', 'error', 'corrupt']]),
                 ('SELECT EVENT_OBJECT_TABLE', []), ('SELECT COUNT(*) FROM xppl_numbering_counters', [['1']]),
                 ('SELECT id,size,sha256', [['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '4', '0'*64]])]
        for prefix, result in cases:
            with self.subTest(prefix=prefix), self.assertRaises(ValueError):
                restore.verify_database(lambda sql: result if sql.startswith(prefix) else self.query(sql), self.source)

    def test_missing_document_or_wrong_size_is_refused(self):
        for row in (['bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '4', '0'*64], ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '5', '0'*64]):
            with self.assertRaises(ValueError): restore.verify_documents([row], self.source/'documents.tar')


if __name__ == '__main__':
    unittest.main()
