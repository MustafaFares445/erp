<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderNumberGenerator;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

function numberingBuyer(): User
{
    $user = User::factory()->create();
    $user->assignRole(DashboardRole::PurchasingManager->value);

    return $user;
}

function draftAttributes(): array
{
    return [
        'supplier_id' => Supplier::factory()->create()->getKey(),
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => now()->toDateString(),
    ];
}

it('assigns a readable sequential number a buyer can quote to a supplier', function (): void {
    $service = app(PurchaseOrderService::class);
    $buyer = numberingBuyer();

    $first = $service->createDraft($buyer, draftAttributes());
    $second = $service->createDraft($buyer, draftAttributes());

    expect($first->purchase_order_number)->toBe('PO-000001')
        ->and($second->purchase_order_number)->toBe('PO-000002');
});

it('never reissues a number belonging to a soft-deleted order (FR-011)', function (): void {
    // The unique index covers soft-deleted rows, and the generator reads through
    // them. A number quoted to a supplier and then archived must not come back
    // attached to a different commitment.
    $service = app(PurchaseOrderService::class);
    $buyer = numberingBuyer();

    $first = $service->createDraft($buyer, draftAttributes());
    $first->delete();

    $second = $service->createDraft($buyer, draftAttributes());

    expect($first->trashed())->toBeTrue()
        ->and($second->purchase_order_number)->toBe('PO-000002')
        ->and($second->purchase_order_number)->not->toBe($first->purchase_order_number);
});

it('refuses a duplicate number at the database, not only in the generator', function (): void {
    $existing = PurchaseOrder::factory()->create(['purchase_order_number' => 'PO-000001']);

    expect(fn (): PurchaseOrder => PurchaseOrder::factory()->create([
        'purchase_order_number' => $existing->purchase_order_number,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a number that duplicates a soft-deleted one', function (): void {
    $trashed = PurchaseOrder::factory()->create(['purchase_order_number' => 'PO-000042']);
    $trashed->delete();

    expect(fn (): PurchaseOrder => PurchaseOrder::factory()->create([
        'purchase_order_number' => 'PO-000042',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('continues the sequence from the highest existing number rather than the row count', function (): void {
    PurchaseOrder::factory()->create(['purchase_order_number' => 'PO-000009']);
    PurchaseOrder::factory()->create(['purchase_order_number' => 'PO-000010']);

    expect(app(PurchaseOrderNumberGenerator::class)->next())->toBe('PO-000011');
});

it('starts at one when no order exists yet', function (): void {
    expect(app(PurchaseOrderNumberGenerator::class)->next())->toBe('PO-000001');
});

it('gives every order a distinct number when several are created in one transaction', function (): void {
    // The in-transaction row lock is the ordering guarantee. SQLite serialises
    // writes, so this asserts the sequence property the lock exists to provide
    // rather than simulating true parallelism, which the test driver cannot do.
    $service = app(PurchaseOrderService::class);
    $buyer = numberingBuyer();

    $numbers = collect(range(1, 5))
        ->map(fn (): string => $service->createDraft($buyer, draftAttributes())->purchase_order_number);

    expect($numbers->unique())->toHaveCount(5)
        ->and($numbers->last())->toBe('PO-000005');
});
