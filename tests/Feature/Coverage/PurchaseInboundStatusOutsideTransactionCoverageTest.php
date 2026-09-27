<?php

declare(strict_types=1);

use App\Enums\PurchaseInboundStatus;
use App\Models\PurchaseInbound;
use App\Services\Purchasing\PurchaseInboundStatusService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

it('synchronizes a Purchase Inbound through its own database transaction', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $inbound = PurchaseInbound::factory()->create([
        'status' => PurchaseInboundStatus::AwaitingAllocation,
    ]);

    $synchronized = app(PurchaseInboundStatusService::class)->synchronize($inbound);

    expect($synchronized->status)->toBe(PurchaseInboundStatus::AwaitingAllocation)
        ->and(DB::transactionLevel())->toBe(0);
});
