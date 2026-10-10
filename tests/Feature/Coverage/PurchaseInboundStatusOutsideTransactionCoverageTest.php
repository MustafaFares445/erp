<?php

declare(strict_types=1);

use App\Enums\PurchaseInboundStatus;
use App\Models\PurchaseInbound;
use App\Services\Purchasing\PurchaseInboundStatusService;
use App\Support\Database\DisposableDatabaseGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(DisposableDatabaseGuard::class)->assertDisposable(DB::connection());
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('synchronizes a Purchase Inbound through its own database transaction', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $inbound = PurchaseInbound::factory()->create([
        'status' => PurchaseInboundStatus::AwaitingAllocation,
    ]);

    $synchronized = app(PurchaseInboundStatusService::class)->synchronize($inbound);

    expect($synchronized->status)->toBe(PurchaseInboundStatus::AwaitingAllocation)
        ->and(DB::transactionLevel())->toBe(0);
});
