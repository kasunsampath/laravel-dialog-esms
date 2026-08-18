<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Tests;

use KasunSampath\DialogEsms\DialogEsmsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [DialogEsmsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('dialog-esms.api_key', 'test-key');
        $app['config']->set('dialog-esms.sender_id', 'TESTAPP');
        $app['config']->set('dialog-esms.base_url', 'https://e-sms.dialog.lk/api/v1/message-via-url');
        $app['config']->set('dialog-esms.push_url', null);
        $app['config']->set('dialog-esms.retries', 0);
        $app['config']->set('dialog-esms.retry_delay', 0);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
