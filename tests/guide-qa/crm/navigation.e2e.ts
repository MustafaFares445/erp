import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('CRM lead list opens for administrator', { session: 'qa-admin' }, async ({ app, browser, screen }) => {
    await app.open('/admin/leads');
    await expect(browser).toHaveURL(/\/admin\/leads/);
    await expect(screen.getByRole('heading', { name: /Leads|عملاء محتملون|العملاء المحتملين/i }).first()).toBeVisible();
});
