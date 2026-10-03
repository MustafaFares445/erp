<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\DashboardRole;
use App\Enums\ExpenseStatus;
use App\Enums\InventoryPermission;
use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchaseInboundStatus;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\PurchaseAgreements\Pages\ListPurchaseAgreements;
use App\Filament\Resources\PurchaseInbounds\Pages\ListPurchaseInbounds;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Bill;
use App\Models\Concerns\Favoritable;
use App\Models\Expense;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseInbound;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->admin);
});

function rolloutAccountant(): User
{
    $accountant = User::factory()->create();
    $accountant->assignRole(DashboardRole::Accountant->value);

    test()->actingAs($accountant);

    return $accountant;
}

/**
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function purchasingRolloutRule(string $type, string $operator, array $settings): array
{
    return ['rules' => ['r1' => ['type' => $type, 'data' => ['operator' => $operator, 'settings' => $settings]]]];
}

/**
 * Stars $starred from its list page, then checks the Starred preset shows only it.
 */
function assertRolloutStarring(Testable $component, User $user, Model&Favoritable $starred, Model $other): void
{
    $component->callTableColumnAction('is_favorited', $starred);

    expect($starred->isFavoritedBy($user))->toBeTrue();

    $component
        ->call('selectTableView', 'preset', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);
}

it('filters, groups and stars bills', function (): void {
    $accountant = rolloutAccountant();
    $draft = Bill::factory()->create(['bill_number' => 'BILL-ROLL-1']);
    $approved = Bill::factory()->create(['bill_number' => 'BILL-ROLL-2', 'status' => BillStatus::Approved]);

    $component = Livewire::test(ManageBills::class)
        ->assertSuccessful()
        ->set('tableGrouping', 'status')
        ->assertCanSeeTableRecords([$draft, $approved])
        ->filterTable('queryBuilder', purchasingRolloutRule('status', 'is', ['values' => [BillStatus::Approved->value]]))
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters();

    assertRolloutStarring($component, $accountant, $draft, $approved);
});

it('filters, groups and stars expenses', function (): void {
    $accountant = rolloutAccountant();
    $draft = Expense::factory()->create(['merchant_name' => 'Rollout Fuel']);
    $paid = Expense::factory()->create(['merchant_name' => 'Other Merchant', 'status' => ExpenseStatus::Paid]);

    $component = Livewire::test(ManageExpenses::class)
        ->assertSuccessful()
        ->set('tableGrouping', 'status')
        ->assertCanSeeTableRecords([$draft, $paid])
        ->filterTable('queryBuilder', purchasingRolloutRule('status', 'is', ['values' => [ExpenseStatus::Draft->value]]))
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$paid])
        ->filterTable('queryBuilder', purchasingRolloutRule('merchant_name', 'contains', ['text' => 'Other']))
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters();

    assertRolloutStarring($component, $accountant, $paid, $draft);
});

it('filters, groups and stars suppliers', function (): void {
    $levant = Supplier::factory()->create(['name' => 'Levant Medical', 'requires_confirmation' => true]);
    $gulf = Supplier::factory()->create(['name' => 'Gulf Trading', 'is_active' => false]);

    $component = Livewire::test(ManageSuppliers::class)
        ->assertSuccessful()
        ->assertSee(__('Logo'))
        ->set('tableGrouping', 'is_active')
        ->assertCanSeeTableRecords([$levant, $gulf])
        ->set('tableGrouping', 'requires_confirmation')
        ->assertCanSeeTableRecords([$levant, $gulf])
        ->filterTable('queryBuilder', purchasingRolloutRule('name', 'contains', ['text' => 'Levant']))
        ->assertCanSeeTableRecords([$levant])
        ->assertCanNotSeeTableRecords([$gulf])
        ->resetTableFilters();

    assertRolloutStarring($component, $this->admin, $gulf, $levant);
});

it('filters and stars purchase inbounds while opening on the allocation queue', function (): void {
    $this->admin->givePermissionTo(InventoryPermission::InboundAllocate->value);
    $awaiting = PurchaseInbound::factory()->create();
    $received = PurchaseInbound::factory()->create(['status' => PurchaseInboundStatus::Received]);

    $component = Livewire::test(ListPurchaseInbounds::class)
        ->assertSet('activeTab', 'awaiting_allocation')
        ->assertCanSeeTableRecords([$awaiting])
        ->assertCanNotSeeTableRecords([$received])
        ->call('selectTableView', 'preset', 'all')
        ->assertCanSeeTableRecords([$awaiting, $received])
        ->filterTable('queryBuilder', purchasingRolloutRule('status', 'is', ['values' => [PurchaseInboundStatus::Received->value]]))
        ->assertCanSeeTableRecords([$received])
        ->assertCanNotSeeTableRecords([$awaiting])
        ->resetTableFilters();

    assertRolloutStarring($component, $this->admin, $received, $awaiting);
});

it('filters and stars purchase agreements', function (): void {
    $supplier = Supplier::factory()->create();
    $agreement = static fn (string $number, PurchaseAgreementStatus $status): PurchaseAgreement => PurchaseAgreement::query()->forceCreate([
        'agreement_number' => $number,
        'supplier_id' => $supplier->getKey(),
        'status' => $status,
        'currency_code' => 'AED',
        'starts_on' => today(),
        'created_by' => test()->admin->getKey(),
    ]);
    $draft = $agreement('PA-ROLL-1', PurchaseAgreementStatus::Draft);
    $active = $agreement('PA-ROLL-2', PurchaseAgreementStatus::Active);

    $component = Livewire::test(ListPurchaseAgreements::class)
        ->assertSuccessful()
        ->set('tableGrouping', 'status')
        ->assertCanSeeTableRecords([$draft, $active])
        ->filterTable('queryBuilder', purchasingRolloutRule('status', 'is', ['values' => [PurchaseAgreementStatus::Active->value]]))
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters();

    assertRolloutStarring($component, $this->admin, $draft, $active);
});
