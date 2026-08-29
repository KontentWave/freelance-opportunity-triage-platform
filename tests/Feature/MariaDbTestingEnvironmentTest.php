<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MariaDbTestingEnvironmentTest extends TestCase
{
    #[Test]
    public function it_runs_the_test_suite_against_a_mariadb_test_database(): void
    {
        $databaseName = (string) DB::connection()->getDatabaseName();
        $driver = (string) DB::connection()->getConfig('driver');
        $versionRow = DB::selectOne('select version() as version');
        $version = strtolower((string) ($versionRow->version ?? ''));

        $this->assertTrue(app()->environment('testing'));
        $this->assertContains($driver, ['mysql', 'mariadb']);
        $this->assertStringEndsWith('_test', $databaseName);
        $this->assertStringContainsString('mariadb', $version);
    }
}
