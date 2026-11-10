<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Feature\QaDemoLiveTest;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        $memory = $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $liveDemo = static::class === QaDemoLiveTest::class && getenv('SMARTBIZ_QA_LIVE') === '1' && $connection->getDriverName() === 'mysql' && $connection->getDatabaseName() === 'smartbiz_demo' && in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true);
        if (! $memory && ! $liveDemo) {
            throw new RuntimeException('Database safety: normal tests require SQLite :memory:. Only the opt-in transactional QaDemoLiveTest may use smartbiz_demo.');
        }

        return $app;
    }
}
