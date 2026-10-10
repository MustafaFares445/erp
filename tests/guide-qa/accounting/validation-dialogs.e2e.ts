import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

/** Confirmation and invalid-form tests intentionally never submit a valid financial change. */

test('Draft journal Post opens a confirmation and can be cancelled without posting', { session: 'qa-admin', tags: ['accounting', 'dialog', 'lifecycle'] }, async ({ app, browser, screen }) => {
    await app.open('/locale/en');
    await app.open('/admin/journal-entries/93/edit');
    await expect(browser.locator('main').first()).toContainText('JE-000093');
    await screen.getByRole('button', { name: /^(Post|ترحيل)$/ }).first().click();
    await expect(screen.getByRole('heading', { name: /^Post$|^ترحيل$/ })).toBeVisible();
    await screen.getByRole('button', { name: /Cancel|إلغاء/ }).last().click();
    await expect(browser.locator('main').first()).toContainText('JE-000093');
    await expect(browser.locator('main').first()).toContainText(/Post|ترحيل/);
});

test('Creating an invalid empty journal fails validation without creating a record', { session: 'qa-admin', tags: ['accounting', 'validation'] }, async ({ app, browser, screen }) => {
    await app.open('/locale/en');
    await app.open('/admin/journal-entries/create');
    await screen.getByRole('button', { name: /^(Create|إضافة)$/ }).first().click();
    await expect(browser).toHaveURL(/\/admin\/journal-entries\/create(?:\?|$)/);
    await expect(browser.locator('main').first()).toContainText(/required|مطلوب|إلزامي/);
});
