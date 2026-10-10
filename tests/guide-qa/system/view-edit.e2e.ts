import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// Existing records in worker-08's deterministic demo database; no edits or posts.
for (const [route, article] of [
  ['/admin/audit-logs/1', '/guide/screens/reports/audit-log'],
  ['/admin/dashboard-users/1/edit', '/guide/screens/system/dashboard-user'],
  ['/admin/document-templates/7/edit', '/guide/screens/system/document-template'],
  ['/admin/notification-templates/1/edit', '/guide/screens/system/notification-template'],
] as const) {
  test('read-only QA of existing detail or edit form ' + route, { session: 'qa-admin' }, async ({ app, browser }) => {
    await app.open(route);
    await expect(browser).toHaveURL(new RegExp(route.replaceAll('/', '\\/')));
    await expect(browser.locator('a[href*="' + article + '"]').first()).toBeVisible();
    const hasFailure = await browser.evaluate(() => document.body.innerText.includes('SQLSTATE['));
    expect(hasFailure).toBe(false);
  });
}

test('Reports search retains contextual help through Livewire filter and reset', { session: 'qa-admin' }, async ({ app, browser }) => {
  await app.open('/admin/reports-center');
  const input = browser.locator('#reports-center-search');
  const help = browser.locator('a[href*="/guide/screens/reports/reports-center"]').first();
  await expect(input).toBeVisible();
  await input.fill('NO_REPORTS_AGENT7_9988');
  await expect(help).toBeVisible();
  await input.clear();
  await expect(help).toBeVisible();
});
