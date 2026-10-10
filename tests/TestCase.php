<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\OmnisTestHelpers;

abstract class TestCase extends BaseTestCase
{
    use OmnisTestHelpers;

    /**
     * Creates the application.
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Feature tests that hit the database should use RefreshDatabase.
     *
     * Schema requires PostgreSQL (triggers / plpgsql). Configure DB_* for
     * a dedicated test database when running the Feature suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Force dev auth driver for the test process.
        config(['omnis.auth.driver' => 'dev']);
    }
}
