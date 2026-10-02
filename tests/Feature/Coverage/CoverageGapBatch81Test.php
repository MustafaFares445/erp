<?php

declare(strict_types=1);

namespace App\Services\Employees {
    function random_int(int $min, int $max): int
    {
        if (($GLOBALS['batch81_force_employee_code_collision'] ?? false) === true) {
            return 42;
        }

        return \random_int($min, $max);
    }
}

namespace {
    use App\Enums\DashboardRole;
    use App\Models\ChartAccount;
    use App\Models\EmployeeProfile;
    use App\Models\Supplier;
    use App\Models\User;
    use App\Services\Accounting\AccountingDocumentService;
    use App\Services\Employees\EmployeeOnboardingService;
    use App\Services\Purchasing\SupplierConfirmationService;
    use App\Services\Sales\SalesDashboardFilters;
    use App\Services\Sales\SalesDashboardMetricsService;
    use Carbon\CarbonImmutable;
    use Database\Seeders\AccountingPermissionSeeder;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;

    uses(RefreshDatabase::class);
    it('covers an already-normalized supplier promised date', function (): void {
        $date = CarbonImmutable::parse('2026-10-15 09:30:00');
        $method = new ReflectionMethod(SupplierConfirmationService::class, 'promisedDateForItem');

        expect($method->invoke(app(SupplierConfirmationService::class), $date, null))
            ->toBe($date);
    });

    it('returns no top products when the selected sales period has no confirmed orders', function (): void {
        $filters = SalesDashboardFilters::fromPageFilters([
            'period' => SalesDashboardFilters::PERIOD_TODAY,
        ]);

        expect(app(SalesDashboardMetricsService::class)->topProducts($filters))
            ->toBe([]);
    });

    it('fails after exhausting employee code collision retries', function (): void {
        EmployeeProfile::factory()->create([
            'employee_code' => 'EMP-0042',
        ]);

        $GLOBALS['batch81_force_employee_code_collision'] = true;
        try {
            $method = new ReflectionMethod(EmployeeOnboardingService::class, 'generateEmployeeCode');

            expect(fn (): mixed => $method->invoke(app(EmployeeOnboardingService::class)))
                ->toThrow(RuntimeException::class, 'Unable to generate a unique employee code.');
        } finally {
            $GLOBALS['batch81_force_employee_code_collision'] = false;
        }
    });

    it('rethrows unexpected database failures while recording a supplier bill', function (): void {
        (new AccountingPermissionSeeder)->run();
        $actor = User::factory()->create();
        $actor->assignRole(DashboardRole::Accountant->value);
        $supplier = Supplier::factory()->create();
        $expenseAccount = ChartAccount::factory()->create();

        DB::statement("CREATE TRIGGER batch81_bill_failure BEFORE INSERT ON bills BEGIN SELECT RAISE(ABORT, 'batch81 forced failure'); END");

        try {
            expect(fn () => app(AccountingDocumentService::class)->recordBill($actor, [
                'supplier_id' => $supplier->getKey(),
                'supplier_reference' => 'BATCH81-DB-FAILURE',
                'expense_account_id' => $expenseAccount->getKey(),
                'bill_date' => '2026-10-02',
                'description' => 'Forced non-unique database failure',
                'subtotal' => '10.00',
                'tax_total' => '0.00',
                'total_amount' => '10.00',
                'amount_paid' => '0.00',
            ]))->toThrow(QueryException::class, 'batch81 forced failure');
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS batch81_bill_failure');
        }
    });
}
