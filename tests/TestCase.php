<?php

namespace Tests;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fixtures\FormalRegistrationRecord;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (defined('DCMS_ISOLATED_MYSQL_TESTS')) {
            $app['events']->listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
                if ($event->method === 'up') {
                    FormalRegistrationRecord::createTestTable();
                }
            });
        }

        return $app;
    }
}
