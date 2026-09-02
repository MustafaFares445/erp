<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\QuotationDecision;
use App\Models\CustomerProfile;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invariant I-1 (data-model.md §12), SC-007. A quotation never has a stock
 * relationship of any kind — no reservation, no movement, no on-hand change
 * — in any state it can reach, and no journal entry either.
 */
it('creates, prices, sends, and decides a quotation without touching stock or the ledger in any state', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    InventoryStock::factory()->for($variant)->create(['on_hand_quantity' => '10.000']);
    $onHandBefore = InventoryStock::query()->where('product_variant_id', $variant->getKey())->value('on_hand_quantity');

    $service = app(QuotationService::class);
    $recorder = User::factory()->create();

    $quotation = $service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 3, 'unit_price' => 100]],
    );

    $service->send($quotation);
    $service->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $recorder);

    expect(InventoryReservation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->where('product_variant_id', $variant->getKey())->value('on_hand_quantity'))->toBe($onHandBefore)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('touches no stock when a quotation is rejected or cancelled either', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $service = app(QuotationService::class);
    $recorder = User::factory()->create();

    $rejected = $service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    $service->send($rejected);
    $service->recordDecision($rejected, QuotationDecision::Rejected, CarbonImmutable::today(), null, $recorder);

    $cancelled = $service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    $cancelled->update(['status' => 'cancelled']);

    expect(InventoryReservation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});
