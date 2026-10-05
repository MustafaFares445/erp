<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\InventoryReportType;
use App\Enums\StockCondition;
use App\Models\ChartAccount;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\BankReconciliation\BankStatementImportService;
use App\Services\Inventory\InventoryReportService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();
});

function coverage115Method(bool $active = true, bool $postable = true): PaymentMethod
{
    $account = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'is_active' => true,
        'is_postable' => $postable,
    ]);

    return PaymentMethod::factory()->create([
        'chart_account_id' => $account->id,
        'is_active' => $active,
    ]);
}

function coverage115Attributes(PaymentMethod $method, string $start = '-2 days', string $end = '+2 days'): array
{
    return [
        'payment_method_id' => $method->id,
        'currency_code' => 'AED',
        'period_start' => today()->modify($start)->toDateString(),
        'period_end' => today()->modify($end)->toDateString(),
        'opening_balance' => '0.00',
        'closing_balance' => '0.00',
    ];
}

it('covers bank statement inactive payment-method and invalid period guards', function (): void {
    $actor = User::factory()->create();
    $service = app(BankStatementImportService::class);

    $inactive = coverage115Method(active: false);

    expect(fn () => $service->import(
        $actor,
        coverage115Attributes($inactive),
        [[
            'transaction_date' => today()->toDateString(),
            'amount' => '1.00',
        ]],
    ))->toThrow(DomainException::class, 'active payment method');

    $active = coverage115Method();

    expect(fn () => $service->import(
        $actor,
        coverage115Attributes($active, '+2 days', '-2 days'),
        [[
            'transaction_date' => today()->toDateString(),
            'amount' => '1.00',
        ]],
    ))->toThrow(DomainException::class, 'period end');
});

it('covers bank statement out-of-period and zero-amount row guards', function (): void {
    $actor = User::factory()->create();
    $method = coverage115Method();
    $service = app(BankStatementImportService::class);
    $attributes = coverage115Attributes($method);

    expect(fn () => $service->import($actor, $attributes, [[
        'transaction_date' => today()->addDays(5)->toDateString(),
        'amount' => '1.00',
    ]]))->toThrow(DomainException::class, 'inside the statement period');

    expect(fn () => $service->import($actor, $attributes, [[
        'transaction_date' => today()->toDateString(),
        'amount' => '0.00',
    ]]))->toThrow(DomainException::class, 'cannot be zero');
});

it('filters expiry-lot report rows by warehouse balance', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $lot = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $variant->id,
        'expires_at' => today()->addDay(),
    ]);

    (new InventoryLotBalance)->forceFill([
        'inventory_lot_id' => $lot->id,
        'warehouse_id' => $warehouse->id,
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '0.000000',
    ])->save();

    $service = app(InventoryReportService::class);

    expect($service->query(InventoryReportType::ExpiryLots, [
        'warehouse_id' => $warehouse->id,
    ])->pluck('id')->all())->toContain($lot->id)
        ->and($service->query(InventoryReportType::ExpiryLots, [
            'warehouse_id' => $otherWarehouse->id,
        ])->pluck('id')->all())->not->toContain($lot->id);
});
