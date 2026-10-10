import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

for (const locale of ['en', 'ar']) {
    test(`Receivable proof includes the seeded customer credit in ${locale}`, { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open('/locale/' + locale);
        await app.open('/admin/accounts-receivable/accounts-receivables');
        const main = browser.locator('main').first();
        await expect(browser).toHaveURL(/\/admin\/accounts-receivable\/accounts-receivables(?:\?|$)/);
        await expect(browser.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
        await expect(main).toContainText(locale === 'ar' ? 'مطابق' : 'Reconciled');
        await expect(main).not.toContainText(locale === 'ar' ? 'الفرق:' : 'Difference:');
        if (locale === 'ar') {
            await expect(main).not.toContainText('Reconciled');
        }
        await expect(main).toContainText(locale === 'ar' ? 'الرصيد الدائن للعميل' : 'Customer credit balance');
        await expect(main).toContainText('210.00');
    });
}
