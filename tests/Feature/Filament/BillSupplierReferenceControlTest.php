<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::Accountant->value);

    $this->supplier = Supplier::factory()->create();
    $this->expenseAccount = ChartAccount::factory()->create([
        'is_postable' => true,
        'is_active' => true,
    ]);
});

/** @return array<string, mixed> */
function billCreateActionData(
    Supplier $supplier,
    ChartAccount $expenseAccount,
    string $reference,
): array {
    return [
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => $reference,
        'bill_date' => '2026-09-03',
        'description' => 'Filament duplicate supplier invoice test',
        'subtotal' => '50.00',
        'tax_total' => '0.00',
        'total_amount' => '50.00',
        'lines' => [[
            'chart_account_id' => $expenseAccount->getKey(),
            'description' => 'Service line',
            'quantity' => '1.000',
            'unit_price' => '50.00',
            'tax_amount' => '0.00',
            'line_total' => '50.00',
        ]],
    ];
}

it('shows a scoped unique validation error for a duplicate supplier invoice reference', function (): void {
    Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'FILAMENT-DUP-001',
    ]);

    Livewire::actingAs($this->actor)
        ->test(ManageBills::class)
        ->callAction(
            TestAction::make(CreateAction::class),
            data: billCreateActionData(
                $this->supplier,
                $this->expenseAccount,
                'FILAMENT-DUP-001',
            ),
        )
        ->assertHasFormErrors([
            'supplier_reference' => ['unique'],
        ]);

    expect(Bill::query()
        ->where('supplier_id', $this->supplier->getKey())
        ->where('supplier_reference', 'FILAMENT-DUP-001')
        ->count())->toBe(1);
});

it('requires the supplier invoice reference in the create action', function (): void {
    $data = billCreateActionData(
        $this->supplier,
        $this->expenseAccount,
        '',
    );

    Livewire::actingAs($this->actor)
        ->test(ManageBills::class)
        ->callAction(
            TestAction::make(CreateAction::class),
            data: $data,
        )
        ->assertHasFormErrors([
            'supplier_reference' => ['required'],
        ]);

    expect(Bill::query()->count())->toBe(0);
});
