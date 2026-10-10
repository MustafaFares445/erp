<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchaseRfqStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\RelationManagers\CollaborationEntriesRelationManager;
use App\Filament\Resources\PurchaseAgreements\Pages\CreatePurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\Pages\ViewPurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseRfqs\Pages\ViewPurchaseRfq;
use App\Filament\Resources\SupplierConfirmations\Pages\ViewSupplierConfirmation;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Filament\Resources\SupplierProductSupports\SupplierProductSupportResource;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

/**
 * Switches to a throw-away locale whose only translations are the given keys, each rendered as "QA <key>",
 * so a test proves the copy is routed through the translator without depending on the shipped Arabic file.
 *
 * @param  list<string>  $keys
 */
function useSourcingProbeLocale(array $keys): void
{
    $lines = [];

    foreach ($keys as $key) {
        $lines['*.'.$key] = 'QA '.$key;
    }

    Lang::addLines($lines, 'qa');
    app()->setLocale('qa');
}

/** @return array{0:Supplier,1:ProductVariant,2:Unit} */
function sourcingSurfaceCatalog(): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();

    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);

    return [$supplier, $variant, $unit];
}

function sentSourcingRfq(User $actor): PurchaseRfq
{
    [$supplier, $variant, $unit] = sourcingSurfaceCatalog();
    $service = app(PurchaseRfqService::class);

    $rfq = $service->create($actor, ['currency_code' => 'AED'], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '100',
    ]], [$supplier->getKey()]);

    return $service->send($actor, $rfq);
}

function sourcingSurfaceAgreement(User $actor): PurchaseAgreement
{
    [$supplier, $variant, $unit] = sourcingSurfaceCatalog();

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    return app(PurchaseAgreementService::class)->create($actor, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
    ], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'unit_price' => '175.00',
        'minimum_order_quantity' => '10',
        'lead_time_days' => 5,
    ]]);
}

it('gives sourcing statuses semantic badge colors instead of one shared color', function (): void {
    // Release QA 2026-10-10: Cancelled/Expired RFQ and agreement badges were blue like Draft/Awarded.
    expect(PurchaseRfqStatus::Cancelled->getColor())->toBe('danger')
        ->and(PurchaseRfqStatus::Expired->getColor())->toBe('gray')
        ->and(PurchaseRfqStatus::Awarded->getColor())->toBe('success')
        ->and(PurchaseAgreementStatus::Cancelled->getColor())->toBe('danger')
        ->and(PurchaseAgreementStatus::Expired->getColor())->toBe('gray')
        ->and(PurchaseAgreementStatus::Active->getColor())->toBe('success');
});

it('labels and colors supplier confirmation responses for Filament badges', function (): void {
    // Release QA 2026-10-10: the confirmation Response badge showed the raw enum value "confirmed".
    expect(SupplierConfirmationStatus::Confirmed)->toBeInstanceOf(HasLabel::class)
        ->and(SupplierConfirmationStatus::Confirmed)->toBeInstanceOf(HasColor::class)
        ->and(SupplierConfirmationStatus::Confirmed->getLabel())->toBe('Confirmed')
        ->and(SupplierConfirmationStatus::Rejected->getColor())->toBe('danger');
});

