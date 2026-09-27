<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests render Filament pages but do not need a compiled frontend
     * bundle. Disabling Vite keeps the PHP CI suite independent from npm build
     * artifacts while still exercising the server-rendered UI.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
