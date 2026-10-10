import { test } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

// Only register credential-dependent sessions when a private credential is provisioned.
// Never include credentials in source, prompts or logged commands.
if (existsSync(join(process.cwd(), 'tmp', 'qa-secrets', 'admin-password')) || process.env.E2E_USER_ADMIN_PASSWORD) {
    test.setup('authenticate as demo administrator', { sessions: ['qa-admin'] }, async ({ app, browser, session }) => {
        const admin = credentials.user('admin');
        await app.open('/admin/login');
        await browser.locator('input[type="email"]').fill(admin.username);
        await browser.locator('input[type^="pass"][type$="word"]').first().fill(admin.password);
        await browser.locator('button[type="submit"]').click();
        await browser.waitForURL(/\/admin(?:\/(?!login)[^?]*)?(?:\?|$)/, { timeout: 45000 });
        await expect(browser).toHaveURL(/\/admin(?:\/(?!login)[^?]*)?(?:\?|$)/);
        await session.save('qa-admin');
    });
}
