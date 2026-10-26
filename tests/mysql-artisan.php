<?php

require __DIR__.'/mysql-bootstrap.php';

if (! in_array($argv[1] ?? '', ['migrate', 'migrate:fresh', 'migrate:rollback', 'migrate:status'], true)) {
    throw new RuntimeException('Only migration commands are supported by this isolated test launcher.');
}
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--database')) {
        throw new RuntimeException('The guarded MySQL connection cannot be overridden.');
    }
}
$process = proc_open([PHP_BINARY, __DIR__.'/../artisan', ...array_slice($argv, 1)], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
if (! is_resource($process)) {
    throw new RuntimeException('Unable to start Artisan.');
}
exit(proc_close($process));
