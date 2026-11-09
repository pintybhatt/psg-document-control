"""Live QNAP gate using synthetic backup sets; never contacts operational hosts."""
import ast
import datetime
import hashlib
import json
import os
import shutil
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from test_release_preparation import load

qnap = load('qnap_readiness_tests', 'prd-qnap-readiness.py')
NOW = datetime.datetime(2026, 11, 9, 14, 13, 23, tzinfo=datetime.timezone(datetime.timedelta(hours=7))).timestamp()


class QnapPython36CompatibilityTests(unittest.TestCase):
    def test_verifier_accepts_python36_grammar_and_rejects_known_new_syntax(self):
        source = Path(qnap.__file__).read_text()
        ast.parse(source, filename=qnap.__file__, feature_version=(3, 6))
        for unsupported in ('while chunk := stream.read(1):\n    pass\n',
                            'def probe(value, /):\n    pass\n',
                            'match value:\n    case 1: pass\n'):
            with self.subTest(unsupported=unsupported), self.assertRaises(SyntaxError):
                ast.parse(unsupported, feature_version=(3, 6))

    @unittest.skipUnless(shutil.which('python3.6'), 'Python 3.6 unavailable in DEV; grammar check is not native runtime verification')
    def test_verifier_imports_in_isolated_native_python36_without_deployment_helpers(self):
        result = subprocess.run([shutil.which('python3.6'), '-I', '-B', '-c',
                                 'import runpy, sys; runpy.run_path(sys.argv[1], run_name="compatibility_check")',
                                 qnap.__file__], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr.decode())


class QnapReadinessTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.directory = self.root/'daily-20261109_131323'
        self.directory.mkdir()
        for name in qnap.PAYLOAD:
            (self.directory/name).write_bytes(('synthetic '+name).encode())
        self.sums()
        (self.directory/'.complete').write_text('Completed: '+self.directory.name+'\n')
        self.timestamps(NOW-3600)
        self.root_patch = patch.object(qnap, 'QNAP_ROOT', self.root)
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)
        mount_patch = patch.object(qnap, 'QNAP_MOUNT', self.root)
        mount_patch.start()
        self.addCleanup(mount_patch.stop)

    def sums(self):
        (self.directory/'SHA256SUMS').write_text(''.join(
            hashlib.sha256((self.directory/name).read_bytes()).hexdigest()+'  '+name+'\n'
            for name in sorted(qnap.PAYLOAD)))

    def timestamps(self, timestamp):
        for path in self.directory.iterdir():
            os.utime(path, (timestamp, timestamp))

    def inspect(self):
        mount = {'filesystems': [{'source': qnap.QNAP_SOURCE, 'fstype': 'nfs4', 'target': str(self.root)}]}
        with patch.object(qnap.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, json.dumps(mount).encode())):
            return qnap.inspect(NOW)

    def test_success_reads_and_checks_all_offhost_payloads(self):
        data = self.inspect()
        self.assertIs(data['verified'], True)
        self.assertEqual(data['backup_time'], NOW-3600)
        self.assertEqual(data['path'], str(self.directory))
        self.assertEqual(data['sha256sums'], hashlib.sha256((self.directory/'SHA256SUMS').read_bytes()).hexdigest())

    def test_nfs_inspection_and_production_client_use_python36_subprocess_api(self):
        calls = []
        evidence = {}

        def run(command, input=None, stdout=None, stderr=None, check=False, timeout=None, env=None):
            calls.append(command)
            self.assertEqual(stdout, subprocess.PIPE)
            self.assertEqual(stderr, subprocess.PIPE)
            if command[0] == '/usr/bin/findmnt':
                self.assertTrue(check)
                data = {'filesystems': [{'source': qnap.QNAP_SOURCE, 'fstype': 'nfs4', 'target': str(self.root)}]}
            else:
                self.assertEqual(command[0], '/usr/bin/ssh')
                data = dict(evidence, nonce=input.decode('ascii').strip())
            return subprocess.CompletedProcess(command, 0, json.dumps(data).encode(), b'')

        with patch.object(qnap.subprocess, 'run', side_effect=run), \
                patch.object(qnap.time, 'time', return_value=NOW), \
                patch.object(qnap, 'protected', side_effect=lambda path, *args: path):
            evidence.update(qnap.inspect(NOW))
            verified = qnap.verify()
        self.assertTrue(verified['verified'])
        self.assertEqual(verified['sha256sums'], evidence['sha256sums'])
        self.assertEqual([command[0] for command in calls], ['/usr/bin/findmnt', '/usr/bin/findmnt', '/usr/bin/ssh'])

    def test_missing_evidence_including_marker_and_payload_is_refused(self):
        for name in ('.complete', 'SHA256SUMS', 'database.sql', 'configuration.tar.enc'):
            path = self.directory/name
            original = path.read_bytes()
            path.unlink()
            with self.subTest(name=name), self.assertRaises((ValueError, OSError)):
                self.inspect()
            path.write_bytes(original)
            self.timestamps(NOW-3600)

    def test_marker_content_must_match_the_selected_daily_backup(self):
        for marker in ('', 'complete', 'Completed: daily-20261108_131323\n',
                       'Completed: '+self.directory.name+'\nextra', 'x'*129):
            (self.directory/'.complete').write_text(marker)
            with self.subTest(marker=marker), self.assertRaisesRegex(ValueError, 'marker'):
                self.inspect()

    def test_manual_marker_without_payloads_and_checksums_is_refused(self):
        for path in self.directory.iterdir():
            if path.name != '.complete':
                path.unlink()
        with self.assertRaises(OSError):
            self.inspect()

    def test_stale_daily_identity_cannot_be_refreshed_by_touching_all_files(self):
        older = self.root/'daily-20261107_131323'
        self.directory.rename(older)
        self.directory = older
        (older/'.complete').write_text('Completed: '+older.name+'\n')
        self.timestamps(NOW)
        with self.assertRaisesRegex(ValueError, 'stale'):
            self.inspect()

    def test_daily_identity_must_be_a_valid_date_and_not_in_the_future(self):
        for name in ('daily-20261309_131323', 'daily-20261110_131323'):
            path = self.root/name
            path.mkdir()
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.inspect()
            path.rmdir()

    def test_latest_daily_identity_is_selected_even_when_old_directory_is_touched(self):
        older = self.root/'daily-20261108_131323'
        older.mkdir()
        os.utime(older, (NOW, NOW))
        (self.root/'unrelated-directory').mkdir()
        self.assertEqual(self.inspect()['path'], str(self.directory))

    def test_link_in_place_of_latest_daily_directory_is_refused(self):
        (self.root/'daily-20261109_133000').symlink_to(self.directory, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'links refused'):
            self.inspect()

    def test_new_manual_marker_does_not_make_stale_payloads_ready(self):
        self.timestamps(NOW-qnap.MAX_AGE-1)
        os.utime(self.directory/'.complete', (NOW, NOW))
        with self.assertRaisesRegex(ValueError, 'stale'):
            self.inspect()

    def test_bad_checksum_and_partial_transfer_are_refused(self):
        path = self.directory/'documents.tar'
        path.write_bytes(b'partial transfer')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.inspect()

    def test_future_payload_is_refused(self):
        self.timestamps(NOW+1)
        with self.assertRaisesRegex(ValueError, 'future'):
            self.inspect()

    def test_local_disk_or_foreign_mount_cannot_supply_evidence(self):
        for source, kind in (('/dev/sda1', 'ext4'), ('192.168.0.228:/backup', 'nfs4')):
            mount = {'filesystems': [{'source': source, 'fstype': kind, 'target': str(self.root)}]}
            with patch.object(qnap.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, json.dumps(mount).encode())):
                with self.subTest(source=source), self.assertRaisesRegex(ValueError, 'mount'):
                    qnap.inspect(NOW)

    def test_exact_export_mountpoint_and_nfs_type_are_required(self):
        for source, kind, target in ((qnap.QNAP_SOURCE, 'nfs4', '/mnt'),
                                     (qnap.QNAP_SOURCE, 'cifs', str(self.root)),
                                     ('192.168.0.239:/another-export', 'nfs4', str(self.root))):
            mount = {'filesystems': [{'source': source, 'fstype': kind, 'target': target}]}
            with patch.object(qnap.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, json.dumps(mount).encode())):
                with self.subTest(source=source, kind=kind, target=target), self.assertRaisesRegex(ValueError, 'mount'):
                    qnap.inspect(NOW)

    def test_unmount_during_verification_is_refused(self):
        mounted = {'filesystems': [{'source': qnap.QNAP_SOURCE, 'fstype': 'nfs4', 'target': str(self.root)}]}
        unmounted = {'filesystems': [{'source': '/dev/sda1', 'fstype': 'ext4', 'target': '/'}]}
        with patch.object(qnap.subprocess, 'run', side_effect=[
                subprocess.CompletedProcess([], 0, json.dumps(mounted).encode()),
                subprocess.CompletedProcess([], 0, json.dumps(unmounted).encode())]) as findmnt:
            with self.assertRaisesRegex(ValueError, 'mount'):
                qnap.inspect(NOW)
            self.assertEqual(findmnt.call_args.args[0], ['/usr/bin/findmnt', '--json', '--target', str(self.root),
                                                        '--output', 'SOURCE,FSTYPE,TARGET'])

    def test_missing_coverage_unsafe_manifest_and_symlink_are_refused(self):
        for contents in ('0'*64+'  ../escape\n', '0'*64+'  database.sql\n', ''):
            (self.directory/'SHA256SUMS').write_text(contents)
            with self.subTest(contents=contents), self.assertRaises(ValueError):
                self.inspect()
        self.sums()
        path = self.directory/'documents.tar'
        path.unlink()
        path.symlink_to(self.directory/'database.sql')
        with self.assertRaises(ValueError):
            self.inspect()

    def test_extra_or_substituted_payload_checksums_are_refused(self):
        with (self.directory/'SHA256SUMS').open('a') as stream:
            stream.write('0'*64+'  private.tar\n')
        with self.assertRaisesRegex(ValueError, 'three daily'):
            self.inspect()

    def test_failed_new_transfer_does_not_fall_back_to_older_success(self):
        (self.root/'daily-20261109_133000').mkdir()
        with self.assertRaises(OSError):
            self.inspect()

    def verify(self, response=None, code=0, error=None):
        data = {'project': 'dcms-prd-xppl', 'database': 'dcms_prd', 'host': '192.168.4.25',
                'qnap': '192.168.0.239', 'path': str(self.directory), 'backup_time': NOW-3600,
                'sha256sums': 'a'*64, 'verified': True, 'nonce': 'b'*64}
        data.update(response or {})
        with patch.object(qnap, 'protected', side_effect=lambda path, *args: path), \
                patch.object(qnap.secrets, 'token_hex', return_value='b'*64), \
                patch.object(qnap.time, 'time', return_value=NOW), \
                patch.object(qnap.subprocess, 'run', side_effect=error,
                             return_value=subprocess.CompletedProcess([], code, json.dumps(data).encode(), b'private')) as ssh:
            result = qnap.verify()
            command = ssh.call_args.args[0]
            self.assertEqual(command[-2:], ['dcms-prd-qnap-read@192.168.4.25', 'verify-dcms-prd-qnap'])
            self.assertIn('StrictHostKeyChecking=yes', command)
            self.assertIn('ProxyCommand=none', command)
            self.assertEqual(ssh.call_args.kwargs['input'], ('b'*64+'\n').encode())
            return result

    def test_client_requires_live_pinned_ssh_and_fresh_challenge_response(self):
        self.assertTrue(self.verify()['verified'])
        for response in ({'nonce': 'c'*64}, {'verified': False}, {'backup_time': NOW-qnap.MAX_AGE-1},
                         {'database': 'dcms_uat'}, {'path': '/srv/dcms-prd/backups/local'},
                         {'sha256sums': 'wrong'}, {'backup_time': float('nan')}):
            with self.subTest(response=response), self.assertRaises(ValueError):
                self.verify(response)

    def test_failed_transport_and_timeout_refuse_without_local_fallback(self):
        with self.assertRaises(ValueError):
            self.verify(code=1)
        with self.assertRaises(subprocess.TimeoutExpired):
            self.verify(error=subprocess.TimeoutExpired(['ssh'], 900))

    def test_config_custody_rejects_public_file_and_symlink(self):
        path = self.root/'config.json'
        path.write_text('{}')
        path.chmod(0o644)
        with self.assertRaises(ValueError):
            qnap.protected(path, True)
        path.unlink()
        path.symlink_to(self.directory/'SHA256SUMS')
        with self.assertRaises(ValueError):
            qnap.protected(path, True)


if __name__ == '__main__':
    unittest.main()
