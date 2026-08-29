<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->assertSafeTestingDatabaseConfiguration($app);

        return $app;
    }

    private function assertSafeTestingDatabaseConfiguration(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Refusing to run tests outside the testing environment.');
        }

        $defaultConnection = (string) $app['config']->get('database.default');
        $connectionConfig = $app['config']->get("database.connections.{$defaultConnection}");

        if (! is_array($connectionConfig)) {
            throw new RuntimeException('Refusing to run tests without a configured database connection.');
        }

        $driver = (string) ($connectionConfig['driver'] ?? '');
        $databaseName = (string) ($connectionConfig['database'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Refusing to run tests on a non-MySQL/MariaDB connection.');
        }

        if ($databaseName === '' || ! str_ends_with($databaseName, '_test')) {
            throw new RuntimeException('Refusing to run tests against a database whose name does not end with _test.');
        }
    }
}
