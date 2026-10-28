<?php

namespace Packstub\Credits\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * The suite in `tests/Uuid` runs with `holder_key_type` = uuid, so `credit_wallets` is created with `uuidMorphs`.
 * The tables are migrated afresh when this case starts and once more when it ends, so the other suites, on a
 * database that persists between tests (Postgres, MySQL in CI), get the integer columns back.
 */
abstract class UuidTestCase extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$migrated = false;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('packstub-credits.holder_key_type', 'uuid');
    }
}
