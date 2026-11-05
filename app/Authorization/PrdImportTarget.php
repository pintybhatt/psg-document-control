<?php

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrdImportTarget
{
    /** @return array{environment: string, driver: string, host: string, port: string, database: string, server_uuid: string, account: string} */
    public function verify(): array
    {
        if (config('xppl_prd_import.enabled') !== true) {
            throw new RuntimeException('Production import is disabled; enable only for the explicitly authorized initialization.');
        }
        $testing = app()->runningUnitTests();
        if (! app()->environment('production') && ! $testing) {
            throw new RuntimeException('Requires APP_ENV=production; DEV, UAT, local and staging are refused.');
        }
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($connection->getConfig('read') !== null || $connection->getConfig('write') !== null || $connection->getConfig('unix_socket') || $connection->getConfig('url')) {
            throw new RuntimeException('Read/write split connections, sockets and URL overrides are not supported by this import guard.');
        }
        if ($testing && $driver === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            $identity = ['driver' => 'sqlite', 'host' => 'memory', 'port' => '0', 'database' => ':memory:', 'server_uuid' => 'sqlite-test-only', 'account' => 'test-only'];
        } else {
            if ($driver !== 'mysql' || $testing) {
                throw new RuntimeException('Requires MySQL 8.4; automated tests may use only in-memory SQLite.');
            }
            if (config('xppl_prd_import.database') !== 'dcms_prd' || $connection->getDatabaseName() !== 'dcms_prd') {
                throw new RuntimeException('Production import requires the independently approved dcms_prd database.');
            }
            $host = $connection->getConfig('host');
            if (is_string($host) && (strtolower($host) === 'localhost' || ! preg_match('/^[a-zA-Z0-9.:[\]_-]+$/D', $host))) {
                throw new RuntimeException('A single explicit TCP host is required; implicit sockets and multiple hosts are refused.');
            }
            foreach (['host', 'port'] as $field) {
                $this->matchIdentity($field, $connection->getConfig($field));
            }
            $port = (string) $connection->getConfig('port');
            if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new RuntimeException('An explicit valid TCP port is required.');
            }
            $server = (array) $connection->selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version, CURRENT_USER() AS account', [], false);
            if (! preg_match('/^8\.4\./', $server['version'] ?? '') || str_contains($server['version'], 'MariaDB')) {
                throw new RuntimeException('Verified MySQL 8.4 required.');
            }
            if (($server['db'] ?? null) !== 'dcms_prd') {
                throw new RuntimeException('Connected database must be dcms_prd.');
            }
            $identity = ['driver' => 'mysql', 'host' => $connection->getConfig('host'), 'port' => (string) $connection->getConfig('port'), 'database' => $server['db'], 'server_uuid' => $server['uuid'], 'account' => $server['account']];
            $tables = $connection->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')", [], false);
            if ($tables !== []) {
                throw new RuntimeException('All target tables must use InnoDB for transactional rollback.');
            }
        }
        foreach (['host', 'port', 'database', 'server_uuid', 'account'] as $field) {
            $this->matchIdentity($field, $identity[$field]);
        }

        return ['environment' => app()->environment()] + $identity;
    }

    private function matchIdentity(string $field, mixed $actual): void
    {
        $expected = config('xppl_prd_import.'.$field);
        if (! is_scalar($actual) || ! is_scalar($expected) || (string) $expected === '' || ! hash_equals((string) $expected, (string) $actual)) {
            throw new RuntimeException('Target '.$field.' differs from the independently approved Production identity.');
        }
    }

    /** Reuse the existing artifact-only verifier unchanged; it never calls UAT verify() or reads UAT configuration.
     * @param  array<string, string>  $target
     */
    public function verifyBackup(string $path, string $manifestPath, array $target, string $planHash, int $actorId): void
    {
        (new UatImportTarget)->verifyBackup($path, $manifestPath, $target, $planHash, $actorId);
    }
}
