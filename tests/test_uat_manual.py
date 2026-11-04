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
        fixture.preserved = {
            'release/compose.uat.yaml': 'services:\n  app:\n    cpus: 2\n    mem_limit: 2g\n  db:\n    cpus: 0.5\n    mem_limit: 1536m\n    command: [--log-bin-trust-function-creators=ON]\n',
            'release/docker/apache-uat.conf': 'MaxRequestWorkers 8\n',
            'config/app.env': 'XPPL_UAT_IMPORT_ENABLED=false\nPHP_MEMORY_LIMIT=512M\n',
        }
        for name, content in fixture.preserved.items():
            (fixture.root/name).write_text(content)
        original_compose = (fixture.root/'config/compose.env').read_text()+'SERVER_MANAGED_VALUE=preserve\n'
        (fixture.root/'config/compose.env').write_text(original_compose)
        fixture.preserved['config/compose.env'] = original_compose.replace(fixtures.OLD, DIGEST)
        with patch.object(manual, 'runtime', deploy), patch.object(manual, 'ROOT', fixture.root), \
                patch.object(deploy.Deployment, 'run', side_effect=fixture.fake_run), \
                patch.object(deploy.Deployment, 'storage'), patch.object(deploy.Deployment, 'migrate', side_effect=ValueError('migration failed') if failure == 'migration' else lambda: fixture.calls.append(['migrate'])) as migrate, \
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
        self.assertLess(backup, fixture.calls.index(['migrate']))
        self.assertLess(fixture.calls.index(['migrate']), replacement)
        self.assertEqual(fixture.calls[replacement][-1], 'app')
        self.assertFalse((fixture.root/'config/recovery-required.json').exists())
        for name, content in fixture.preserved.items():
            self.assertEqual((fixture.root/name).read_text(), content)
        self.assertFalse(any(('compose' in call and 'down' in call and 'artisan' not in call) or '--volumes' in call for call in fixture.calls))

    def test_failed_health_keeps_recovery_gate(self):
        fixture, _ = self.exercise('health')
        self.assertTrue((fixture.root/'config/recovery-required.json').exists())

    def test_backup_failure_blocks_migration_and_restart(self):
        fixture, migrated = self.exercise('backup')
        self.assertFalse(migrated)
        self.assertFalse(any('--force-recreate' in call for call in fixture.calls))
        self.assertTrue((fixture.root/'config/recovery-required.json').exists())

    def test_migration_failure_keeps_recovery_state_and_never_replaces_the_app(self):
        fixture, migrated = self.exercise('migration')
        self.assertTrue(migrated)
        self.assertFalse(any('--force-recreate' in call for call in fixture.calls))
        self.assertTrue((fixture.root/'config/recovery-required.json').exists())
        self.assertEqual(json.loads((fixture.root/'config/current.json').read_text())['image'], fixtures.OLD)
