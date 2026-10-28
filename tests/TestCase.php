<?php

namespace Packstub\Credits\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Credits\CreditsServiceProvider;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Tests\Fixtures\Models\Team;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Credits::allowancesUsing(null);
        Credits::metersUsing(null);
    }

    protected function getPackageProviders($app): array
    {
        return [CreditsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // DB_CONNECTION=pgsql, mysql or mariadb runs the suite on that database (DB_HOST, DB_DATABASE… as in an app), as
        // CI's database jobs do; anything else runs it on SQLite in memory.
        if (in_array($connection = env('DB_CONNECTION'), ['pgsql', 'mysql', 'mariadb'], true)) {
            $app['config']->set('database.default', $connection);
        } else {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]);
        }

        $app['config']->set('packstub-credits.meters', [
            'ai.input_tokens' => ['credits' => 1, 'per' => 1_000],
            'ai.output_tokens' => ['credits' => 5, 'per' => 1_000],
            'sms' => ['credits' => 2],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function team(string $name = 'Acme'): Team
    {
        return Team::query()->create(['name' => $name, 'plan' => 'pro']);
    }
}
