<?php

use App\Authorization\DocumentNumbering;
use App\Models\AuthorizationScope;
use App\Models\NumberingSeries;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FormalRegistrationRecord;

require __DIR__.'/../mysql-bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $mode, $actorId, $scopeId, $seriesId, $barrier, $worker] = $argv;
$root = realpath(__DIR__.'/../../storage/framework/testing/mysql84');
if (! $root || ! str_starts_with($barrier, $root.'/worker-') || ! preg_match('/^worker-[a-f0-9]+$/D', basename($barrier))) {
    throw new RuntimeException('Only isolated test barriers are allowed.');
}
$actor = User::query()->findOrFail((int) $actorId);
$scope = AuthorizationScope::query()->findOrFail((int) $scopeId);
$series = NumberingSeries::query()->findOrFail((int) $seriesId);
$wait = function () use ($barrier): void {
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'.go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }
};
$issue = function (NumberingSeries $selected) use ($actor, $scope): string {
    return app(DocumentNumbering::class)->register($actor, $scope, $selected->id, fn (string $number): FormalRegistrationRecord => FormalRegistrationRecord::query()->create([
        'scope_id' => $scope->id, 'register_id' => $selected->register_id, 'state' => 'registered', 'internal_number' => $number,
    ]))->number;
};
$attempts = 0;
try {
    if ($mode === 'deadlock') {
        $other = NumberingSeries::query()->findOrFail((int) $argv[7]);
        $result = DB::transaction(function () use (&$attempts, $issue, $actor, $scope, $series, $other, $barrier, $worker, $wait): array {
            $attempts++;
            $second = null;
            $first = app(DocumentNumbering::class)->register($actor, $scope, $series->id, function (string $number) use (&$second, &$attempts, $issue, $scope, $series, $other, $barrier, $worker, $wait): FormalRegistrationRecord {
                $record = FormalRegistrationRecord::query()->create(['scope_id' => $scope->id, 'register_id' => $series->register_id, 'state' => 'registered', 'internal_number' => $number]);
                if ($attempts === 1) {
                    file_put_contents($barrier.'.ready-'.$worker, 'ready');
                    $wait();
                }
                $second = $issue($other);

                return $record;
            })->number;

            return [$first, $second];
        }, 2);
        $result = ['numbers' => $result, 'attempts' => $attempts];
    } else {
        if ($mode !== 'hold') {
            file_put_contents($barrier.'.ready-'.$worker, 'ready');
            $wait();
        }
        $number = DB::transaction(function () use ($mode, $actor, $scope, $series, $barrier, $worker, $wait): string {
            return app(DocumentNumbering::class)->register($actor, $scope, $series->id, function (string $number) use ($mode, $scope, $series, $barrier, $worker, $wait): FormalRegistrationRecord {
                $record = FormalRegistrationRecord::query()->create(['scope_id' => $scope->id, 'register_id' => $series->register_id, 'state' => 'registered', 'internal_number' => $number]);
                if ($mode === 'hold') {
                    file_put_contents($barrier.'.ready-'.$worker, 'ready');
                    $wait();
                }
                if ($mode === 'rollback') {
                    throw new RuntimeException('Deliberate test rollback');
                }

                return $record;
            })->number;
        }, 1);
        $result = ['number' => $number, 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
    }
} catch (RuntimeException $exception) {
    if ($mode !== 'rollback' || $exception->getMessage() !== 'Deliberate test rollback') {
        throw $exception;
    }
    $result = ['rolled_back' => true];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
