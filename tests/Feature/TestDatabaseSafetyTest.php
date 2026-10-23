<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_feature_tests_do_not_target_the_live_application_database(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->assertSame('testing', app()->environment());
        $this->assertNotContains(trim($database), ['avdbcms', 'avdbclip']);
        $this->assertTrue(
            $connection === 'sqlite' || str_ends_with(trim($database), '_test'),
            "Unexpected non-test database target for {$connection}: {$database}"
        );
    }
}
