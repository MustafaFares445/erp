<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\PeriodCloseCheck;
use App\Enums\StockCondition;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodCloseCheck;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SalesSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\Exceptions\PeriodCloseBlocked;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PeriodCloseChecklistService;
use App\Services\Sales\InvoicePostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * WP-2.5 (GAP-MW-18): the period-close gate. Every mandatory check delegates
 * to the service that already owns its figure, so each blocking scenario
 * below arranges a real divergence through that owning service (or, for the
 * trial balance, a row the database structurally allows but application code
 * never would) rather than faking a checklist result directly.
 */
beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
    ])->save();

    $this->period = FiscalPeriod::factory()->forMonth(CarbonImmutable::create(2026, 1, 1))->create();

    $this->service = app(FiscalPeriodService::class);
    $this->checklist = app(PeriodCloseChecklistService::class);

    $this->chief = User::factory()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    $this->actingAs($this->chief);
});

it('passes every check and closes a clean period', function (): void {
    $closed = $this->service->close($this->chief, $this->period);

    expect($closed->is_closed)->toBeTrue()
        ->and($closed->closed_by)->toBe($this->chief->getKey())
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->close_override_reason)->toBeNull()
        ->and($closed->close_override_by)->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.fiscal_period.closed',
        'subject_type' => FiscalPeriod::class,
        'subject_id' => $closed->getKey(),
        'causer_id' => $this->chief->getKey(),
    ]);
});

it('blocks the close with an unbalanced trial balance, naming the check', function (): void {
    // The database structurally allows an unbalanced entry even though
    // JournalPostingService never would; forcing one in directly is how the
    // gate's own trial-balance check is exercised without faking a result.
    $entry = JournalEntry::factory()->create([
        'entry_date' => $this->period->starts_at->toDateString(),
    ]);
    $debitAccount = ChartAccount::factory()->create();
    $creditAccount = ChartAccount::factory()->create();

    $entry->lines()->create([
        'chart_account_id' => $debitAccount->getKey(),
        'debit' => '100.00',
        'credit' => '0.00',
        'sort_order' => 1,
    ]);
    $entry->lines()->create([
        'chart_account_id' => $creditAccount->getKey(),
        'debit' => '0.00',
        'credit' => '40.00',
        'sort_order' => 2,
    ]);
    $entry->forceFill([
        'status' => 'posted',
        'fiscal_period_id' => $this->period->getKey(),
    ])->saveQuietly();

    expect(fn (): FiscalPeriod => $this->service->close($this->chief, $this->period))
        ->toThrow(PeriodCloseBlocked::class, PeriodCloseCheck::TrialBalanceBalances->label());

    expect($this->period->fresh()->is_closed)->toBeFalse();
});

it('blocks the close on an AR/control-account divergence', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => $this->period->starts_at->toDateString(),
        'due_date' => $this->period->starts_at->addDays(30)->toDateString(),
        'subtotal' => '400.00',
        'tax_total' => '0.00',
        'total_amount' => '400.00',
        'status' => 'draft',
        'issued_at' => null,
    ]);

    app(InvoicePostingService::class)->post($this->chief, $invoice);
    $invoice->forceFill(['status' => 'issued', 'issued_at' => now()])->save();

    // A manual journal entry posted directly against the receivable control
    // account, bypassing every subledger-affecting document — mirrors
    // ReceivablesReconciliationTest's own divergence scenario.
    $cash = ChartAccount::query()->where('code', '1110')->sole();
    $receivable = ChartAccount::query()->where('code', '1200')->sole();

    $stray = app(JournalPostingService::class)->draft(
        $this->chief,
        $this->period->starts_at,
        [
            ['chart_account_id' => $cash->getKey(), 'debit' => '50.00', 'credit' => '0.00'],
            ['chart_account_id' => $receivable->getKey(), 'debit' => '0.00', 'credit' => '50.00'],
        ],
        'Out-of-band adjustment',
    );
    app(JournalPostingService::class)->post($this->chief, $stray);

    expect(fn (): FiscalPeriod => $this->service->close($this->chief, $this->period))
        ->toThrow(PeriodCloseBlocked::class, PeriodCloseCheck::ReceivablesAgreeToControlAccount->label());

    expect($this->period->fresh()->is_closed)->toBeFalse();
});

