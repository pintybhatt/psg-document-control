<?php

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Models\ControlledDocument;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[$script, $driver, $database, $actorId, $companyId, $siteId, $recordId, $barrier, $worker, $operation, $version, $revisionId, $rollback] = $argv;
$root = realpath(__DIR__.'/../../storage/framework/testing');
if (! $root || ! str_starts_with($barrier, $root.'/controlled-') || ! preg_match('/^controlled-[a-f0-9-]+$/D', basename($barrier))) {
    throw new RuntimeException('Only isolated controlled test barriers are allowed.');
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
    config(['database.default' => 'controlled_parallel', 'cache.default' => 'array', 'database.connections.controlled_parallel' => [
        'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED',
    ]]);
}
$actor = User::query()->findOrFail((int) $actorId);
$departmentId = ControlledDocument::query()->findOrFail((int) $recordId)->profile->department_id;
$scope = new DocumentScope((int) $companyId, (int) $siteId, $departmentId);
$namespaceId = isset($argv[13]) && $argv[13] !== '' ? (int) $argv[13] : null;
file_put_contents($barrier.'.ready-'.$worker, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(10000);
}
try {
    $record = DB::transaction(function () use ($actor, $scope, $recordId, $operation, $version, $revisionId, $rollback, $namespaceId) {
        $manager = app(ManageControlledDocuments::class);
        $data = ['lock_version' => (int) $version];
        if ($operation === 'register' && $namespaceId !== null) {
            $data['namespace_id'] = $namespaceId;
        }
        $record = match ($operation) {
            'register' => $manager->register($actor, $scope, $recordId, $data),
            'revise' => $manager->revise($actor, $scope, $recordId, $data + ['revision_reason' => 'Concurrent review', 'change_description' => 'Updated procedure']),
            'release' => $manager->release($actor, $scope, $recordId, $data + ['revision_id' => (int) $revisionId, 'reason' => 'Concurrent authorized release']),
            default => throw new RuntimeException('Unsupported controlled operation.'),
        };
        if ($rollback === 'yes') {
            throw new RuntimeException('Deliberate controlled rollback');
        }

        return $record;
    }, 1);
    fwrite(STDOUT, json_encode(['success' => true, 'number' => $record->internal_number, 'current_revision_id' => $record->current_revision_id]));
} catch (ValidationException $exception) {
    if (($exception->errors()['controlled'][0] ?? null) !== 'controlledStale') {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['stale' => true]));
} catch (RuntimeException $exception) {
    if ($rollback !== 'yes' || $exception->getMessage() !== 'Deliberate controlled rollback') {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['rollback' => true]));
}
