<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Supplier;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function payableCoverageMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(AccountsPayableService::class, $name);
}

it('exports supplier payable rows and filters zero balance suppliers and detail documents', function (): void {
    $supplier = Supplier::factory()->create(['name' => 'Coverage Supplier']);

    Expense::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '25.00',
    ]);
    Expense::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => ExpenseStatus::Paid,
        'expense_date' => '2026-07-01',
        'due_date' => '2026-07-15',
        'subtotal' => '50.00',
        'tax_total' => '0.00',
        'total_amount' => '50.00',
        'amount_paid' => '50.00',
    ]);

    $service = app(AccountsPayableService::class);
    $asOf = CarbonImmutable::parse('2026-09-18');

    $summary = $service->summary($asOf);
    $detail = $service->supplierDetail($supplier, $asOf);
    $csv = $service->toCsv($asOf);

    expect($summary['suppliers'])->toHaveCount(1)
        ->and($summary['suppliers'][0]['supplier_name'])->toBe('Coverage Supplier')
        ->and($summary['outstanding_minor'])->toBe(7500)
        ->and($detail['documents'])->toHaveCount(1)
        ->and($detail['documents'][0]['remaining_minor'])->toBe(7500)
        ->and($detail['documents'][0]['days_overdue'])->toBeGreaterThan(0)
        ->and($csv)->toContain('Coverage Supplier')
        ->and($csv)->toContain('Subledger outstanding')
        ->and($csv)->toContain('75.00');
});

it('covers aging buckets deleted supplier fallback null due date and formatting helpers', function (): void {
    $service = app(AccountsPayableService::class);
    $asOf = CarbonImmutable::parse('2026-09-18');
    $documents = [
        [
            'type' => 'expense',
            'supplier_id' => 999999,
            'number' => 'CUR',
            'supplier_reference' => null,
            'date' => '2026-09-18',
            'due_date' => '2026-09-20',
            'total_minor' => 100,
            'paid_minor' => 0,
            'remaining_minor' => 100,
        ],
        [
            'type' => 'expense',
            'supplier_id' => 999999,
            'number' => 'D20',
            'supplier_reference' => null,
            'date' => '2026-08-01',
            'due_date' => '2026-08-29',
            'total_minor' => 200,
            'paid_minor' => 0,
            'remaining_minor' => 200,
        ],
        [
            'type' => 'expense',
            'supplier_id' => 999999,
            'number' => 'D45',
            'supplier_reference' => null,
            'date' => '2026-07-01',
            'due_date' => '2026-08-04',
            'total_minor' => 300,
            'paid_minor' => 0,
            'remaining_minor' => 300,
        ],
        [
            'type' => 'expense',
            'supplier_id' => 999999,
            'number' => 'D75',
            'supplier_reference' => null,
            'date' => '2026-06-01',
            'due_date' => '2026-07-05',
            'total_minor' => 400,
            'paid_minor' => 0,
            'remaining_minor' => 400,
        ],
        [
            'type' => 'expense',
            'supplier_id' => 999999,
            'number' => 'D100',
            'supplier_reference' => null,
            'date' => '2026-05-01',
            'due_date' => '2026-06-01',
            'total_minor' => 500,
            'paid_minor' => 100,
            'remaining_minor' => 400,
        ],
    ];

    $summary = payableCoverageMethod('supplierSummary')->invoke($service, 999999, $documents, $asOf);

    expect($summary['supplier_name'])->toBe('Deleted supplier #999999')
        ->and($summary['supplier_deleted'])->toBeTrue()
        ->and($summary['buckets'])->toBe([
            'current' => 100,
            '1_30' => 200,
            '31_60' => 300,
            '61_90' => 400,
            'over_90' => 400,
        ])
        ->and(payableCoverageMethod('daysOverdue')->invoke($service, null, $asOf))->toBe(0)
        ->and(payableCoverageMethod('formatMinor')->invoke($service, 12345))->toBe('123.45')
        ->and($service->payableControlAccountMinor())->toBe(0);
});