it('blocks the close on a failing stock reconciliation', function (): void {
    $lot = InventoryLot::factory()->canonical()->create();

    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    expect(fn (): FiscalPeriod => $this->service->close($this->chief, $this->period))
        ->toThrow(PeriodCloseBlocked::class, PeriodCloseCheck::StockLedgerReconciles->label());

    expect($this->period->fresh()->is_closed)->toBeFalse();
});

it('does not block on a draft journal entry in the period, only flags it as advisory', function (): void {
    JournalEntry::factory()->balanced()->create([
        'entry_date' => $this->period->starts_at->toDateString(),
    ]);

    $closed = $this->service->close($this->chief, $this->period);

    expect($closed->is_closed)->toBeTrue();

    $advisory = FiscalPeriodCloseCheck::query()
        ->where('fiscal_period_id', $this->period->getKey())
        ->where('check_key', PeriodCloseCheck::NoDraftJournalEntriesInPeriod->value)
        ->latest('measured_at')
        ->first();

    expect($advisory)->not->toBeNull()
        ->and($advisory->passed)->toBeFalse();
});

it('closes over a failing mandatory check with a reason, recording the override and a distinct audit event', function (): void {
    $lot = InventoryLot::factory()->canonical()->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);

    $closed = $this->service->close($admin, $this->period, 'Known data-migration artifact, corrected next period.');

    expect($closed->is_closed)->toBeTrue()
        ->and($closed->close_override_reason)->toBe('Known data-migration artifact, corrected next period.')
        ->and($closed->close_override_by)->toBe($admin->getKey())
        ->and($closed->closed_by)->toBe($admin->getKey());

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.period.closed_with_override',
        'subject_type' => FiscalPeriod::class,
        'subject_id' => $closed->getKey(),
        'causer_id' => $admin->getKey(),
    ]);

    $this->assertDatabaseMissing('activity_log', [
        'description' => 'accounting.fiscal_period.closed',
        'subject_type' => FiscalPeriod::class,
        'subject_id' => $closed->getKey(),
    ]);
});

it('refuses an override from a System Admin who lacks the override permission', function (): void {
    $lot = InventoryLot::factory()->canonical()->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);
    Role::findByName(DashboardRole::SystemAdmin->value, 'web')->revokePermissionTo(
        AccountingPermission::PeriodCloseOverride->value
    );

    expect(fn (): FiscalPeriod => $this->service->close($admin, $this->period, 'Please let me close.'))
        ->toThrow(AuthorizationException::class);

    expect($this->period->fresh()->is_closed)->toBeFalse();
});

it('refuses an override with a blank reason', function (): void {
    $lot = InventoryLot::factory()->canonical()->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);

    expect(fn (): FiscalPeriod => $this->service->close($admin, $this->period, '   '))
        ->toThrow(PeriodCloseBlocked::class);

    expect(fn (): FiscalPeriod => $this->service->close($admin, $this->period, null))
        ->toThrow(PeriodCloseBlocked::class);

    expect($this->period->fresh()->is_closed)->toBeFalse();
});

it('persists every checklist result and keeps it re-readable after the close', function (): void {
    $this->service->close($this->chief, $this->period);

    $persisted = FiscalPeriodCloseCheck::query()
        ->where('fiscal_period_id', $this->period->getKey())
        ->get()
        ->keyBy(fn (FiscalPeriodCloseCheck $check): string => $check->check_key->value);

    foreach (PeriodCloseCheck::cases() as $check) {
        expect($persisted->has($check->value))->toBeTrue("missing persisted result for {$check->value}");
    }

    $trialBalanceRow = $persisted->get(PeriodCloseCheck::TrialBalanceBalances->value);
    expect($trialBalanceRow->passed)->toBeTrue()
        ->and($trialBalanceRow->detail)->toBeArray();
});

it('reopens a closed period and writes a fresh checklist snapshot as before/after evidence', function (): void {
    $this->service->close($this->chief, $this->period);

    $countAfterClose = FiscalPeriodCloseCheck::query()->where('fiscal_period_id', $this->period->getKey())->count();

    $reopened = $this->service->reopen($this->chief, $this->period->fresh());

    expect($reopened->is_closed)->toBeFalse();

    $countAfterReopen = FiscalPeriodCloseCheck::query()->where('fiscal_period_id', $this->period->getKey())->count();

    expect($countAfterReopen)->toBeGreaterThan($countAfterClose);
});
