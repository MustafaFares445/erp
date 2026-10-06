<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\PurchaseAgreementStatus;
use App\Filament\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Resources\PurchaseAgreements\Pages\CreatePurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\Pages\ViewPurchaseAgreement;
use App\Filament\Resources\PurchaseRfqs\Pages\CreatePurchaseRfq;
use App\Models\ChartAccount;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseAgreement;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverage98InvokeCreate(string $pageClass, array $data): mixed
{
    $page = new ReflectionClass($pageClass)->newInstanceWithoutConstructor();

    return new ReflectionMethod($pageClass, 'handleRecordCreation')->invoke($page, $data);
}

/** @return array{0: Supplier, 1: ProductVariant, 2: Unit} */
function coverage98PurchasingCatalog(): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();

    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->id,
        'product_variant_id' => $variant->id,
        'purchase_cost' => '12.00',
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    return [$supplier, $variant, $unit];
}

it('covers bank-statement create-page form adaptation including ignored invalid rows', function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $bank = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'is_active' => true,
        'is_postable' => true,
    ]);
    $method = PaymentMethod::factory()->create([
        'chart_account_id' => $bank->id,
        'is_active' => true,
    ]);

    $statement = coverage98InvokeCreate(CreateBankStatement::class, [
        'payment_method_id' => (string) $method->id,
        'currency_code' => 'AED',
        'period_start' => today()->toDateString(),
        'period_end' => today()->toDateString(),
        'opening_balance' => 10,
        'closing_balance' => 15,
        'rows' => [
            'ignored-row',
            [
                'transaction_date' => today()->toDateString(),
                'amount' => 5,
                'reference' => ' COV-98 ',
                'counterparty' => null,
                'description' => '',
            ],
        ],
    ]);

    expect($statement->lines)->toHaveCount(1)
        ->and($statement->lines->first()->amount)->toBe('5.00')
        ->and($statement->lines->first()->reference)->toBe('COV-98');
});

it('covers purchase-agreement and RFQ create-page adapters including line and supplier normalization', function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    [$supplier, $variant, $unit] = coverage98PurchasingCatalog();

    $agreement = coverage98InvokeCreate(CreatePurchaseAgreement::class, [
        'supplier_id' => (string) $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
        'ends_on' => '',
        'notes' => '',
        'lines' => [
            'ignored-line',
            [
                'product_variant_id' => (string) $variant->id,
                'unit_id' => (string) $unit->id,
                'unit_price' => 42,
                'minimum_order_quantity' => '',
                'lead_time_days' => '3',
            ],
        ],
    ]);

    expect($agreement)->toBeInstanceOf(PurchaseAgreement::class)
        ->and($agreement->lines)->toHaveCount(1)
        ->and((float) $agreement->lines->first()->unit_price)->toBe(42.0)
        ->and($agreement->lines->first()->lead_time_days)->toBe(3);

    $rfq = coverage98InvokeCreate(CreatePurchaseRfq::class, [
        'currency_code' => 'AED',
        'needed_by' => '',
        'closes_at' => '',
        'notes' => '',
        'lines' => [
            123,
            [
                'product_variant_id' => (string) $variant->id,
                'unit_id' => (string) $unit->id,
                'quantity' => 2,
                'notes' => '',
            ],
        ],
        'supplier_ids' => ['not-numeric', (string) $supplier->id, $supplier->id],
    ]);

    expect($rfq->lines)->toHaveCount(1)
        ->and($rfq->suppliers)->toHaveCount(1)
        ->and($rfq->suppliers->first()->supplier_id)->toBe($supplier->id);
});

it('covers purchase-agreement view activate expire and cancel actions', function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();

    $actor = User::factory()->admin()->create();
    [$supplier, $variant, $unit] = coverage98PurchasingCatalog();

    $createAgreement = (static fn (?string $endsOn = null): PurchaseAgreement => app(PurchaseAgreementService::class)->create($actor, [
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->toDateString(),
        'ends_on' => $endsOn,
    ], [[
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => '33.00',
    ]]));

    $draft = $createAgreement(today()->addDay()->toDateString());

    Livewire::actingAs($actor)
        ->test(ViewPurchaseAgreement::class, ['record' => $draft->id])
        ->callAction('activate');

    expect($draft->refresh()->status)->toBe(PurchaseAgreementStatus::Active);

    $draft->forceFill(['ends_on' => today()->subDay()])->saveQuietly();

    Livewire::actingAs($actor)
        ->test(ViewPurchaseAgreement::class, ['record' => $draft->id])
        ->callAction('expire');

    expect($draft->refresh()->status)->toBe(PurchaseAgreementStatus::Expired);

    $cancelled = $createAgreement();

    Livewire::actingAs($actor)
        ->test(ViewPurchaseAgreement::class, ['record' => $cancelled->id])
        ->callAction('cancel');

    expect($cancelled->refresh()->status)->toBe(PurchaseAgreementStatus::Cancelled);
});
