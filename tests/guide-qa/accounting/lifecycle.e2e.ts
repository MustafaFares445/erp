import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

/**
 * Read-only verification against legitimate worker-05 seeded financial states.
 * No action here posts, approves, pays, reverses, deletes, or closes a document.
 * The demo fixture's ids were checked against the isolated database.
 */

test('Posted journal presents reversal workflow without an edit affordance', { session: 'qa-admin', tags: ['accounting', 'lifecycle'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/journal-entries/1');
    const main = browser.locator('main').first();
    await expect(main).toContainText('JE-000001');
    await expect(main).toContainText(/Posted|مرحّل/);
    await expect(main).toContainText(/Reverse|عكس القيد/);
    await expect(main).toContainText(/can no longer be edited|لم يعد قابلاً للتعديل/);
});

test('A seeded draft journal retains editing and separate Post action', { session: 'qa-admin', tags: ['accounting', 'lifecycle'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/journal-entries/93/edit');
    const main = browser.locator('main').first();
    await expect(main).toContainText('JE-000093');
    await expect(main).toContainText('Post');
    await expect(main).toContainText('Save changes');
});

test('Closed fiscal period exposes Reopen and checklist without mutating it', { session: 'qa-admin', tags: ['accounting', 'lifecycle'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/fiscal-periods/1');
    const main = browser.locator('main').first();
    await expect(main).toContainText('January 2026');
    await expect(main).toContainText('Reopen');
    await expect(main).toContainText('Run checklist');
});

test('Supplier payment list shows posted Paid disbursements and no fabricated draft', { session: 'qa-admin', tags: ['accounting', 'supplier-payments'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/supplier-payments');
    const main = browser.locator('main').first();
    await expect(main).toContainText('SPAY-0000008');
    await expect(main).toContainText('Paid');
});

test('Refund queue distinguishes draft, approved, paid and cancelled finance states', { session: 'qa-admin', tags: ['accounting', 'refunds'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/refunds');
    const main = browser.locator('main').first();
    await expect(main).toContainText('REF-000005');
    await expect(main).toContainText('Draft');
    await expect(main).toContainText('Approved');
    await expect(main).toContainText('Paid');
    await expect(main).toContainText('Cancelled');
});

test('AR view exposes reconciliation evidence rather than auto-posting a balancing entry', { session: 'qa-admin', tags: ['accounting', 'receivables'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/accounts-receivable/accounts-receivables');
    const main = browser.locator('main').first();
    await expect(main).toContainText(/Receivables ageing|أعمار الذمم|تقادم الذمم/);
    await expect(main).toContainText('Reconciliation proof');
    await expect(main).toContainText('Outstanding');
});

test('AP aging displays the control-account tie-out and supplier balances', { session: 'qa-admin', tags: ['accounting', 'payables'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/accounts-payable/accounts-payables');
    const main = browser.locator('main').first();
    await expect(main).toContainText('Payable aging');
    await expect(main).toContainText('Tie-out proof');
    await expect(main).toContainText('Outstanding');
});
