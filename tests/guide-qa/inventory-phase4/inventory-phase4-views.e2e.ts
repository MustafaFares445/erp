import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// The explicit routes represent the 14 current Phase 4 screenshot positions.
// Browsing existing records is safe; stock postings are covered by Pest.
const screens = [
  ['/admin/inventory-counts', 'Inventory Counts', 'عمليات الجرد المخزني'],
  ['/admin/inventory-counts/create', 'Create Inventory Count', 'إضافة جرد مخزني'],
  ['/admin/inventory-counts/1', 'View Inventory Count', 'عرض جرد مخزني'],
  ['/admin/adjustments', 'Inventory Adjustments', 'تسويات المخزون'],
  ['/admin/adjustments/create', 'Create Inventory Adjustment', 'إضافة تسوية مخزون'],
  ['/admin/adjustments/1', 'View Inventory Adjustment', 'عرض تسوية مخزون'],
  ['/admin/adjustments/3/edit', 'Edit Inventory Adjustment', 'تعديل تسوية مخزون'],
  ['/admin/inventory-corrections', 'Receipt Corrections', 'تصحيحات الاستلام'],
  ['/admin/inventory-corrections/1', 'View Receipt Correction', 'عرض تصحيح استلام'],
  ['/admin/returns', 'Inventory Returns', 'مرتجعات المخزون'],
  ['/admin/returns/1', 'View Inventory Return', 'عرض مرتجع مخزني'],
  ['/admin/inventory-condition-changes', 'Inventory Condition Changes', 'تغييرات حالة المخزون'],
  ['/admin/inventory-condition-changes/create', 'Create Inventory Condition Change', 'إضافة تغيير حالة المخزون'],
  ['/admin/inventory-condition-changes/1', 'View Inventory Condition Change', 'عرض تغيير حالة المخزون'],
] as const;

for (const locale of ['en', 'ar'] as const) {
  test(`Phase 4 screens render real ${locale} headings and contextual guide links`, { session: 'qa-admin' }, async ({ app, browser }) => {
    await app.open('/locale/' + locale);
    for (const [route, english, arabic] of screens) {
      await app.open(route);
      await expect(browser).toHaveURL(new RegExp(route.replaceAll('/', '\\/') + '(?:\\?|$)'));
      await expect(browser.locator('h1').first()).toContainText(locale === 'en' ? english : arabic);
      await expect(browser.locator('body')).not.toContainText('Internal Server Error');
      await expect(browser.locator('body')).not.toContainText('Whoops');
      // Filament renders desktop and mobile links; only one is visible in a given viewport.
      await expect(browser.locator('a[href*="/guide/"]:visible').first()).toBeVisible();
    }
    await expect(browser.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
  });
}

test('reviewer opens an existing completed physical count', { session: 'qa-admin' }, async ({ app, browser, screen }) => {
  await app.open('/locale/en');
  await app.open('/admin/inventory-counts');
  await screen.getByRole('link', { name: 'CNT-000001', exact: true }).tap();
  await expect(browser).toHaveURL(/\/admin\/inventory-counts\/1(?:\?|$)/);
  await expect(browser.locator('h1').first()).toContainText('View Inventory Count');
  await expect(browser.locator('body')).toContainText('Count Progress');
});

test('reviewer follows a posted supplier return', { session: 'qa-admin' }, async ({ app, agent, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/returns');
  await agent.act('Open the existing posted supplier return in the table to review its stock document and financial follow-up, without creating or posting a return.');
  await expect(browser.locator('h1').first()).toContainText('View Inventory Return');
  await expect(browser.locator('body')).toContainText('Financial Follow-up');
});
