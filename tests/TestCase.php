<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Database\DisposableDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Refuse to run traits such as RefreshDatabase against a non-SQLite default connection
     * unless it is an allow-listed disposable schema (IERP_DISPOSABLE_DATABASES).
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    protected function setUpTraits(): array
    {
        $this->assertDefaultConnectionIsDisposable();

        return parent::setUpTraits();
    }

    protected function assertDefaultConnectionIsDisposable(): void
    {
        $defaultConnection = config('database.default');

        if (config("database.connections.{$defaultConnection}.driver") === 'sqlite') {
            return;
        }

        app(DisposableDatabaseGuard::class)->assertDisposable(DB::connection());
    }
}
