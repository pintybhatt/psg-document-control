<?php

namespace Tests\Feature;

use App\Authorization\PrdImportTarget;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PrdImportTargetTest extends TestCase
{
    /** Mock the server boundary: these tests never open a MySQL connection.
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $server
     * @param  list<object>  $tables
     */
    private function mysqlTarget(array $configuration = [], array $server = [], array $tables = []): MySqlConnection
    {
        $this->app['env'] = 'production';
        config(['xppl_prd_import' => ['enabled' => true, 'host' => 'production.invalid', 'port' => '3306', 'database' => 'dcms_prd', 'server_uuid' => 'synthetic-server-uuid', 'account' => 'synthetic_prd@%']]);
        $configuration = array_replace(['host' => 'production.invalid', 'port' => 3306, 'database' => 'dcms_prd'], $configuration);
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn($configuration['database']);
        $connection->shouldReceive('getConfig')->andReturnUsing(fn (string $field): mixed => $configuration[$field] ?? null);
        $connection->shouldReceive('selectOne')->with('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version, CURRENT_USER() AS account', [], false)
            ->andReturn((object) array_replace(['db' => 'dcms_prd', 'uuid' => 'synthetic-server-uuid', 'version' => '8.4.7', 'account' => 'synthetic_prd@%'], $server));
        $connection->shouldReceive('select')->with("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')", [], false)->andReturn($tables);
        $connection->shouldNotReceive('getPdo');
        DB::shouldReceive('connection')->andReturn($connection);

        return $connection;
    }

    public function test_exact_production_identity_and_mysql_84_are_accepted(): void
    {
        $this->mysqlTarget();
        $this->assertSame(['environment' => 'production', 'driver' => 'mysql', 'host' => 'production.invalid', 'port' => '3306', 'database' => 'dcms_prd', 'server_uuid' => 'synthetic-server-uuid', 'account' => 'synthetic_prd@%'], app(PrdImportTarget::class)->verify());
    }

    #[TestWith(['8.0.40'])]
    #[TestWith(['8.5.0'])]
    #[TestWith(['8.4.7-MariaDB'])]
    #[TestWith(['10.11.8-MariaDB'])]
    public function test_other_database_versions_are_refused(string $version): void
    {
        $this->mysqlTarget(server: ['version' => $version]);
        $this->expectExceptionMessage('Verified MySQL 8.4 required');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['read', ['host' => 'other.invalid']])]
    #[TestWith(['write', ['host' => 'other.invalid']])]
    #[TestWith(['read', []])]
    #[TestWith(['write', []])]
    #[TestWith(['unix_socket', '/tmp/mysql.sock'])]
    #[TestWith(['url', 'mysql://synthetic.invalid/dcms_prd'])]
    public function test_split_connections_socket_and_url_overrides_are_refused_before_queries(string $field, mixed $value): void
    {
        $connection = $this->mysqlTarget([$field => $value]);
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('not supported');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['host', 'wrong.invalid'])]
    #[TestWith(['port', 3307])]
    #[TestWith(['host', ['production.invalid', 'other.invalid']])]
    public function test_wrong_configured_host_or_port_is_refused_before_queries(string $field, mixed $value): void
    {
        $connection = $this->mysqlTarget([$field => $value]);
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('Target '.$field.' differs');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['uuid', 'wrong-server', 'server_uuid'])]
    #[TestWith(['account', 'other_prd@%', 'account'])]
    public function test_live_server_uuid_and_authenticated_account_must_match(string $field, string $value, string $identityField): void
    {
        $this->mysqlTarget(server: [$field => $value]);
        $this->expectExceptionMessage('Target '.$identityField.' differs');
        app(PrdImportTarget::class)->verify();
    }

    public function test_uat_database_is_refused_even_if_independently_configured(): void
    {
        $connection = $this->mysqlTarget(['database' => 'dcms_uat']);
        config(['xppl_prd_import.database' => 'dcms_uat']);
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('requires the independently approved dcms_prd database');
        app(PrdImportTarget::class)->verify();
    }

    public function test_live_database_must_be_dcms_prd(): void
    {
        $this->mysqlTarget(server: ['db' => 'dcms_uat']);
        $this->expectExceptionMessage('Connected database must be dcms_prd');
        app(PrdImportTarget::class)->verify();
    }

    public function test_nontransactional_tables_are_refused(): void
    {
        $this->mysqlTarget(tables: [(object) ['TABLE_NAME' => 'synthetic_myisam_table']]);
        $this->expectExceptionMessage('All target tables must use InnoDB');
        app(PrdImportTarget::class)->verify();
    }

    public function test_testing_environment_cannot_use_mysql_even_with_production_identity(): void
    {
        $connection = $this->mysqlTarget();
        $this->app['env'] = 'testing';
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('automated tests may use only in-memory SQLite');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['host'])]
    #[TestWith(['port'])]
    #[TestWith(['database'])]
    #[TestWith(['server_uuid'])]
    #[TestWith(['account'])]
    public function test_independent_configuration_cannot_be_omitted(string $field): void
    {
        $this->mysqlTarget();
        config(['xppl_prd_import.'.$field => null]);
        $this->expectException(RuntimeException::class);
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith([false])]
    #[TestWith(['true'])]
    #[TestWith([1])]
    public function test_enablement_requires_a_boolean_true(mixed $enabled): void
    {
        config(['xppl_prd_import.enabled' => $enabled]);
        DB::shouldReceive('connection')->never();
        $this->expectExceptionMessage('Production import is disabled');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['localhost'])]
    #[TestWith(['production.invalid,other.invalid'])]
    #[TestWith(['db;unix_socket=/tmp/mysql.sock'])]
    public function test_implicit_socket_or_multiple_hosts_are_refused(string $host): void
    {
        $connection = $this->mysqlTarget(['host' => $host]);
        config(['xppl_prd_import.host' => $host]);
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('A single explicit TCP host is required');
        app(PrdImportTarget::class)->verify();
    }

    #[TestWith(['0'])]
    #[TestWith(['65536'])]
    #[TestWith(['3306;unix_socket=/tmp/mysql.sock'])]
    public function test_invalid_tcp_ports_are_refused_before_queries(string $port): void
    {
        $connection = $this->mysqlTarget(['port' => $port]);
        config(['xppl_prd_import.port' => $port]);
        $connection->shouldNotReceive('selectOne');
        $this->expectExceptionMessage('An explicit valid TCP port is required');
        app(PrdImportTarget::class)->verify();
    }
}
