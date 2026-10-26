<?php

use App\Authorization\DocumentScope;
use App\Authorization\ManagePhysicalCustody;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

[$script, $driver, $database, $actorId, $companyId, $siteId, $recordId, $copyId, $barrier, $worker] = $argv;
$root = realpath(__DIR__.'/../../storage/framework/testing');
if (! $root || ! str_starts_with($barrier, $root.'/custody-') || ! preg_match('/^custody-[a-f0-9-]+$/D', basename($barrier))) {
    throw new RuntimeException('Only isolated custody test barriers are allowed.');
}
if ($driver === 'mysql') {
    require getenv('DCMS_NATIVE_MYSQL_TESTS') === '1' ? __DIR__.'/../native-mysql-bootstrap.php' : __DIR__.'/../mysql-bootstrap.php';
} elseif ($driver === 'sqlite' && realpath($database) === $barrier.'.sqlite') {
    require __DIR__.'/../../vendor/autoload.php';
} else {
    throw new RuntimeException('Invalid isolated database.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($driver === 'sqlite') {
    config(['database.default' => 'custody_parallel', 'cache.default' => 'array', 'database.connections.custody_parallel' => [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED',
    ]]);
}
$actor = User::findOrFail((int) $actorId);
$scope = new DocumentScope((int) $companyId, (int) $siteId);
file_put_contents($barrier.'.ready-'.$worker, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(10000);
}
try {
    app(ManagePhysicalCustody::class)->change($actor, $scope, 'incoming', $recordId, null, $copyId,
        ['action' => 'checkout', 'lock_version' => 1, 'custodian_id' => $actor->id, 'checkout_date' => '2026-10-25', 'expected_return_date' => '2026-10-28', 'reason' => 'Concurrent checkout']);
    fwrite(STDOUT, 'success');
} catch (ValidationException $failure) {
    if (($failure->errors()['storage'][0] ?? null) !== 'storageStale') {
        throw $failure;
    }
    fwrite(STDOUT, 'stale');
}
