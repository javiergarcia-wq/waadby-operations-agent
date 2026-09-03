<?php

namespace Tests;

use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use Waadby\OperationsAgent\OperationsAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tests.installation_id' => (string) Str::uuid(),
            'waadby_operations.application.code' => 'waadby-billing',
            'waadby_operations.application.environment' => 'testing',
            'waadby_operations.remote_agent.enabled' => true,
            'waadby_operations.remote_agent.allow_local_testing_http' => true,
            'waadby_operations.remote_agent.state_path' => storage_path('framework/testing/operations-agent-'.Str::uuid()),
            'waadby_operations.remote_agent.replay_store' => 'array',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [OperationsAgentServiceProvider::class];
    }
}
