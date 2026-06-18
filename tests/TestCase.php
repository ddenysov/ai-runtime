<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->app->environment('testing')) {
            return;
        }

        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            $this->fail(
                "Refusing to run tests against {$default}://{$database}. "
                .'Tests must use sqlite :memory: (see phpunit.xml DB_* env with force="true").'
            );
        }
    }
}
