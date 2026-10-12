<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = config('database.connections.'.config('database.default'));
        if ($connection['driver'] === 'pgsql' && ! str_ends_with((string) $connection['database'], '_test')) {
            throw new \LogicException('Tests require a dedicated PostgreSQL database ending in _test.');
        }

        return $app;
    }
}
