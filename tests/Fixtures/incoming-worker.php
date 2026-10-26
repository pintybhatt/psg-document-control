<?php

use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[$script, $driver, $database, $actorId, $companyId, $siteId, $recordId, $barrier, $worker, $rollback] = $argv;
$root = realpath(__DIR__.'/../../storage/framework/testing');
if (! $root || ! str_starts_with($barrier, $root.'/incoming-') || ! preg_match('/^incoming-[a-f0-9-]+$/D', basename($barrier))) {
    throw new RuntimeException('Only isolated incoming test barriers are allowed.');
}
if ($driver === 'mysql') {
    require __DIR__.'/../mysql-bootstrap.php';
} elseif ($driver === 'sqlite' && realpath($database) === $barrier.'.sqlite') {
    require __DIR__.'/../../vendor/autoload.php';
} else {
    throw new RuntimeException('Invalid isolated database.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($driver === 'sqlite') {
    config(['database.default' => 'incoming_parallel', 'cache.default' => 'array', 'database.connections.incoming_parallel' => [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED',
    ]]);
}
$actor = User::query()->findOrFail((int) $actorId);
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
    $record = DB::transaction(function () use ($actor, $scope, $recordId, $rollback) {
        $record = app(ManageIncomingCorrespondence::class)->register($actor, $scope, $recordId);
        if ($rollback === 'yes') {
            throw new RuntimeException('Deliberate incoming rollback');
        }

        return $record;
    }, 1);
    fwrite(STDOUT, json_encode(['number' => $record->internal_number]));
} catch (ValidationException $exception) {
    if (($exception->errors()['incoming'][0] ?? null) !== 'incomingAlreadyRegistered') {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['duplicate' => true]));
} catch (RuntimeException $exception) {
    if ($rollback !== 'yes' || $exception->getMessage() !== 'Deliberate incoming rollback') {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['rollback' => true]));
}
