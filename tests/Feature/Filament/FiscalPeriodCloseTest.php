<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\PeriodCloseCheck;
use App\Enums\StockCondition;
use App\Filament\Resources\FiscalPeriods\Pages\ViewFiscalPeriod;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\SalesSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\PeriodCloseChecklistService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
    ])->save();

    $this->period = FiscalPeriod::factory()->forMonth(CarbonImmutable::create(2026, 1, 1))->create();
});

/**
 * Arranges a period whose most recently persisted checklist snapshot shows a
 * failing mandatory check, by corrupting a lot balance and running the
 * checklist for real — the same mechanism used in PeriodCloseChecklistTest.
 */
function seedFailingChecklistSnapshot(FiscalPeriod $period): void
{
    $lot = InventoryLot::factory()->canonical()->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    app(PeriodCloseChecklistService::class)->run($period);
}

it('renders the failing checklist items inside the close modal', function (): void {
    seedFailingChecklistSnapshot($this->period);

    $chief = User::factory()->create();
    $chief->assignRole(DashboardRole::ChiefAccountant->value);

    Livewire::actingAs($chief)
        ->test(ViewFiscalPeriod::class, ['record' => $this->period->getKey()])
        ->assertActionEnabled('close')
        ->mountAction('close')
        ->assertMountedActionModalSee(PeriodCloseCheck::StockLedgerReconciles->label())
        ->assertMountedActionModalSee('FAIL');
});

it('disables the close button for an actor without the override permission when a mandatory check is failing', function (): void {
    seedFailingChecklistSnapshot($this->period);

    $accountant = User::factory()->create();
    $accountant->assignRole(DashboardRole::Reviewer->value);
    $accountant->givePermissionTo(AccountingPermission::FiscalPeriodClose->value);

    Livewire::actingAs($accountant)
        ->test(ViewFiscalPeriod::class, ['record' => $this->period->getKey()])
        ->assertActionDisabled('close');
});

it('keeps the close button enabled for an actor who holds the override permission', function (): void {
    seedFailingChecklistSnapshot($this->period);

    $chief = User::factory()->create();
    $chief->assignRole(DashboardRole::ChiefAccountant->value);

    Livewire::actingAs($chief)
        ->test(ViewFiscalPeriod::class, ['record' => $this->period->getKey()])
        ->assertActionEnabled('close');
});

it('enables the close button once the checklist passes cleanly', function (): void {
    app(PeriodCloseChecklistService::class)->run($this->period);

    $accountant = User::factory()->create();
    $accountant->assignRole(DashboardRole::Reviewer->value);
    $accountant->givePermissionTo(AccountingPermission::FiscalPeriodClose->value);

    Livewire::actingAs($accountant)
        ->test(ViewFiscalPeriod::class, ['record' => $this->period->getKey()])
        ->assertActionEnabled('close');
});
