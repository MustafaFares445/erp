import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

const pages = [
  ['/admin/reports-center', '/guide/screens/reports/reports-center'],
  ['/admin/audit-logs', '/guide/screens/reports/audit-log'],
  ['/admin/dashboard-users', '/guide/screens/system/dashboard-user'],
  ['/admin/document-templates', '/guide/screens/system/document-template'],
  ['/admin/notification-templates', '/guide/screens/system/notification-template'],
  ['/admin/custom-field-definitions', '/guide/screens/system/custom-field-definition'],
] as const;

for (const [route, article] of pages) {
  test('authorized Reports/System page ' + route + ' maps to its guide article', { session: 'qa-admin' }, async ({ app, browser }) => {
    await app.open(route);
    await expect(browser).toHaveURL(new RegExp(route.replaceAll('/', '\\/')));
    await expect(browser.locator('a[href*="' + article + '"]').first()).toBeVisible();
  });
}

test('unauthenticated user cannot view the administrative report', async ({ app, browser }) => {
  await app.open('/admin/audit-logs');
  await expect(browser).toHaveURL(/\/admin\/login/);
});
