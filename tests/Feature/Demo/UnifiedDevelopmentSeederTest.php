<?php

declare(strict_types=1);

use Database\Seeders\Demo\UnifiedDevelopmentSeeder;
use Database\Seeders\Demo\UnifiedMasterDataSeeder;
use Database\Seeders\Demo\UnifiedSalesHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to start any unified demo in production', function (): void {
    $this->app['env'] = 'production';

    expect(fn () => app(UnifiedDevelopmentSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(fn () => app(UnifiedMasterDataSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(fn () => app(UnifiedSalesHistorySeeder::class)->run())->toThrow(RuntimeException::class);
});

it('exposes exactly one guarded unified demo CLI workflow and accepts a dry run', function (): void {
    $this->artisan('demo:seed', ['--dry-run' => true, '--anchor' => '2026-10-10'])
        ->assertSuccessful();

    expect(DB::table('customer_profiles')->count())->toBe(0);
});

it('rejects an invalid anchor without modifying business records', function (): void {
    $this->artisan('demo:seed', ['--dry-run' => true, '--anchor' => '2026-13-40'])
        ->assertFailed();

    expect(DB::table('customer_profiles')->count())->toBe(0);
});

it('uses a strict nonzero verification exit when data is missing', function (): void {
    $this->artisan('demo:seed', ['--verify-only' => true, '--anchor' => '2026-10-10'])
        ->assertFailed();
});
