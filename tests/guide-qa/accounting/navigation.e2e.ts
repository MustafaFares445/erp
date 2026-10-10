import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

/**
 * Agent 4: accounting route inventory. Read-only navigation through the real Filament UI.
 * All requests run against the worker-05 SQLite snapshot, not the development database.
 * The 42 record-state targets are tracked separately in tmp/qa-handoff.
 */
const groups = {
    ledger: [
        ['dashboard', '/admin/accounting-dashboard'],
        ['chart of accounts', '/admin/chart-of-accounts'],
        ['new account', '/admin/chart-of-accounts/create'],
        ['journal entries', '/admin/journal-entries'],
        ['new journal', '/admin/journal-entries/create'],
        ['fiscal periods', '/admin/fiscal-periods'],
        ['new fiscal period', '/admin/fiscal-periods/create'],
    ],
    settlement: [
        ['receivables', '/admin/accounts-receivable/accounts-receivables'],
        ['payables', '/admin/accounts-payable/accounts-payables'],
        ['bills', '/admin/bills'],
        ['supplier payments', '/admin/supplier-payments'],
        ['expenses', '/admin/expenses'],
        ['refunds', '/admin/refunds'],
        ['write offs', '/admin/receivable-write-offs'],
        ['new write off', '/admin/receivable-write-offs/create'],
    ],
    reconciliation: [
        ['bank statements', '/admin/bank-statements'],
        ['bank statement import', '/admin/bank-statements/create'],
        ['taxes', '/admin/taxes'],
        ['tax register', '/admin/taxes/register'],
        ['financial reports', '/admin/financial-reports'],
    ],
    settings: [
        ['currencies', '/admin/currencies'],
        ['payment terms', '/admin/payment-terms'],
        ['new payment term', '/admin/payment-terms/create'],
        ['payment methods', '/admin/payment-methods'],
        ['new payment method', '/admin/payment-methods/create'],
        ['sales setting', '/admin/sales-settings'],
    ],
} as const;

for (const [group, routes] of Object.entries(groups)) {
    for (const [name, path] of routes) {
        test(`Accounting ${group}: ${name} renders in authenticated browser`, { session: 'qa-admin', tags: ['accounting', 'navigation'] }, async ({ app, browser }) => {
            await app.open(path);
            await expect(browser).toHaveURL(new RegExp(path.replaceAll('/', '\\/') + '(?:\\?|$)'));
            await expect(browser.locator('main').first()).toBeVisible();
            await expect(browser.locator('main').first()).not.toContainText('Server Error');
        });
    }
}

test('unauthenticated bank reconciliation cannot be opened', { tags: ['accounting', 'authorization'] }, async ({ app, browser }) => {
    await app.open('/admin/bank-statements');
    await expect(browser).toHaveURL(/\/admin\/login(?:\?|$)/);
});
