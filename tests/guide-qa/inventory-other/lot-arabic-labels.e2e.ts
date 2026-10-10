import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Arabic Inventory Lot detail translates timeline headings, fields and visible status values',
 { session: 'qa-admin', timeout: 90000 }, async ({ app, browser }) => {
  await app.open('/locale/ar');
  await app.open('/admin/inventory-lots/23');
  await expect(browser.locator('h1').first()).toBeVisible();
  await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
  const area = browser.locator('main');
  await expect(area).not.toContainText('Lot traceability');
  await expect(area).not.toContainText('Movement history');
  await expect(area).not.toContainText('Expiry window');
  await expect(area).not.toContainText('Base delta');
  await expect(area).not.toContainText('From condition');
  await expect(area).not.toContainText('To condition');
  await expect(area).not.toContainText('expiring');
  await expect(area).toContainText('تتبع');
});
