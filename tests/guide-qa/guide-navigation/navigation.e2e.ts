import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Reports navigation exposes stable contextual help', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/reports-center');
  await expect(browser).toHaveURL(/\/admin\/reports-center/);
  await expect(browser.locator('a[href*="/guide/screens/reports/reports-center"]').first()).toBeVisible();
  await app.open('/admin/audit-logs');
  await expect(browser).toHaveURL(/\/admin\/audit-logs/);
  await expect(browser.locator('a[href*="/guide/screens/reports/audit-log"]').first()).toBeVisible();
});

test('Arabic locale retains the correct contextual guide article after navigation', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/quotations');
  await expect(browser.locator('a[href*="/guide/screens/sales/quotation"]').first()).toBeVisible();
  await browser.locator('.app-language-switcher').click();
  await expect(browser.locator('a[href*="/guide/ar/screens/sales/quotation"]').first()).toBeVisible();
  await app.open('/admin/reports-center');
  await expect(browser.locator('a[href*="/guide/ar/screens/reports/reports-center"]').first()).toBeVisible();
  // Keep the demo administrator's persisted locale stable for the independent test contexts.
  await browser.locator('.app-language-switcher').click();
  await expect(browser.locator('a[href*="/guide/screens/reports/reports-center"]').first()).toBeVisible();
});

test('Quotation table Livewire search does not destroy the contextual guide link', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/quotations');
  const guide = browser.locator('a[href*="/guide/screens/sales/quotation"]').first();
  await expect(guide).toBeVisible();
  const search = browser.locator('input[placeholder*="quotation number"]').first();
  await search.fill('AGENT7_NO_MATCH_999999');
  await expect(guide).toBeVisible();
  await search.clear();
  await expect(guide).toBeVisible();
});