it('shows translated RFQ supplier status and trimmed quantities and hides empty response lines', function (): void {
    // Release QA 2026-10-10: raw "pending" badge, "100.000000" quantity and empty "Response lines" headings.
    $rfq = sentSourcingRfq($this->manager);

    Livewire::test(ViewPurchaseRfq::class, ['record' => $rfq->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Pending')
        ->assertSee('Responded at')
        ->assertDontSee('100.000000')
        ->assertDontSee('Response lines');
});

it('lists recorded RFQ response lines with formatted quantities and the RFQ currency', function (): void {
    // Release QA 2026-10-10: response lines render once a supplier has quoted.
    $rfq = sentSourcingRfq($this->manager);
    $candidate = $rfq->suppliers()->firstOrFail();

    app(PurchaseRfqService::class)->recordResponse($this->manager, $candidate, [[
        'rfq_line_id' => $rfq->lines()->firstOrFail()->getKey(),
        'unit_price' => '12.50',
        'offered_quantity' => '100',
        'lead_time_days' => 4,
        'minimum_order_quantity' => '25',
    ]]);

    Livewire::test(ViewPurchaseRfq::class, ['record' => $rfq->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Response lines')
        ->assertSee('Responded')
        ->assertDontSee('25.000000')
        ->assertSee('AED');
});

it('routes the RFQ and agreement page copy through the translator', function (): void {
    // Release QA 2026-10-10: RFQ/agreement titles and the agreement model label stayed English in Arabic.
    $rfq = sentSourcingRfq($this->manager);
    $agreement = sourcingSurfaceAgreement($this->manager);

    useSourcingProbeLocale(['RFQ :number', 'Agreement :number', 'Purchase agreement', 'Purchase agreements']);

    expect(PurchaseAgreementResource::getModelLabel())->toBe('QA Purchase agreement')
        ->and(PurchaseAgreementResource::getPluralModelLabel())->toBe('QA Purchase agreements');

    Livewire::test(ViewPurchaseRfq::class, ['record' => $rfq->getRouteKey()])
        ->assertSee('QA RFQ '.$rfq->rfq_number);

    Livewire::test(ViewPurchaseAgreement::class, ['record' => $agreement->getRouteKey()])
        ->assertSee('QA Agreement '.$agreement->agreement_number);
});

it('labels the agreement form fields and trims agreement minimum order quantities', function (): void {
    // Release QA 2026-10-10: "Supplier id" / "Lead time days" labels and "10.000000" MOQ on the agreement.
    $agreement = sourcingSurfaceAgreement($this->manager);

    Livewire::test(CreatePurchaseAgreement::class)
        ->assertSuccessful()
        ->assertDontSee('Supplier id')
        ->assertDontSee('Lead time days')
        ->assertSee('Lead time (days)');

    Livewire::test(ViewPurchaseAgreement::class, ['record' => $agreement->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Minimum order quantity')
        ->assertDontSee('10.000000');
});

it('shows the supplier product name for variant-specific capabilities under one capability name', function (): void {
    // Release QA 2026-10-10: Product column showed "Derived from variant" and the page title read "Supplier Product Supports".
    $support = SupplierProductSupport::factory()->create(['product_id' => null]);
    $productName = $support->productVariant->product->name;

    Livewire::test(ManageSupplierProductSupports::class)
        ->assertSuccessful()
        ->assertSee('Supplier Capability Matrix')
        ->assertDontSee('Derived from variant')
        ->assertTableColumnStateSet('product_name', $productName, $support)
        ->searchTable($productName)
        ->assertCanSeeTableRecords([$support]);

    expect(SupplierProductSupportResource::getPluralModelLabel())->toBe('Supplier Capabilities');
});

it('routes capability scope and supplier catalog availability copy through the translator', function (): void {
    // Release QA 2026-10-10: "Variant-specific" scope and "Active" availability badges stayed English in Arabic.
    $support = SupplierProductSupport::factory()->create(['product_id' => null]);
    $reference = SupplierProductReference::factory()->create([
        'availability_status' => 'temporarily_unavailable',
        'pack_size' => '12.000000',
    ]);

    useSourcingProbeLocale(['Variant-specific', 'Temporarily unavailable']);

    Livewire::test(ManageSupplierProductSupports::class)
        ->assertTableColumnFormattedStateSet('scope', 'QA Variant-specific', $support);

    Livewire::test(ManageSupplierProductReferences::class)
        ->assertTableColumnFormattedStateSet('availability_status', 'QA Temporarily unavailable', $reference)
        ->assertTableColumnFormattedStateSet('pack_size', '12', $reference);
});

it('translates the supplier 360 header and profile badges and drops the generated preview headings', function (): void {
    // Release QA 2026-10-10: subtitle, Active/Not required badges and preview headings were untranslated.
    $supplier = Supplier::factory()->create(['is_active' => true, 'requires_confirmation' => false]);
    SupplierProductSupport::factory()->create(['supplier_id' => $supplier->getKey(), 'product_id' => null]);

    Livewire::test(ViewSupplier::class, ['record' => $supplier->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Confirmation not required')
        ->assertDontSee('Active product supports preview')
        ->assertDontSee('Active product references preview');

    useSourcingProbeLocale(['Active', 'Confirmation not required', 'Variant-specific']);

    Livewire::test(ViewSupplier::class, ['record' => $supplier->getRouteKey()])
        ->assertSee($supplier->code.' · QA Active · QA Confirmation not required')
        ->assertSee('QA Variant-specific');
});

it('translates the supplier confirmation page heading', function (): void {
    // Release QA 2026-10-10: heading "Supplier Confirmation · PO-000003" stayed English in Arabic.
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    useSourcingProbeLocale(['Supplier Confirmation · :number']);

    Livewire::test(ViewSupplierConfirmation::class, ['record' => $confirmation->getRouteKey()])
        ->assertSee('QA Supplier Confirmation · '.$order->purchase_order_number);
});

it('translates the shared collaboration relation manager heading and empty state', function (): void {
    // Release QA 2026-10-10: "Collaboration Entries" heading and "collaboration entries" empty state were untranslated.
    $order = PurchaseOrder::factory()->create();

    useSourcingProbeLocale(['Collaboration Entries', 'No collaboration entries']);

    Livewire::test(CollaborationEntriesRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditPurchaseOrder::class,
    ])
        ->assertSuccessful()
        ->assertSee('QA Collaboration Entries')
        ->assertSee('QA No collaboration entries');
});

it('renders a compact translated purchase demand queue with a readable lead time', function (): void {
    // Release QA 2026-10-10: the 17-column queue overflowed, its copy was hard-coded English and read "3 day lead time".
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'lead_time_days' => 3,
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    Livewire::test(PurchaseNeeds::class)
        ->assertSuccessful()
        ->assertSee('Lead time: 3 days')
        ->assertSee('Stock position')
        ->assertDontSee('Suggested Qty');

    useSourcingProbeLocale(['Needs sourcing', 'Purchase demand queue', 'Find a need', 'Inventory Replenishment', 'Create PO draft']);

    Livewire::test(PurchaseNeeds::class)
        ->assertSee('QA Needs sourcing')
        ->assertSee('QA Purchase demand queue')
        ->assertSee('QA Find a need')
        ->assertSee('QA Inventory Replenishment')
        ->assertSee('QA Create PO draft');
});
