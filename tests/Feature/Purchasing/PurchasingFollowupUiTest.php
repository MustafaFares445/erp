<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductSupport;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

/** @param list<mixed> $arguments */
function supplierConfirmationResourceInvoke(string $method, array $arguments): mixed
{
    return new ReflectionMethod(SupplierConfirmationResource::class, $method)
        ->invokeArgs(null, $arguments);
}

it('organizes Vendors navigation around overview planning suppliers and catalog sections', function (): void {
    /** @var array{sections:list<array{key:string,label:string}>,items:list<array{label:string,link:string,section:string}>} $group */
    $group = collect(AdminModuleRegistry::groups())->firstWhere('key', 'vendors');

    expect(collect($group['sections'])->pluck('key')->all())
        ->toBe(['overview', 'planning', 'suppliers', 'catalog', 'setup'])
        ->and(collect($group['items'])->pluck('section')->unique()->values()->all())
        ->toBe(['overview', 'planning', 'suppliers', 'catalog', 'setup']);
});

it('renders the Supplier Capability Matrix as separate Purchasing master data', function (): void {
    SupplierProductSupport::factory()->count(2)->create();

    Livewire::test(ManageSupplierProductSupports::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(SupplierProductSupport::query()->get());
});

it('projects supplier confirmation next actions across the evidence lifecycle', function (): void {
    $unsent = PurchaseOrder::factory()->accepted()->create();
    $pending = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $unsent->getKey(),
        'supplier_id' => $unsent->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    expect(supplierConfirmationResourceInvoke('nextAction', [$pending->load('purchaseOrder')]))
        ->toBe('Send Purchase Order to supplier');

    $unsent->forceFill(['sent_at' => now()])->save();

    expect(supplierConfirmationResourceInvoke('nextAction', [$pending->refresh()->load('purchaseOrder')]))
        ->toBe('Record supplier response');

    foreach ([
        SupplierConfirmationStatus::Partial->value => 'Follow up backordered quantity',
        SupplierConfirmationStatus::Confirmed->value => 'Monitor inbound receiving',
        SupplierConfirmationStatus::Rejected->value => 'Resolve supplier exception',
    ] as $status => $expected) {
        $confirmation = SupplierConfirmation::factory()->create([
            'purchase_order_id' => $unsent->getKey(),
            'supplier_id' => $unsent->supplier_id,
            'confirmation_status' => $status,
        ]);

        expect(supplierConfirmationResourceInvoke('nextAction', [$confirmation->load('purchaseOrder')]))
            ->toBe($expected);
    }
});

it('marks an answered supplier promise overdue only while its Purchase Order remains active', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create(['sent_at' => now()->subWeek()]);
    $confirmation = SupplierConfirmation::factory()->confirmed()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
        'promised_at' => today()->subDay(),
    ]);

    expect(supplierConfirmationResourceInvoke('isOverdue', [$confirmation->load('purchaseOrder')]))
        ->toBeTrue();

    $confirmation->forceFill(['promised_at' => today()->addDay()])->save();

    expect(supplierConfirmationResourceInvoke('isOverdue', [$confirmation->refresh()->load('purchaseOrder')]))
        ->toBeFalse();

    $confirmation->forceFill(['promised_at' => today()->subDay()])->save();
    $order->forceFill(['status' => PurchaseOrderStatus::Received])->save();

    expect(supplierConfirmationResourceInvoke('isOverdue', [$confirmation->refresh()->load('purchaseOrder')]))
        ->toBeFalse();
});
