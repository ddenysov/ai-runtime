<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->assertSafeTestingDatabaseConfiguration();

        parent::setUp();

        $this->assertSafeTestingDatabaseConfiguration();
    }

    private function assertSafeTestingDatabaseConfiguration(): void
    {
        if (getenv('APP_ENV') !== 'testing') {
            return;
        }

        $default = (string) (getenv('DB_CONNECTION') ?: $_ENV['DB_CONNECTION'] ?? '');
        $database = (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? '');

        if ($default !== 'sqlite' || $database !== ':memory:') {
            $this->fail(
                "Refusing to run tests against {$default}://{$database}. "
                .'Tests must use sqlite :memory: (see phpunit.xml DB_* env with force="true").'
            );
        }
    }
}
