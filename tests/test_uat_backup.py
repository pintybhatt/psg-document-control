import hashlib
import importlib.util
import io
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('uat_backup', Path(__file__).resolve().parent.parent/'docker/uat-backup.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class UatBackupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root/'source'
        self.source.mkdir()
        self.store = self.root/'managed-v1'
        self.store.mkdir()
        (self.source/'database.sql').write_text('-- synthetic dump\n-- Dump completed on synthetic date\n')
        for name, entries in [('documents.tar', ['documents', 'private']), ('configuration.tar', backup.CONFIG)]:
            with tarfile.open(self.source/name, 'w') as archive:
                for entry in entries:
                    item = tarfile.TarInfo(entry)
                    if name == 'documents.tar':
                        item.type = tarfile.DIRTYPE
                        archive.addfile(item)
                    else:
                        item.size = 1
                        archive.addfile(item, io.BytesIO(b'x'))
        self.checksums()
        self.bundle = io.BytesIO()
        with tarfile.open(fileobj=self.bundle, mode='w') as archive:
            for name in backup.FILES:
                archive.add(self.source/name, arcname=name)
        self.bundle.seek(0)

    def checksums(self):
        (self.source/'SHA256SUMS').write_text(''.join(hashlib.sha256((self.source/name).read_bytes()).hexdigest()+'  '+name+'\n' for name in backup.FILES[:3]))

    def test_success_requires_restore_then_retains_only_three_managed_successes(self):
        legacy = self.store/'existing-backup'
        legacy.mkdir()
        pending = self.store/'pending-existing'
        pending.mkdir()
        with patch.object(backup, 'STORE', self.store), patch.object(backup.subprocess, 'run') as restore:
            for _ in range(4):
                self.bundle.seek(0)
                backup.receive(self.bundle)
            self.assertEqual(restore.call_count, 4)
            self.assertEqual(restore.call_args.args[0][0], backup.RESTORE)
        self.assertEqual(len(list(self.store.glob('success-*'))), 3)
        self.assertTrue(legacy.exists())
        self.assertTrue(pending.exists())

    def test_retention_keeps_the_latest_three_completion_times(self):
        paths = []
        for index in range(5):
            path = self.store/('success-'+format(index, '032x'))
            path.mkdir()
            (path/'success.json').write_text('{}')
            os.utime(path, ns=(1000000000+index, 1000000000+index))
            paths.append(path)
        with patch.object(backup, 'STORE', self.store):
            backup.retain()
        self.assertEqual([path.exists() for path in paths], [False, False, True, True, True])

    def test_missing_configuration_members_fail_even_with_valid_checksums(self):
        with tarfile.open(self.source/'configuration.tar', 'w'):
            pass
        self.checksums()
        with self.assertRaises(ValueError):
            backup.validate(self.source)

    def test_failed_restore_never_marks_success_or_prunes_existing_sets(self):
        existing = self.store/('success-'+'a'*32)
        existing.mkdir()
        (existing/'success.json').write_text('{}')
        with patch.object(backup, 'STORE', self.store), patch.object(backup.subprocess, 'run', side_effect=OSError('synthetic failure')):
            with self.assertRaises(OSError):
                backup.receive(self.bundle)
        self.assertEqual(list(self.store.glob('success-*')), [existing])
        self.assertEqual(len(list(self.store.glob('pending-*'))), 1)

    def test_tampered_missing_and_incomplete_files_fail_verification(self):
        (self.source/'database.sql').write_text('tampered')
        with self.assertRaises(ValueError):
            backup.validate(self.source)
        self.checksums()
        with self.assertRaises(ValueError):
            backup.validate(self.source)
        (self.source/'documents.tar').unlink()
        with self.assertRaises(ValueError):
            backup.validate(self.source)

    def test_archive_traversal_is_refused_before_restore(self):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w') as archive:
            item = tarfile.TarInfo('../escape')
            item.size = 1
            archive.addfile(item, io.BytesIO(b'x'))
        stream.seek(0)
        with patch.object(backup, 'STORE', self.store), patch.object(backup.subprocess, 'run') as restore:
            with self.assertRaises(ValueError):
                backup.receive(stream)
            restore.assert_not_called()
        self.assertFalse((self.root/'escape').exists())

    def test_sender_requires_pinned_ssh_and_matching_remote_acknowledgement(self):
        from types import SimpleNamespace
        digest = backup.validate(self.source)
        with patch.object(backup, 'validate', return_value=digest), patch.object(backup.tarfile, 'open'), patch.object(backup.subprocess, 'run') as ssh:
            for code, response in [(1, b''), (0, b'OK wrong\n'), (0, ('OK '+digest+'\n').encode())]:
                ssh.return_value = SimpleNamespace(returncode=code, stdout=response)
                if code or b'wrong' in response:
                    with self.assertRaises(ValueError):
                        backup.send('/srv/dcms-uat/backups/gha-123-1')
                else:
                    backup.send('/srv/dcms-uat/backups/gha-123-1')
                command = ssh.call_args.args[0]
                self.assertIn('dcms-uat-backup@192.168.0.227', command)
                self.assertIn('StrictHostKeyChecking=yes', command)
        with self.assertRaises(ValueError):
            backup.send('/srv/dcms-production/backups/gha-123-1')
