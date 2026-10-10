import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

// PO10 is a legitimate seeded draft. Cancelling must preserve its persisted state.
test('operator inspects draft Submit confirmation and safely cancels', {
    session: 'qa-admin',
    timeout: 180_000,
}, async ({ app, agent, browser, screen }) => {
    await app.open('/locale/ar');
    await app.open('/admin/purchase-orders/10');
    await expect(browser).toHaveURL(/\/admin\/purchase-orders\/10(?:\?|$)/);
    await expect(browser.locator('h1').first()).toContainText('أمر الشراء');

    await agent.act('Open the Submit confirmation dialog for this Draft Purchase Order. Stop at the confirmation dialog and do not confirm or change the order.');
    // Filament's visible modal is not exposed with ARIA role=dialog in this UI.
    // Assert its actual localized title and confirmation control instead.
    await expect(screen.getByRole('heading', 'إرسال')).toBeVisible();
    await expect(screen.getByRole('button', 'تأكيد')).toBeVisible();

    await agent.act('Dismiss the Submit confirmation dialog without submitting the Purchase Order or changing any data.');
    await expect(screen.getByRole('button', 'تأكيد')).not.toBeVisible();
    await expect(browser).toHaveURL(/\/admin\/purchase-orders\/10(?:\?|$)/);
    await expect(browser.locator('text=مسودة').first()).toBeVisible();
    const database = new DatabaseSync(join(process.cwd(), 'tmp/e2e.sqlite'), { readOnly: true });
    try {
        const order = database.prepare('SELECT status, submitted_at, sent_at FROM purchase_orders WHERE id = ?').get(10);
        expect(order?.status).toBe('draft');
        expect(order?.submitted_at).toBeNull();
        expect(order?.sent_at).toBeNull();
    } finally {
        database.close();
    }
});
