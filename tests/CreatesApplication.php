<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->refuseToTouchARealDatabase($app);

        return $app;
    }

    /**
     * RefreshDatabase wipes the whole database. Tests may only run against
     * in-memory SQLite or a database whose name ends in "_test", never against
     * the development database from .env.
     */
    private function refuseToTouchARealDatabase(Application $app): void
    {
        $connection = $app['config']['database.default'];
        $database = (string) $app['config']["database.connections.{$connection}.database"];

        $safe = ($connection === 'sqlite' && $database === ':memory:') || str_ends_with($database, '_test');

        if (! $safe) {
            throw new RuntimeException(
                "Refusing to run tests against the '{$database}' database ({$connection}): they would erase it. "
                .'Use in-memory SQLite (see phpunit.xml) or a database whose name ends in _test.'
            );
        }
    }
}
