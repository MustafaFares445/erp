<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\SerializedInventoryUnits\Schemas\SerializedInventoryUnitInfolist;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PriceFloorOverride;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage93Method(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

it('covers invoice pricing floor labels including pending and approved overrides', function (): void {
    $isBelow = coverage93Method(InvoiceInfolist::class, 'isBelowFloor');
    $label = coverage93Method(InvoiceInfolist::class, 'pricingStatusLabel');

    $noFloor = new InvoiceLine;
    $noFloor->forceFill([
        'unit_price' => '80.00',
        'floor_price_minor' => null,
    ]);

    expect($isBelow->invoke(null, $noFloor))->toBeFalse()
        ->and($label->invoke(null, $noFloor))->toBe('Not applicable');

    $within = new InvoiceLine;
    $within->forceFill([
        'unit_price' => '80.00',
        'floor_price_minor' => 7500,
    ]);

    expect($isBelow->invoke(null, $within))->toBeFalse()
        ->and($label->invoke(null, $within))->toBe('Within allowed pricing range');

    $below = new InvoiceLine;
    $below->forceFill([
        'unit_price' => '70.00',
        'floor_price_minor' => 7500,
    ]);
    $below->setRelation('priceFloorOverride', null);

    expect($isBelow->invoke(null, $below))->toBeTrue()
        ->and($label->invoke(null, $below))->toBe('Approved exception (pending approval record)');

    $approver = User::factory()->create(['name' => 'Coverage Approver']);
    $override = new PriceFloorOverride;
    $override->setRelation('approvedBy', $approver);

    $below->setRelation('priceFloorOverride', $override);

    expect($label->invoke(null, $below))->toBe('Approved exception (override by Coverage Approver)');
});

it('covers credit-note draft reversed and confirmed banner variants', function (): void {
    $banner = coverage93Method(CreditNoteInfolist::class, 'bannerMeta');

    $draft = new CreditNote;
    $draft->forceFill([
        'status' => CreditNoteStatus::Draft,
        'grand_total' => '25.00',
        'confirmed_at' => null,
        'reversed_at' => null,
    ]);
    expect($banner->invoke(null, $draft)['status'])->toBe('info');

    $reversed = new CreditNote;
    $reversed->forceFill([
        'status' => CreditNoteStatus::Reversed,
        'grand_total' => '25.00',
        'confirmed_at' => now(),
        'reversed_at' => now(),
    ]);
    expect($banner->invoke(null, $reversed)['status'])->toBe('warning');

    $confirmedWithoutInvoice = new CreditNote;
    $confirmedWithoutInvoice->forceFill([
        'status' => CreditNoteStatus::Confirmed,
        'grand_total' => '25.00',
        'confirmed_at' => now(),
        'reversed_at' => null,
    ]);
    $confirmedWithoutInvoice->setRelation('invoice', null);

    $accountBanner = $banner->invoke(null, $confirmedWithoutInvoice);
    expect($accountBanner['status'])->toBe('success')
        ->and($accountBanner['description'])->toContain('25');

    $confirmedWithInvoice = clone $confirmedWithoutInvoice;
    $confirmedWithInvoice->setRelation('invoice', new Invoice);

    $invoiceBanner = $banner->invoke(null, $confirmedWithInvoice);

    expect($invoiceBanner['status'])->toBe('success')
        ->and($invoiceBanner['description'])->toContain('25');
});

it('covers serialized-unit legacy warranty and explicit entitlement coverage summaries', function (): void {
    $legacyState = coverage93Method(SerializedInventoryUnitInfolist::class, 'legacyWarrantyState');
    $coverageRules = coverage93Method(SerializedInventoryUnitInfolist::class, 'coverageRules');

    $none = SerializedInventoryUnit::factory()->create(['warranty_expires_on' => null]);
    expect($legacyState->invoke(null, $none))->toBe('No entitlement / needs verification')
        ->and($coverageRules->invoke(null, $none))->toBe('No warranty coverage rules are available.');

    $legacyActive = SerializedInventoryUnit::factory()->create(['warranty_expires_on' => today()->addDay()]);
    expect($legacyState->invoke(null, $legacyActive))->toBe('Active (legacy)')
        ->and($coverageRules->invoke(null, $legacyActive))->toBe('Legacy warranty: parts and labour require claim assessment.');

    $legacyExpired = SerializedInventoryUnit::factory()->create(['warranty_expires_on' => today()->subDay()]);
    expect($legacyState->invoke(null, $legacyExpired))->toBe('Expired (legacy)');

    $coveredUnit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $coveredUnit->id,
        'covers_parts' => true,
        'covers_labour' => true,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => true,
    ]);

    expect($coverageRules->invoke(null, $coveredUnit->refresh()))
        ->toContain('Parts', 'Labour', 'Third-party services');

    $uncoveredUnit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $uncoveredUnit->id,
        'covers_parts' => false,
        'covers_labour' => false,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => false,
    ]);

    expect($coverageRules->invoke(null, $uncoveredUnit->refresh()))
        ->toBe('No default charge categories are covered.');
});
