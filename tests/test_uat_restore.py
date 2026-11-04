import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

path = Path(__file__).resolve().parent.parent/'docker/uat-restore-check.py'
spec = importlib.util.spec_from_file_location('uat_restore', path)
restore = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore)


class RestoreTests(unittest.TestCase):
    def test_document_bytes_are_checked_against_restored_metadata(self):
        with tempfile.TemporaryDirectory() as directory:
            archive_path = Path(directory)/'documents.tar'
            identity = 'a'*8+'-'+'a'*4+'-'+'a'*4+'-'+'a'*4+'-'+'a'*12
            with tarfile.open(archive_path, 'w') as archive:
                item = tarfile.TarInfo('documents/objects/'+identity)
                item.size = 4
                archive.addfile(item, io.BytesIO(b'test'))
            rows = [[identity, '4', hashlib.sha256(b'test').hexdigest()]]
            with patch.object(restore.paired.hashlib, 'file_digest', None, create=True):
                restore.verify_documents(rows, archive_path)
            for changes in ([identity, '5', rows[0][2]], [identity, '4', '0'*64], ['b'*36, '4', rows[0][2]]):
                with self.subTest(changes=changes), self.assertRaises(ValueError):
                    restore.verify_documents([changes], archive_path)

    def test_restore_uses_only_its_new_socket_and_cleans_up_after_failure(self):
        from unittest.mock import Mock
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'database.sql').write_text('synthetic')
            server = Mock()
            server.poll.return_value = None
            calls = []
            def command(args, **kwargs):
                calls.append(args)
                if '--version' in args:
                    return 'mysqld Ver 8.4.11'
                if '--initialize-insecure' in args:
                    datadir = next(value.split('=', 1)[1] for value in args if value.startswith('--datadir='))
                    Path(datadir).parent.joinpath('mysql.sock').touch()
                    return ''
                raise subprocess.CalledProcessError(1, args)
            with patch.object(restore.paired, 'validate'), patch.object(restore, 'WORK', root), \
                    patch.object(restore, 'command', side_effect=command), patch.object(restore.subprocess, 'Popen', return_value=server) as start:
                with self.assertRaises(subprocess.CalledProcessError):
                    restore.restore(root)
                self.assertIn('--skip-networking', start.call_args.args[0])
                self.assertIn('--mysqlx=OFF', start.call_args.args[0])
                self.assertIn('--protocol=SOCKET', calls[-1])
                self.assertFalse(any(value.startswith(('--host', '--port')) for value in calls[-1]))
                server.terminate.assert_called_once()
            self.assertFalse(list(root.glob('v-*')))

    def test_database_verification_refuses_missing_history_protections(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with tarfile.open(root/'configuration.tar', 'w') as archive:
                for name, value in [('current.json', json.dumps({'migration_inventory': [{'path': 'database/migrations/one.php'}]}).encode()), ('app.env', b'APP_KEY=base64:synthetic\n')]:
                    item = tarfile.TarInfo(name)
                    item.size = len(value)
                    archive.addfile(item, io.BytesIO(value))
            def query(sql):
                if sql == 'SELECT DATABASE()': return [['dcms_uat']]
                if sql.startswith('SELECT migration'): return [['one']]
                if sql.startswith('SELECT COUNT(*)'): return [['0']]
                return []
            with self.assertRaises(ValueError):
                restore.verify_database(query, root)


class NativeRestoreTests(unittest.TestCase):
    @unittest.skipUnless(__import__('os').environ.get('DCMS_NATIVE_RESTORE_TEST') == '1', 'Explicit opt-in for disposable native MySQL restore')
    def test_real_mysql_restore_and_numbering_failure(self):
        with tempfile.TemporaryDirectory(prefix='dcms-restore-test-') as directory:
            root = Path(directory)
            source = root/'backup'
            source.mkdir()
            work = root/'work'
            work.mkdir()
            sql = '''CREATE DATABASE dcms_uat; USE dcms_uat;
CREATE TABLE migrations (migration varchar(255)); INSERT INTO migrations VALUES ('one');
CREATE TABLE numbering_counters (series_id int,period int,value int,PRIMARY KEY(series_id,period));
CREATE TABLE issued_document_numbers (series_id int,period int,sequence int,FOREIGN KEY(series_id,period) REFERENCES numbering_counters(series_id,period));
CREATE TABLE xppl_numbering_counters (namespace_id int,period int,baseline int,value int);
CREATE TABLE xppl_numbering_allocations (namespace_id int,period int,sequence int);
CREATE TABLE attachment_versions (id varchar(36),size int,sha256 varchar(64));
INSERT INTO numbering_counters VALUES (1,0,1); INSERT INTO issued_document_numbers VALUES (1,0,1);
'''
            for table in ('issued_document_numbers', 'xppl_numbering_allocations', 'attachment_versions'):
                for event in ('UPDATE', 'DELETE'):
                    sql += f"CREATE TRIGGER {table}_{event} BEFORE {event} ON {table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='protected';\n"
            identity = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'
            sql += "INSERT INTO attachment_versions VALUES ('"+identity+"',4,'"+hashlib.sha256(b'test').hexdigest()+"');\n"
            (source/'database.sql').write_text(sql+'-- Dump completed on synthetic date\n')
            with tarfile.open(source/'configuration.tar', 'w') as archive:
                for name in restore.paired.CONFIG:
                    value = (json.dumps({'migration_inventory': [{'path': 'database/migrations/one.php'}]}).encode() if name == 'current.json' else b'APP_KEY=base64:synthetic\n')
                    item = tarfile.TarInfo(name)
                    item.size = len(value)
                    archive.addfile(item, io.BytesIO(value))
            with tarfile.open(source/'documents.tar', 'w') as archive:
                for name in ('documents', 'private'):
                    item = tarfile.TarInfo(name)
                    item.type = tarfile.DIRTYPE
                    archive.addfile(item)
                item = tarfile.TarInfo('documents/objects/'+identity)
                item.size = 4
                archive.addfile(item, io.BytesIO(b'test'))
            def checksums():
                (source/'SHA256SUMS').write_text(''.join(hashlib.sha256((source/name).read_bytes()).hexdigest()+'  '+name+'\n' for name in restore.paired.FILES[:3]))
            checksums()
            with patch.object(restore, 'WORK', work):
                restore.restore(source)
                (source/'database.sql').write_text(sql.replace('VALUES (1,0,1); INSERT INTO issued', 'VALUES (1,0,0); INSERT INTO issued')+'-- Dump completed on synthetic date\n')
                checksums()
                with self.assertRaises(ValueError):
                    restore.restore(source)
            self.assertEqual(list(work.iterdir()), [])
