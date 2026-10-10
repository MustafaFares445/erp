import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// Only inspect existing inventory documents; assert server-filtered table outcomes.
test('Count list search returns matching and empty states, then resets', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/inventory-counts');
  const search=browser.locator('main input[type="search"]').first();
  await expect(search).toBeVisible();
  await search.fill('CNT-000001');
  await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('CNT-000001');
  await search.fill('NO-MATCH-PHASE-FOUR-987');
  await search.press('Enter');
  await expect(browser.locator('main')).not.toContainText('CNT-000001');
  await search.fill('');
  await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('CNT-000001');
});

test('Arabic Return list searches supplier reference without changing RTL', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/locale/ar');
  await app.open('/admin/returns');
  await expect(browser.locator('html')).toHaveAttribute('dir','rtl');
  const search=browser.locator('main input[type="search"]').first();
  await expect(search).toBeVisible();
  await search.fill('RET-000001');
  await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('RET-000001');
  await expect(browser.locator('main table')).not.toContainText('RET-000003');
});
