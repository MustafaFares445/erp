import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

/**
 * Accounting interactions are strictly read-only or validation-only.
 * Never submit a valid money-changing action in this suite.
 */

test('Chart of Accounts search filters to a known seeded account', { session: 'qa-admin', tags: ['accounting', 'search'] }, async ({ app, browser, screen }) => {
    await app.open('/locale/en');
    await app.open('/admin/chart-of-accounts');
    await screen.getByPlaceholder('Search').first().fill('1110');
    await expect(browser.locator('main').first()).toContainText('1110');
});

test('Bank statement queue shows a legitimate empty state, not a fabricated View record', { session: 'qa-admin', tags: ['accounting', 'reconciliation'] }, async ({ app, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/bank-statements');
    const main = browser.locator('main').first();
    await expect(main).toContainText(/No bank statements|لا توجد كشوف/i);
    await expect(main).toContainText(/Import statement|استيراد كشف/);
});

test('Financial report export starts an actual CSV download', { session: 'qa-admin', tags: ['accounting', 'exports'] }, async ({ app, browser, screen }) => {
    await app.open('/locale/en');
    await app.open('/admin/financial-reports');
    await expect(browser.locator('main').first()).toContainText(/Financial Reports|التقارير المالية/);
    const download = await browser.waitForDownload(async () => {
        await screen.getByRole('button', { name: /Export current report|تصدير التقرير الحالي|تصدير التقرير/ }).first().click();
    }, { timeout: 20000 });
    expect(download.suggestedFilename).toMatch(/\.csv$/i);
});
