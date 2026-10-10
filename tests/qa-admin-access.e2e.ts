import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

if (existsSync(join(process.cwd(), 'tmp', 'qa-secrets', 'admin-password')) || process.env.E2E_USER_ADMIN_PASSWORD) {
    test('isolated administrator session can open its dashboard', { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open('/admin');
        await browser.waitForURL(/\/admin(?:\/(?!login)[^?]*)?(?:\?|$)/, { timeout: 45000 });
        await expect(browser).toHaveURL(/\/admin(?:\/(?!login)[^?]*)?(?:\?|$)/);
    });
}
