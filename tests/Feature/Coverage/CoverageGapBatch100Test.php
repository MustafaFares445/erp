<?php

declare(strict_types=1);

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\PaymentStatus;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Http\Resources\Api\Customer\CustomerEquipmentResource;
use App\Models\CreditNoteLine;
use App\Models\EquipmentInstallation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductVariant;
use App\Models\TaxRecognitionEntry;
use App\Models\Unit;
use App\Models\WarrantyEntitlement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers every customer equipment installation status branch', function (): void {
    $method = new ReflectionMethod(CustomerEquipmentResource::class, 'installationStatus');

    $installation = new EquipmentInstallation;

    $installation->customer_acceptance_status = CustomerAcceptanceStatus::Rejected;
    expect($method->invoke(null, $installation))->toBe('rejected');

    $installation->customer_acceptance_status = CustomerAcceptanceStatus::Accepted;
    expect($method->invoke(null, $installation))->toBe('accepted');

    $installation->customer_acceptance_status = CustomerAcceptanceStatus::Pending;
    $installation->commissioning_status = CommissioningStatus::Failed;
    expect($method->invoke(null, $installation))->toBe('commissioning_failed');

    $installation->commissioning_status = CommissioningStatus::Passed;
    expect($method->invoke(null, $installation))->toBe('commissioned');

    $installation->commissioning_status = CommissioningStatus::Pending;
    $installation->installed_at = null;
    expect($method->invoke(null, $installation))->toBe('pending');

    $installation->installed_at = now();
    expect($method->invoke(null, $installation))->toBe('installed');
});

it('covers warranty entitlement active-window evaluation including default time and guards', function (): void {
    $entitlement = new WarrantyEntitlement;
    $entitlement->forceFill([
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addDay(),
    ]);

    expect($entitlement->isActiveAt())->toBeTrue()
        ->and($entitlement->isActiveAt(today()->subDays(2)))->toBeFalse()
        ->and($entitlement->isActiveAt(today()->addDays(2)))->toBeFalse();

    $entitlement->state = WarrantyEntitlementState::Ended;
    expect($entitlement->isActiveAt())->toBeFalse();

    $entitlement->state = WarrantyEntitlementState::Active;
    $entitlement->starts_on = null;
    expect($entitlement->isActiveAt())->toBeFalse();

    $entitlement->starts_on = today()->subDay();
    $entitlement->expires_on = null;
    expect($entitlement->isActiveAt())->toBeFalse();
});

it('covers posted fully-applied payment banner and recognized numeric tax total', function (): void {
    $banner = new ReflectionMethod(PaymentInfolist::class, 'bannerMeta');
    $taxTotal = new ReflectionMethod(PaymentInfolist::class, 'recognizedTaxTotal');

    $payment = new Payment;
    $payment->forceFill([
        'amount' => '100.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
        'reversed_at' => null,
    ]);

    $allocation = new PaymentAllocation;
    $allocation->forceFill(['amount' => '100.00']);
    $payment->setRelation('allocations', new EloquentCollection([$allocation]));

    $meta = $banner->invoke(null, $payment);
    expect($meta['status'])->toBe('success')
        ->and($meta['heading'])->toBe(__('admin.sales.payment_ui.posted_heading'))
        ->and($meta['description'])->toContain('100');

    $numeric = new TaxRecognitionEntry;
    $numeric->forceFill(['recognised_tax_amount' => '2.50']);
    $empty = new TaxRecognitionEntry;
    $empty->forceFill(['recognised_tax_amount' => null]);
    $payment->setRelation('taxRecognitionEntries', new EloquentCollection([$numeric, $empty]));

    expect($taxTotal->invoke(null, $payment))->toBe(2.5);
});

it('covers credit-note return-line source formatting with sku unit and fallback description', function (): void {
    $method = new ReflectionMethod(CreditNoteInfolist::class, 'lineSource');

    $return = new InventoryReturn;
    $return->forceFill(['return_number' => 'RET-COVERAGE-100']);

    $variant = new ProductVariant;
    $variant->forceFill(['sku' => 'SKU-COVERAGE-100']);

    $unit = new Unit;
    $unit->forceFill(['name' => 'Box']);

    $returnLine = new InventoryReturnLine;
    $returnLine->forceFill(['transaction_quantity' => '2.000000']);
    $returnLine->setRelation('inventoryReturn', $return);
    $returnLine->setRelation('productVariant', $variant);
    $returnLine->setRelation('transactionUnit', $unit);

    $line = new CreditNoteLine;
    $line->forceFill(['description' => 'Fallback product']);
    $line->setRelation('inventoryReturnLine', $returnLine);

    $formatted = $method->invoke(null, $line);
    expect($formatted)->toContain('RET-COVERAGE-100', 'SKU-COVERAGE-100', '2', 'Box');

    $variant->sku = null;
    $returnLine->setRelation('transactionUnit', null);
    $formattedFallback = $method->invoke(null, $line);

    expect($formattedFallback)->toContain('Fallback product', '2');
});

it('covers quotation integer normalization numeric branch', function (): void {
    $method = new ReflectionMethod(QuotationLinesRepeater::class, 'toInteger');

    expect($method->invoke(null, '42'))->toBe(42)
        ->and($method->invoke(null, 7))->toBe(7)
        ->and($method->invoke(null, 7.0))->toBeNull();
});
