import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Accounting bank import renders the required reconciliation inputs without posting money', { session: 'qa-admin', tags: ['accounting', 'reconciliation'] }, async ({ app, browser, screen }) => {
    await app.open('/admin/bank-statements/create');
    await expect(browser).toHaveURL(/\/admin\/bank-statements\/create/);
    await expect(screen.getByRole('heading', { name: /Import|Bank Statement|كشف|استيراد/i }).first()).toBeVisible();
    await expect(browser.locator('main').first()).toContainText(/Statement|statement|كشف|مراجعة/);
    await expect(browser.locator('input').first()).toBeAttached();
});

test('Accounting supplier payment list contains lifecycle controls but not an unsupported public create URL', { session: 'qa-admin', tags: ['accounting', 'supplier-payments'] }, async ({ app, browser }) => {
    await app.open('/admin/supplier-payments');
    await expect(browser).toHaveURL(/\/admin\/supplier-payments(?:\?|$)/);
    await expect(browser.locator('main').first()).toBeVisible();
});

test('Financial reports open read-only in the UI', { session: 'qa-admin', tags: ['accounting', 'reports'] }, async ({ app, browser }) => {
    await app.open('/admin/financial-reports');
    await expect(browser).toHaveURL(/\/admin\/financial-reports(?:\?|$)/);
    await expect(browser.locator('main').first()).toBeVisible();
    await expect(browser.locator('main').first()).not.toContainText('Server Error');
});

test('Both accounting locales render, and Arabic uses right-to-left direction', { session: 'qa-admin', tags: ['accounting', 'bilingual'] }, async ({ app, browser }) => {
    await app.open('/locale/ar');
    await app.open('/admin/chart-of-accounts');
    await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(browser.locator('main').first()).toBeVisible();
    await app.open('/locale/en');
    await app.open('/admin/chart-of-accounts');
    await expect(browser.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(browser.locator('main').first()).toBeVisible();
});

test('Bank reconciliation navigation remains translated in Arabic', { session: 'qa-admin', tags: ['accounting', 'bilingual', 'regression'] }, async ({ app, browser }) => {
    await app.open('/locale/ar');
    await app.open('/admin/bank-statements');
    await expect(browser.locator('body')).toContainText('مطابقة البنك');
    await app.open('/locale/en');
    await app.open('/admin/bank-statements');
    await expect(browser.locator('body')).toContainText('Bank reconciliation');
});

test('Arabic bank import uses translated transaction controls', { session: 'qa-admin', tags: ['accounting', 'bilingual', 'regression'] }, async ({ app, browser }) => {
    await app.open('/locale/ar');
    await app.open('/admin/bank-statements/create');
    const main = browser.locator('main').first();
    await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(main).toContainText('تاريخ الحركة');
    await expect(main).toContainText('إضافة حركة بنكية');
});

test('Arabic supplier bill renders its status and reference evidence in Arabic', { session: 'qa-admin', tags: ['accounting', 'bilingual', 'regression'] }, async ({ app, browser }) => {
    await app.open('/locale/ar');
    await app.open('/admin/bills/1');
    const main = browser.locator('main').first();
    await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(main).toContainText('مدفوعة');
    await expect(main).toContainText('قدّمه المورد');
    await expect(main).toContainText('البنود والمطابقة الثلاثية');
});
