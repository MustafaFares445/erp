import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

type VendorTarget = { id: string; page: string; url: string | null; ready: boolean; blocker: string | null };
const path = join(process.cwd(), 'tmp', 'qa-logs', 'vendors-targets.json');
if (!readFileSync(path, 'utf8')) throw new Error('Agent-3 demo route map missing');
const targets: VendorTarget[] = JSON.parse(readFileSync(path, 'utf8'));
if (targets.length !== 20 || targets.some(t => !t.id.startsWith('vendors-'))) {
    throw new Error('Expected the 20 Purchasing/vendor screenshot positions');
}
const ready = targets.filter(t => t.ready && t.url !== null);
const unavailable = targets.filter(t => !t.ready);
if (unavailable.length > 0) {
    console.warn('Seeded record blockers: ' + unavailable.map(t => t.id + ' (' + t.blocker + ')').join('; '));
}
const quote = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

for (const locale of ['en', 'ar'] as const) {
    for (const target of ready) {
        test(`Purchasing ${locale} — ${target.id} renders its actual seeded route`, { session: 'qa-admin' }, async ({ app, browser, screen }) => {
            await app.open('/locale/' + locale);
            await app.open(target.url!);
            await expect(browser).toHaveURL(new RegExp(quote(target.url!) + '(?:\\?|$)'));
            await expect(browser.locator('h1').first()).toBeVisible();
            await expect(browser.locator('input[type="password"]')).not.toBeVisible({ timeout: 3000 });
            const direction = await browser.evaluate(() => getComputedStyle(document.documentElement).direction);
            expect(direction).toBe(locale === 'ar' ? 'rtl' : 'ltr');
            const pageTitle = await browser.locator('h1').first().textContent();
            expect(pageTitle.trim().length).toBeGreaterThan(0);
        });
    }
}
