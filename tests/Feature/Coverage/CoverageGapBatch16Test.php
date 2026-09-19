<?php

declare(strict_types=1);

use App\Filament\Resources\FiscalPeriods\Pages\CreateFiscalPeriod;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers purchase-order create auth and malformed line normalization branches', function (): void {
    $page = new ReflectionClass(CreatePurchaseOrder::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CreatePurchaseOrder::class, 'handleRecordCreation');

    auth()->logout();
    expect(fn (): mixed => $method->invoke($page, []))
        ->toThrow(Halt::class);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $service = new class
    {
        /** @var list<array<string,mixed>> */
        public array $seenLines = [];

        public function createDraftWithLines(User $actor, array $data, array $lines): PurchaseOrder
        {
            $this->seenLines = $lines;

            return new PurchaseOrder;
        }
    };
    app()->instance(PurchaseOrderService::class, $service);

    $method->invoke($page, [
        'supplier_id' => 1,
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'lines' => 'not-an-array',
    ]);
    expect($service->seenLines)->toBe([]);

    $method->invoke($page, [
        'supplier_id' => 1,
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'lines' => [
            'not-an-array-line',
            [
                'product_variant_id' => '5',
                'unit_id' => '7',
                'quantity_ordered' => '2.000000',
                'unit_cost' => '12.50',
            ],
        ],
    ]);

    expect($service->seenLines)->toHaveCount(1)
        ->and($service->seenLines[0]['product_variant_id'])->toBe(5)
        ->and($service->seenLines[0]['unit_id'])->toBe(7);
});

it('covers fiscal-period and journal-entry create unauthenticated guards', function (): void {
    auth()->logout();

    $fiscalPage = new ReflectionClass(CreateFiscalPeriod::class)->newInstanceWithoutConstructor();
    $fiscalCreate = new ReflectionMethod(CreateFiscalPeriod::class, 'handleRecordCreation');

    expect(fn (): mixed => $fiscalCreate->invoke($fiscalPage, []))
        ->toThrow(Halt::class);

    $journalPage = new ReflectionClass(CreateJournalEntry::class)->newInstanceWithoutConstructor();
    $journalCreate = new ReflectionMethod(CreateJournalEntry::class, 'handleRecordCreation');

    expect(fn (): mixed => $journalCreate->invoke($journalPage, []))
        ->toThrow(Halt::class);
});

it('covers edit-product unit synchronization branch', function (): void {
    $product = Product::factory()->create();
    $unitA = Unit::factory()->create();
    $unitB = Unit::factory()->create();

    $page = new ReflectionClass(EditProduct::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(EditProduct::class, 'handleRecordUpdate');

    $updated = $method->invoke($page, $product, [
        'name' => $product->name,
        'images' => [],
        'unit_ids' => [$unitA->getKey(), $unitB->getKey()],
        'default_unit_id' => $unitB->getKey(),
    ]);

    expect($updated)->toBe($product)
        ->and($product->units()->count())->toBe(2)
        ->and((int) $product->units()->wherePivot('is_default', true)->firstOrFail()->getKey())
        ->toBe($unitB->getKey());
});
