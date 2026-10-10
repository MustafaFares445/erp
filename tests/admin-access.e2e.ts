import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// Read-only ERP authentication boundary; safe against the existing local database.
test('a visitor must sign in before accessing the ERP admin dashboard', async ({ app, browser }) => {
  await app.open('/admin');

  await expect(browser).toHaveURL(/\/admin\/login(?:\?|$)/);
  await expect(browser.locator('input[type="email"]')).toBeVisible();
  await expect(browser.locator('input[type="password"]')).toBeVisible();

  // A protected inventory screen must enforce the same authentication boundary.
  await app.open('/admin/inventory-operations');
  await expect(browser).toHaveURL(/\/admin\/login(?:\?|$)/);
});
