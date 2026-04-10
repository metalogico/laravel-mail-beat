<?php

namespace Metalogico\MailBeat\Tests;

use Metalogico\MailBeat\MailBeatServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MailBeatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.name', 'TestApp');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('mail-beat.endpoint', 'http://collector:4318');
        $app['config']->set('mail-beat.timeout', 2);
        $app['config']->set('mail-beat.queue.connection', null);
        $app['config']->set('mail-beat.queue.name', 'default');
    }
}
