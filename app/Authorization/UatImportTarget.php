<?php

namespace App\Authorization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UatImportTarget
{
    /** @return array{environment: string, driver: string, host: string, port: string, database: string, server_uuid: string, account: string} */
    public function verify(): array
    {
        if (config('xppl_uat_import.enabled') !== true) {
            throw new RuntimeException('UAT import is disabled; enable only in the independently verified UAT deployment.');
        }
        $connection = DB::connection();
        $testing = app()->runningUnitTests();
        if (! app()->environment('uat') && ! $testing) {
            throw new RuntimeException('Requires APP_ENV=uat; local, staging and production are refused.');
        }
        $driver = $connection->getDriverName();
        if ($testing && $driver === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            $identity = ['driver' => 'sqlite', 'host' => 'memory', 'port' => '0', 'database' => ':memory:', 'server_uuid' => 'sqlite-test-only', 'account' => 'test-only'];
        } else {
            if ($driver !== 'mysql' || ($testing && ! defined('DCMS_ISOLATED_MYSQL_TESTS'))) {
                throw new RuntimeException('Requires MySQL 8.4; SQLite is allowed only in in-memory automated tests.');
            }
            if ($connection->getConfig('read') || $connection->getConfig('write') || $connection->getConfig('unix_socket')) {
                throw new RuntimeException('Read/write split connections and sockets are not supported by this import guard.');
            }
            $server = (array) $connection->selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version, CURRENT_USER() AS account', [], false);
            if (! preg_match('/^8\.4\./', $server['version']) || str_contains($server['version'], 'MariaDB')) {
                throw new RuntimeException('Verified MySQL 8.4 required.');
            }
            $identity = ['driver' => 'mysql', 'host' => $connection->getConfig('host'), 'port' => (string) $connection->getConfig('port'), 'database' => $server['db'], 'server_uuid' => $server['uuid'], 'account' => $server['account']];
            $tables = $connection->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'", [], false);
            if ($tables !== []) {
                throw new RuntimeException('All target tables must use InnoDB for transactional rollback.');
            }
        }
        foreach (['host', 'port', 'database', 'server_uuid', 'account'] as $field) {
            $expected = config('xppl_uat_import.'.$field);
            if (! is_string($identity[$field]) || ! is_scalar($expected) || (string) $expected === '' || ! hash_equals((string) $expected, $identity[$field])) {
                throw new RuntimeException('Target '.$field.' differs from the independently approved UAT identity.');
            }
        }

        return ['environment' => app()->environment()] + $identity;
    }

    /** Verify artifact integrity and an administrator's external restore-test attestation; never restore here.
     * @param  array<string, string>  $target
     */
    public function verifyBackup(string $path, string $manifestPath, array $target, string $planHash, int $actorId): void
    {
        foreach ([$path, $manifestPath] as $file) {
            $real = realpath($file);
            if (! str_starts_with($file, '/') || ! $real || ! is_file($real) || is_link($file) || ! is_readable($real)
                || str_starts_with($real, public_path().'/') || (fileperms($real) & 0077) !== 0) {
                throw new RuntimeException('Backup and manifest must be existing private readable absolute files outside public/.');
            }
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifestTarget = is_array($manifest['target'] ?? null) ? $manifest['target'] : [];
        ksort($manifestTarget);
        ksort($target);
        if (! is_array($manifest) || $manifestTarget !== $target || ($manifest['plan_sha256'] ?? null) !== $planHash
            || ($manifest['verified_by'] ?? null) !== $actorId || ($manifest['restore_verified'] ?? null) !== true
            || ! is_string($manifest['verification_reference'] ?? null) || trim($manifest['verification_reference']) === ''
            || filesize($path) < 1 || ! hash_equals(hash_file('sha256', $path), (string) ($manifest['backup_sha256'] ?? ''))) {
            throw new RuntimeException('Backup integrity, target, plan or restore-test attestation does not match.');
        }
        foreach (['created_at', 'verified_at'] as $field) {
            if (! is_string($manifest[$field] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $manifest[$field])) {
                throw new RuntimeException('Backup timestamps must be absolute ISO-8601 dates with a timezone.');
            }
        }
        $created = CarbonImmutable::parse($manifest['created_at'] ?? 'invalid', 'UTC');
        $verified = CarbonImmutable::parse($manifest['verified_at'] ?? 'invalid', 'UTC');
        if ($created->isFuture() || $created->lt(now()->subDay()) || $verified->lt($created) || $verified->isFuture()) {
            throw new RuntimeException('Backup must be less than 24 hours old with a subsequent completed restore-test attestation.');
        }
    }
}
