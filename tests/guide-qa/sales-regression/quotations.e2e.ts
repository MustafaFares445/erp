import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('SQLite quotation table search survives empty matching and reset', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/admin/quotations');
  await app.open('/locale/en');
  await app.open('/admin/quotations');
  await expect(browser).toHaveURL(/\/admin\/quotations/);
  const search = browser.locator('input[placeholder*="quotation number"]').first();
  await search.fill('AGENT7_NONEXISTENT_20261008');
  await expect(browser.locator('a[href*="/guide/screens/sales/quotation"]').first()).toBeVisible();
  const failures = await browser.evaluate(() => document.body.innerText.includes('SQLSTATE['));
  expect(failures).toBe(false);
  await search.clear();
  const resetFailures = await browser.evaluate(() => document.body.innerText.includes('SQLSTATE['));
  expect(resetFailures).toBe(false);
});

test('the quotations list retains search, table actions and a user guide entry', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/admin/quotations');
  await app.open('/locale/en');
  await app.open('/admin/quotations');
  await expect(browser.locator('input[placeholder*="quotation number"]').first()).toBeVisible();
  await expect(browser.locator('a[href*="/guide/screens/sales/quotation"]').first()).toBeVisible();
  await expect(browser.locator('table').first()).toBeVisible();
});
