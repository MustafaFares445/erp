import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('the Support case queue supports genuine Arabic RTL and English LTR at mobile width', { session: 'qa-admin' }, async ({ app, browser, screen }) => {
    await browser.setViewport({ width: 390, height: 844 });
    await app.open('/admin/tickets');
    await expect(screen.getByRole('link', { name: 'عربي' })).toBeVisible();
    await screen.getByRole('link', { name: 'عربي' }).tap();

    await expect.poll(() => browser.evaluate(() => document.documentElement.lang), { timeout: 15000 }).toBe('ar');
    const arabic = await browser.evaluate(() => ({
        lang: document.documentElement.lang,
        dir: document.documentElement.dir,
        heading: document.querySelector('h1')?.textContent?.trim() ?? '',
        viewport: window.innerWidth,
        documentWidth: document.documentElement.scrollWidth,
    }));
    expect(arabic.dir).toBe('rtl');
    expect(arabic.heading.length).toBeGreaterThan(2);
    expect(arabic.documentWidth).toBeLessThanOrEqual(arabic.viewport + 8);

    await expect(screen.getByRole('link', { name: 'EN' })).toBeVisible();
    await screen.getByRole('link', { name: 'EN' }).tap();
    await expect.poll(() => browser.evaluate(() => document.documentElement.lang), { timeout: 15000 }).toBe('en');
    const english = await browser.evaluate(() => ({
        dir: document.documentElement.dir,
        heading: document.querySelector('h1')?.textContent?.trim() ?? '',
        viewport: window.innerWidth,
        documentWidth: document.documentElement.scrollWidth,
    }));
    expect(english.dir).toBe('ltr');
    expect(english.heading).toMatch(/tickets/i);
    expect(english.documentWidth).toBeLessThanOrEqual(english.viewport + 8);
});

test('maintenance list and detailed work remain usable at narrow viewport', { session: 'qa-admin' }, async ({ app, browser }) => {
    await browser.setViewport({ width: 390, height: 844 });
    for (const path of ['/admin/maintenance-requests', '/admin/service-records', '/admin/support-equipment']) {
        await app.open(path);
        const view = await browser.evaluate(() => ({
            heading: document.querySelector('h1')?.textContent?.trim() ?? '',
            viewport: window.innerWidth,
            documentWidth: document.documentElement.scrollWidth,
        }));
        expect(view.heading.length, `Heading missing on ${path}`).toBeGreaterThan(2);
        expect(view.documentWidth, `Page-level overflow on ${path}`).toBeLessThanOrEqual(view.viewport + 8);
    }
});
