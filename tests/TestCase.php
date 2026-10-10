<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use slash197\Reges\Laravel\RegesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function app(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [RegesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('reges.client_id', 'reges-api');
        $app['config']->set('reges.client_secret', 'secret');
        $app['config']->set('reges.username', 'key-user');
        $app['config']->set('reges.password', 'key-password');
    }
}
