<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'pgsql' || $app['config']->get('database.connections.pgsql.database') !== 'umss_testing') {
            throw new \RuntimeException('Las pruebas solo pueden ejecutarse en PostgreSQL/umss_testing.');
        }

        return $app;
    }
}
