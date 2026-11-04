import io
import json
import unittest
from unittest.mock import patch
from test_release_preparation import load
import test_uat_deployment as fixtures
from test_uat_deployment import deploy, DIGEST, SOURCE

manual = load('uat_manual', 'deploy-uat-manual.py')


class ManualDeploymentTests(unittest.TestCase):
    def test_only_immutable_dc_ms_source_requests_are_accepted(self):
        data = {'image': DIGEST, 'source_commit': SOURCE, 'run': '789-1'}
        self.assertEqual(manual.request(io.BytesIO(json.dumps(data).encode())), data)
        for changes in ({'image':'latest'}, {'source_commit':'main'}, {'run':'../escape'}, {'target':'production'}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                manual.request(io.BytesIO(json.dumps(dict(data, **changes)).encode()))

    def exercise(self, failure=None):
        fixture = fixtures.UatDeploymentTests()
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        fixture.failure = failure
        with patch.object(manual, 'runtime', deploy), patch.object(manual, 'ROOT', fixture.root), \
                patch.object(deploy.Deployment, 'run', side_effect=fixture.fake_run), \
                patch.object(deploy.Deployment, 'storage'), patch.object(deploy.Deployment, 'migrate') as migrate, \
                patch.object(manual, 'local_backup', side_effect=ValueError('backup failed') if failure == 'backup' else lambda *args: fixture.calls.append(['local-backup'])):
            try:
                manual.execute({'image':DIGEST, 'source_commit':SOURCE, 'run':'789-1'})
            except ValueError:
                if failure is None: raise
            return fixture, migrate.called

    def test_backup_and_migrations_precede_app_only_restart(self):
        fixture, migrated = self.exercise()
        self.assertTrue(migrated)
        backup = fixture.calls.index(['local-backup'])
        replacement = next(i for i, call in enumerate(fixture.calls) if '--force-recreate' in call)
        self.assertLess(backup, replacement)
        self.assertEqual(fixture.calls[replacement][-1], 'app')
        self.assertFalse((fixture.root/'config/recovery-required.json').exists())

    def test_failed_health_keeps_recovery_gate(self):
        fixture, _ = self.exercise('health')
        self.assertTrue((fixture.root/'config/recovery-required.json').exists())

    def test_backup_failure_blocks_migration_and_restart(self):
        fixture, migrated = self.exercise('backup')
        self.assertFalse(migrated)
        self.assertFalse(any('--force-recreate' in call for call in fixture.calls))
        self.assertTrue((fixture.root/'config/recovery-required.json').exists())
