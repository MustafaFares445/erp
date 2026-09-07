<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;

describe('AccountElement', function (): void {
    it('has the five accounting elements and nothing else', function (): void {
        expect(AccountElement::values())->toBe(['asset', 'liability', 'equity', 'income', 'expense']);
    });

    it('pairs each element with the normal balance double-entry bookkeeping gives it', function (): void {
        expect(AccountElement::Asset->normalBalance())->toBe(NormalBalance::Debit)
            ->and(AccountElement::Expense->normalBalance())->toBe(NormalBalance::Debit)
            ->and(AccountElement::Liability->normalBalance())->toBe(NormalBalance::Credit)
            ->and(AccountElement::Equity->normalBalance())->toBe(NormalBalance::Credit)
            ->and(AccountElement::Income->normalBalance())->toBe(NormalBalance::Credit);
    });
});

describe('NormalBalance', function (): void {
    it('signs a debit-normal account positive and a credit-normal one negative (FR-036)', function (): void {
        // The whole reported-balance sign convention reduces to these two values:
        // balance = (debits - credits) * sign.
        expect(NormalBalance::Debit->sign())->toBe(1)
            ->and(NormalBalance::Credit->sign())->toBe(-1);
    });

    it('has exactly two cases', function (): void {
        expect(NormalBalance::values())->toBe(['debit', 'credit']);
    });
});

describe('JournalEntryStatus', function (): void {
    it('has only draft and posted — no void, cancelled, or reversed', function (): void {
        // A posted entry is immutable, so a correction is a separate reversing
        // entry rather than a state of the original (FR-025, FR-027).
        expect(JournalEntryStatus::values())->toBe(['draft', 'posted']);
    });

    it('answers isPosted for each case', function (): void {
        expect(JournalEntryStatus::Posted->isPosted())->toBeTrue()
            ->and(JournalEntryStatus::Draft->isPosted())->toBeFalse();
    });
});

describe('AccountingPermission', function (): void {
    it('declares the accounting catalogue entries, each namespaced under accounting', function (): void {
        // Payables adds supplier-payment recording alongside the existing
        // accounting foundation, reporting, receivables, bills, expenses,
        // refunds, and tax permissions.
        expect(AccountingPermission::values())->toHaveCount(30)
            ->and(AccountingPermission::ReportView->value)->toBe('accounting.report.view');

        foreach (AccountingPermission::values() as $permission) {
            expect($permission)->toStartWith('accounting.');
        }
    });

    it('keeps the four load-bearing separations as distinct permissions (FR-040)', function (): void {
        $distinct = [
            AccountingPermission::JournalEntryManage->value,
            AccountingPermission::JournalEntryPost->value,
            AccountingPermission::JournalEntryReverse->value,
            AccountingPermission::JournalEntryPostFromSource->value,
            AccountingPermission::FiscalPeriodManage->value,
            AccountingPermission::FiscalPeriodClose->value,
        ];

        expect(array_unique($distinct))->toHaveCount(6);
    });

    it('keeps closing over a failing check separate from closing itself (WP-2.5)', function (): void {
        // The ability to close a clean period is not the ability to close
        // over a reconciliation failure (GAP-MW-18).
        expect(AccountingPermission::PeriodCloseOverride->value)
            ->not->toBe(AccountingPermission::FiscalPeriodClose->value);
    });

    it('has no fixedRoleNames of its own, so only DashboardRole answers that question', function (): void {
        expect(method_exists(AccountingPermission::class, 'fixedRoleNames'))->toBeFalse();
    });
});

describe('DashboardRole', function (): void {
    it('registers Chief Accountant and Accountant as fixed roles (FR-041)', function (): void {
        expect(DashboardRole::ChiefAccountant->value)->toBe('Chief Accountant')
            ->and(DashboardRole::Accountant->value)->toBe('Accountant')
            ->and(DashboardRole::fixedRoleNames())->toContain('Chief Accountant', 'Accountant');
    });

    it('exposes every case through fixedRoleNames, so a new role narrows every module at once', function (): void {
        expect(DashboardRole::fixedRoleNames())->toHaveCount(count(DashboardRole::cases()));
    });
});
