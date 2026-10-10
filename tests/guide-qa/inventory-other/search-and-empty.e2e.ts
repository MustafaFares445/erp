import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Product catalog search filters a seeded product, returns empty state and restores it',
 {session:'qa-admin',timeout:120000},async ({app,browser})=>{
  await app.open('/locale/en');
  await app.open('/admin/products');
  const search=browser.locator('main input[type="search"]').first();
  await expect(search).toBeVisible();
  await search.fill('Dental Sintering Furnace');await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('Dental Sintering Furnace', { timeout: 30000 });
  await expect(browser.locator('main table')).not.toContainText('Maintenance Kit', { timeout: 30000 });
  await search.fill('NO-MATCH-INVENTORY-AGENT2-999999');await search.press('Enter');
  await expect(browser.locator('main')).not.toContainText('Dental Sintering Furnace');
  await search.fill('');await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('Dental Sintering Furnace');
  await expect(browser.locator('main table')).toContainText('Maintenance Kit');
});

test('Arabic lot search isolates real tracking identity and preserves RTL layout',
 {session:'qa-admin',timeout:120000},async ({app,browser})=>{
  await app.open('/locale/ar');
  await app.open('/admin/inventory-lots');
  await expect(browser.locator('html')).toHaveAttribute('dir','rtl');
  const search=browser.locator('main input[type="search"]').first();
  await expect(search).toBeVisible();
  await search.fill('LOT-P010-A3-OPEN');await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('LOT-P010-A3-OPEN');
  await search.fill('NO-MATCH-INVENTORY-AGENT2-999999');await search.press('Enter');
  await expect(browser.locator('main table')).not.toContainText('LOT-P010-A3-OPEN');
  await search.fill('');await search.press('Enter');
  await expect(browser.locator('main table')).toContainText('LOT-P010-A3-OPEN');
});
