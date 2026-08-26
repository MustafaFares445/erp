<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountElement;
use App\Models\AccountType;
use App\Models\ChartAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds the five account types (FR-002) and a starting chart of accounts
 * (FR-012).
 *
 * Idempotent: both passes use `firstOrCreate` keyed on the natural unique column,
 * so re-running neither duplicates nor rewrites a chart an accountant has since
 * edited.
 *
 * No document in the canonical set specifies account codes, so the structure
 * below is a conventional starting point rather than a prescribed one, and every
 * row is user-editable afterward (spec.md §Assumptions). Each element gets a
 * non-postable header with postable leaves beneath it, which is the shape FR-007
 * requires.
 */
final class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $types = $this->seedAccountTypes();

        foreach ($this->chart() as $header) {
            $parent = ChartAccount::query()->firstOrCreate(
                ['code' => $header['code']],
                [
                    'account_type_id' => $types[$header['element']->value],
                    'parent_id' => null,
                    'name' => $header['name'],
                    'is_postable' => false,
                    'is_active' => true,
                ],
            );

            foreach ($header['children'] as $code => $name) {
                ChartAccount::query()->firstOrCreate(
                    ['code' => (string) $code],
                    [
                        'account_type_id' => $types[$header['element']->value],
                        'parent_id' => $parent->getKey(),
                        'name' => $name,
                        'is_postable' => true,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, int> account type id keyed by element value
     */
    private function seedAccountTypes(): array
    {
        $ids = [];

        foreach (AccountElement::cases() as $element) {
            $type = AccountType::query()->firstOrCreate(
                ['name' => $element->value],
                ['normal_balance' => $element->normalBalance()->value],
            );

            $ids[$element->value] = $type->id;
        }

        return $ids;
    }

    /**
     * @return list<array{code: string, name: string, element: AccountElement, children: array<int|string, string>}>
     */
    private function chart(): array
    {
        return [
            [
                'code' => '1000',
                'name' => 'Assets',
                'element' => AccountElement::Asset,
                'children' => [
                    '1100' => 'Cash on Hand',
                    '1110' => 'Bank Account',
                    '1200' => 'Accounts Receivable',
                    '1300' => 'Inventory',
                    '1400' => 'Prepaid Expenses',
                    '1450' => 'Recoverable Input Tax',
                    '1500' => 'Property and Equipment',
                ],
            ],
            [
                'code' => '2000',
                'name' => 'Liabilities',
                'element' => AccountElement::Liability,
                'children' => [
                    '2100' => 'Accounts Payable',
                    '2200' => 'Accrued Liabilities',
                    '2300' => 'Sales Tax Payable',
                    // Added by spec 019 (ADR 0008, research.md R-007): tax that
                    // has been invoiced but not yet collected. Without a
                    // separate account, invoice issuance would have nowhere
                    // to credit tax except 2300, which is what "recognising
                    // tax at issuance" means — exactly what Principle III
                    // forbids.
                    '2350' => 'Deferred Sales Tax',
                    '2400' => 'Customer Deposits',
                ],
            ],
            [
                'code' => '3000',
                'name' => 'Equity',
                'element' => AccountElement::Equity,
                'children' => [
                    '3100' => 'Share Capital',
                    '3200' => 'Retained Earnings',
                ],
            ],
            [
                'code' => '4000',
                'name' => 'Income',
                'element' => AccountElement::Income,
                'children' => [
                    '4100' => 'Product Sales',
                    '4200' => 'Service Revenue',
                    '4300' => 'Maintenance Revenue',
                    '4900' => 'Other Income',
                    '4950' => 'Sales Returns and Allowances',
                ],
            ],
            [
                'code' => '5000',
                'name' => 'Expenses',
                'element' => AccountElement::Expense,
                'children' => [
                    '5100' => 'Cost of Goods Sold',
                    '5200' => 'Salaries and Wages',
                    '5300' => 'Rent',
                    '5400' => 'Utilities',
                    '5500' => 'Depreciation',
                    '5900' => 'Other Expenses',
                ],
            ],
        ];
    }
}
