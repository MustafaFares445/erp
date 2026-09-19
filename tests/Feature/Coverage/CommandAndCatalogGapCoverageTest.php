<?php

declare(strict_types=1);

use App\Enums\QuotationStatus;
use App\Models\Concerns\ValidatesCurrencyCatalog;
use App\Models\InventoryMovement;
use App\Models\Quotation;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Services\Settings\CurrencyCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('expires lapsed sent quotations through the command', function (): void {
    $expired = Quotation::factory()->expired()->create();
    $future = Quotation::factory()->sent()->create(['expires_at' => today()->addDay()]);

    expect(Artisan::call('sales:quotations:expire'))->toBe(0)
        ->and($expired->refresh()->status)->toBe(QuotationStatus::Expired)
        ->and($future->refresh()->status)->toBe(QuotationStatus::Sent);
});

it('validates and normalizes currency catalog fields', function (): void {
    $service = app(CurrencyCatalogService::class);
    expect($service->activeOptions())->not->toBeEmpty()
        ->and($service->defaultCode())->not->toBe('')
        ->and($service->normalizeActive(' aed '))->toBe('AED');

    $model = new class extends Model
    {
        use ValidatesCurrencyCatalog;

        protected $guarded = [];

        public function validateCurrency(string $field): void
        {
            $this->validateActiveCurrency($field);
        }
    };

    $model->forceFill(['currency' => ' aed ']);
    $model->validateCurrency('currency');

    expect($model->getAttribute('currency'))->toBe('AED');

    $model->syncOriginalAttribute('currency');
    $model->validateCurrency('currency');
    $model->setAttribute('currency', null);
    $model->validateCurrency('currency');

    $model->setAttribute('currency', 'ZZZ');

    expect(fn () => $model->validateCurrency('currency'))
        ->toThrow(ValidationException::class);
});

it('counts serialized confirmed shipment provenance in warranty backfill dry run', function (): void {
    $shipment = Shipment::factory()->arrived()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'warehouse_id' => $shipment->warehouse_id,
    ]);

    InventoryMovement::factory()->create([
        'product_variant_id' => $unit->product_variant_id,
        'warehouse_id' => $shipment->warehouse_id,
        'source_type' => 'inventory_operation',
        'source_id' => $shipment->inventory_operation_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    expect(Artisan::call('support:warranties:backfill', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('eligible serialized movements', 'dry-run');
});
