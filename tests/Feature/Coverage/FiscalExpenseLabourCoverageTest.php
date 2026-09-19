<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\FiscalPeriods\Actions\FiscalPeriodActions;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LabourEntriesRelationManager;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\MaintenanceRecord;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function fiscalCoverageSetup(): FiscalPeriod
{
    (new AccountingPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
    ])->save();

    return FiscalPeriod::factory()->forMonth(CarbonImmutable::create(2026, 3, 1))->create();
}

function fiscalCoverageTable(): Table
{
    $owner = new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return Table::make($owner);
}

it('executes fiscal checklist close reopen and missing-actor branches', function (): void {
    $period = fiscalCoverageSetup();

    $runChecklist = FiscalPeriodActions::runChecklist()->getActionFunction();
    $close = FiscalPeriodActions::close()->getActionFunction();
    $reopen = FiscalPeriodActions::reopen()->getActionFunction();

    expect($runChecklist)->toBeInstanceOf(Closure::class)
        ->and($close)->toBeInstanceOf(Closure::class)
        ->and($reopen)->toBeInstanceOf(Closure::class);

    auth()->logout();
    $runChecklist($period);
    $close($period, []);
    $reopen($period);

    $closeDisabled = new ReflectionMethod(FiscalPeriodActions::class, 'closeDisabled');
    expect($closeDisabled->invoke(null, $period))->toBeTrue();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $runChecklist($period);
    expect($closeDisabled->invoke(null, $period))->toBeFalse();

    $close($period, ['override_reason' => null]);
    expect($period->refresh()->is_closed)->toBeTrue();

    $reopen($period->refresh());
    expect($period->refresh()->is_closed)->toBeFalse();
});

it('renders and executes the failing fiscal checklist branch', function (): void {
    $period = fiscalCoverageSetup();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $lot = InventoryLot::factory()->canonical()->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '-5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $runChecklist = FiscalPeriodActions::runChecklist()->getActionFunction();
    expect($runChecklist)->toBeInstanceOf(Closure::class);
    $runChecklist($period);

    $summary = new ReflectionMethod(FiscalPeriodActions::class, 'checklistSummary');
    expect($summary->invoke(null, $period))->toContain('FAIL');

    $closeDisabled = new ReflectionMethod(FiscalPeriodActions::class, 'closeDisabled');
    expect($closeDisabled->invoke(null, $period))->toBeFalse();
});

it('synchronizes legacy and canonical expense amount tax and account fields', function (): void {
    $supplier = Supplier::factory()->create();
    $accountA = ChartAccount::factory()->create();
    $accountB = ChartAccount::factory()->create();

    $legacy = new Expense;
    $legacy->forceFill([
        'expense_number' => null,
        'supplier_id' => $supplier->getKey(),
        'expense_date' => today(),
        'description' => 'Legacy direction',
        'amount' => '100.00',
        'tax_amount' => '5.00',
        'chart_account_id' => $accountA->getKey(),
        'status' => ExpenseStatus::Draft,
    ]);
    $legacy->save();

    expect($legacy->expense_number)->toMatch('/^EXP-\d{7}$/')
        ->and($legacy->subtotal)->toBe('100.00')
        ->and($legacy->tax_total)->toBe('5.00')
        ->and($legacy->total_amount)->toBe('105.00')
        ->and($legacy->expense_account_id)->toBe($accountA->getKey());

    $canonical = new Expense;
    $canonical->forceFill([
        'supplier_id' => $supplier->getKey(),
        'expense_date' => today(),
        'description' => 'Canonical direction',
        'subtotal' => '80.00',
        'tax_total' => '4.00',
        'expense_account_id' => $accountB->getKey(),
        'status' => ExpenseStatus::Draft,
    ]);
    $canonical->save();

    expect($canonical->amount)->toBe('80.00')
        ->and($canonical->tax_amount)->toBe('4.00')
        ->and($canonical->total_amount)->toBe('84.00')
        ->and($canonical->chart_account_id)->toBe($accountB->getKey());
});

it('enforces expense financial immutability and lifecycle deletion guards', function (): void {
    $approved = Expense::withoutEvents(
        fn (): Expense => Expense::factory()->create(['status' => ExpenseStatus::Approved]),
    );

    $approved->description = 'Changed after approval';

    expect(fn (): bool => $approved->save())
        ->toThrow(DomainException::class, 'cannot be changed');

    $approved->refresh();
    $approved->status = ExpenseStatus::Draft;

    expect(fn (): bool => $approved->save())
        ->toThrow(DomainException::class, 'cannot move backwards');

    $approved->refresh();
    expect(fn (): ?bool => $approved->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');

    expect($approved->outstandingAmount())->toBeGreaterThanOrEqual(0.0)
        ->and($approved->isFinanciallyImmutable())->toBeTrue();
});

it('covers labour relation-manager helpers guards and valid action', function (): void {
    $manager = new ReflectionClass(LabourEntriesRelationManager::class)->newInstanceWithoutConstructor();

    $requiredInt = new ReflectionMethod(LabourEntriesRelationManager::class, 'requiredInt');
    $optionalInt = new ReflectionMethod(LabourEntriesRelationManager::class, 'optionalInt');
    $requiredString = new ReflectionMethod(LabourEntriesRelationManager::class, 'requiredString');
    $optionalString = new ReflectionMethod(LabourEntriesRelationManager::class, 'optionalString');
    $currentActor = new ReflectionMethod(LabourEntriesRelationManager::class, 'currentActor');
    $maintenanceRecord = new ReflectionMethod(LabourEntriesRelationManager::class, 'maintenanceRecord');

    expect($requiredInt->invoke(null, ['v' => '12'], 'v'))->toBe(12)
        ->and(fn (): mixed => $requiredInt->invoke(null, [], 'v'))->toThrow(LogicException::class)
        ->and($optionalInt->invoke(null, ['v' => '7'], 'v'))->toBe(7)
        ->and($optionalInt->invoke(null, [], 'v'))->toBeNull()
        ->and($requiredString->invoke(null, ['v' => 'x'], 'v'))->toBe('x')
        ->and(fn (): mixed => $requiredString->invoke(null, [], 'v'))->toThrow(LogicException::class)
        ->and($optionalString->invoke(null, ['v' => 'x'], 'v'))->toBe('x')
        ->and($optionalString->invoke(null, [], 'v'))->toBeNull();

    auth()->logout();
    expect(fn (): mixed => $currentActor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');

    $manager->ownerRecord = User::factory()->create();
    expect(fn (): mixed => $maintenanceRecord->invoke($manager))
        ->toThrow(LogicException::class, 'MaintenanceRecord');

    $record = MaintenanceRecord::factory()->create();
    $manager->ownerRecord = $record;
    expect($maintenanceRecord->invoke($manager))->toBe($record);

    $actor = User::factory()->admin()->create();
    $employee = User::factory()->create();
    $this->actingAs($actor);

    $table = $manager->table(fiscalCoverageTable());
    $action = $table->getHeaderActions()[0];
    $closure = $action->getActionFunction();
    expect($closure)->toBeInstanceOf(Closure::class);

    $closure([
        'employee_id' => $employee->getKey(),
        'performed_on' => today()->toDateString(),
        'minutes' => 30,
        'hourly_rate_minor' => 6000,
        'notes' => 'Coverage labour',
    ]);

    expect($record->labourEntries()->count())->toBe(1)
        ->and($record->labourEntries()->sole()->total_cost_minor)->toBe(3000);
});
