<?php

use App\Authorization\DocumentNumbering;
use App\Models\AuthorizationScope;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FormalRegistrationRecord;

require __DIR__.'/../../vendor/autoload.php';
$database = realpath($argv[1] ?? '');
$root = realpath(__DIR__.'/../../storage/framework/testing');
if (! $database || ! $root || ! str_starts_with($database, $root.'/numbering-') || ! str_ends_with($database, '.sqlite')) {
    throw new RuntimeException('Only an isolated numbering test database is allowed.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'numbering_parallel', 'cache.default' => 'array', 'database.connections.numbering_parallel' => [
    'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED',
]]);
$actor = User::query()->findOrFail((int) $argv[2]);
$scope = AuthorizationScope::query()->findOrFail((int) $argv[3]);
$series = (int) $argv[4];
$register = (int) $argv[5];
$worker = (int) $argv[6];
file_put_contents($database.'.ready-'.$worker, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($database.'.start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker start barrier timed out.');
    }
    usleep(10000);
}
try {
    $issued = DB::transaction(fn () => app(DocumentNumbering::class)->register($actor, $scope, $series, function (string $number) use ($scope, $register, $worker): FormalRegistrationRecord {
        usleep(30000);
        $record = FormalRegistrationRecord::query()->create(['scope_id' => $scope->id, 'register_id' => $register, 'state' => 'registered', 'internal_number' => $number]);
        if ($worker === 3) {
            throw new RuntimeException('Deliberate test rollback');
        }

        return $record;
    }), 1);
    fwrite(STDOUT, json_encode(['sequence' => $issued->sequence, 'number' => $issued->number]));
} catch (RuntimeException $exception) {
    if ($worker !== 3 || $exception->getMessage() !== 'Deliberate test rollback') {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['rolled_back' => true]));
}
