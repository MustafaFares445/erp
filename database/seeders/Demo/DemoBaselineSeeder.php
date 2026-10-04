<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\DashboardRole;
use App\Enums\PaymentMethodType;
use App\Enums\UserType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PurchaseSetting;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Sales\PaymentTermService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Operational settings and staff the demo month depends on: role users, sales/purchasing
 * settings, payment terms and methods, and the 2026 fiscal calendar.
 */
final class DemoBaselineSeeder extends DemoSeeder
{
    /** @var array<string, array{name: string, roles: list<DashboardRole>}> actor key => definition */
    private const array Staff = [
        'admin' => ['name' => 'Demo Administrator', 'roles' => [DashboardRole::SystemAdmin]],
        'operations' => ['name' => 'Demo Operations Admin', 'roles' => [DashboardRole::WarehouseManager]],
        'sales_manager' => ['name' => 'Demo Sales Manager', 'roles' => [DashboardRole::SalesManager]],
        'billing' => ['name' => 'Demo Billing Officer', 'roles' => [DashboardRole::BillingOfficer]],
        'chief_accountant' => ['name' => 'Demo Chief Accountant', 'roles' => [DashboardRole::ChiefAccountant]],
        'accountant' => ['name' => 'Demo Accountant', 'roles' => [DashboardRole::Accountant]],
        'purchasing_manager' => ['name' => 'Demo Purchasing Manager', 'roles' => [DashboardRole::PurchasingManager]],
        'purchasing_officer' => ['name' => 'Demo Purchasing Officer', 'roles' => [DashboardRole::PurchasingOfficer]],
        'crm_manager' => ['name' => 'Demo CRM Manager', 'roles' => [DashboardRole::CrmManager]],
        'employee_manager' => ['name' => 'Demo Employee Manager', 'roles' => [DashboardRole::EmployeeManager]],
        'payroll' => ['name' => 'Demo Payroll Officer', 'roles' => [DashboardRole::PayrollOfficer]],
        'support_manager' => ['name' => 'Demo Support Manager', 'roles' => [DashboardRole::SupportManager]],
    ];

    protected function seed(DemoContext $context): void
    {
        $this->seedStaff();
        $this->seedSalesSettings();
        $this->seedPurchaseSettings();
        $this->seedPaymentTerms();
        $this->seedPaymentMethods();
        $this->seedFiscalPeriods($context);
    }

    private function seedStaff(): void
    {
        foreach (self::Staff as $key => $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => DemoContext::Actors[$key]],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make(DemoContext::Password),
                    'user_type' => UserType::Admin,
                ],
            );

            foreach ($definition['roles'] as $role) {
                if (! $user->hasRole($role->value)) {
                    $user->assignRole($role->value);
                }
            }
        }
    }

    private function seedSalesSettings(): void
    {
        $settings = SalesSetting::current();
        $settings->forceFill([
            'default_tax_percent' => 5,
            'default_quotation_validity_days' => 30,
            'customer_order_auto_close_days' => 14,
            'receivable_account_id' => $this->accountId('1200'),
            'revenue_account_id' => $this->accountId('4100'),
            'deferred_tax_account_id' => $this->accountId('2350'),
            'tax_payable_account_id' => $this->accountId('2300'),
            'customer_deposits_account_id' => $this->accountId('2400'),
            'bad_debt_expense_account_id' => $this->accountId('6800'),
            'auto_apply_customer_deposits' => true,
        ])->save();
    }

    private function seedPurchaseSettings(): void
    {
        PurchaseSetting::current()->update([
            'approval_threshold_amount' => '5000.00',
            'approval_threshold_currency' => 'AED',
        ]);
    }

    private function seedPaymentTerms(): void
    {
        $service = app(PaymentTermService::class);
        foreach (DemoFixtures::PaymentTerms as $name => $term) {
            if (PaymentTerm::query()->where('name', $name)->exists()) {
                continue;
            }

            $service->create([
                'name' => $name,
                'due_days' => $term['days'],
                'grace_days' => $term['grace'],
                'discount_percent' => 0,
                'is_default' => false,
            ]);
        }
    }

    private function seedPaymentMethods(): void
    {
        $bank = $this->accountId('1110');
        $cash = $this->accountId('1100');

        $methods = [
            ['Operating Bank Transfer', PaymentMethodType::BankTransfer, $bank, true],
            ['Customer Bank Transfer', PaymentMethodType::BankTransfer, $bank, false],
            ['Stripe Card Payments', PaymentMethodType::Stripe, $bank, false],
            ['Cash Desk', PaymentMethodType::Cash, $cash, false],
            ['Cheque Deposit', PaymentMethodType::Cheque, $bank, true],
            ['Exchange House Remittance', PaymentMethodType::Other, $bank, true],
        ];

        foreach ($methods as [$name, $type, $accountId, $requiresProof]) {
            PaymentMethod::query()->firstOrCreate(
                ['name' => $name],
                ['type' => $type, 'chart_account_id' => $accountId, 'is_active' => true, 'requires_proof' => $requiresProof],
            );
        }
    }

    private function seedFiscalPeriods(DemoContext $context): void
    {
        $admin = $context->actor('admin');
        $service = app(FiscalPeriodService::class);

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create(2026, $month, 1)?->startOfDay() ?? throw new LogicException("Cannot build fiscal period start for month [{$month}].");
            $end = $start->copy()->endOfMonth();

            if (FiscalPeriod::query()->whereDate('starts_at', $start->toDateString())->exists()) {
                continue;
            }

            $service->create($admin, $start->format('F Y'), $start, $end);
        }
    }

    private function accountId(string $code): int
    {
        $account = ChartAccount::query()->where('code', $code)->first();

        if (! $account instanceof ChartAccount) {
            throw new LogicException("Chart of accounts is missing account [{$code}]. Run ChartOfAccountsSeeder first.");
        }

        return $account->id;
    }
}
