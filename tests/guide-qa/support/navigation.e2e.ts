import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// Core screens are enabled in the default IERP Support rollout.
// A URL match alone is insufficient: every case checks the actual page heading.
const pages: Array<[string, RegExp]> = [
    ['/admin/support-dashboard', /support/i],
    ['/admin/tickets', /tickets/i],
    ['/admin/maintenance-requests', /maintenance requests/i],
    ['/admin/service-records', /service records/i],
    ['/admin/maintenance-schedules', /maintenance schedules/i],
    ['/admin/service-appointments', /field service|service appointments/i],
    ['/admin/support-equipment', /equipment/i],
    ['/admin/support-reports', /support reports|reports/i],
    ['/admin/sla-policies', /sla policies/i],
    ['/admin/sla-calendars', /sla calendars/i],
    ['/admin/support-service-levels', /service levels/i],
    ['/admin/support-entitlements', /support entitlements|entitlements/i],
    ['/admin/warranty-policies', /warranty policies/i],
];

for (const [path, heading] of pages) {
    test(`core Support route renders: ${path}`, { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open(path);
        await expect(browser).toHaveURL(new RegExp(path.replaceAll('/', '\\/') + '(?:\\?|$)'));
        const result = await browser.evaluate(() => ({
            heading: document.querySelector('h1')?.textContent?.trim() ?? '',
            body: document.body?.innerText ?? '',
        }));
        expect(result.heading, `Missing heading at ${path}`).toMatch(heading);
        expect(result.body).not.toMatch(/403 Forbidden|404 Not Found|500 Server Error|Internal Server Error/i);
    });
}

for (const [path, heading] of [
    ['/admin/tickets/create', /create.*ticket|new.*ticket/i],
    ['/admin/maintenance-requests/create', /create.*maintenance|new.*maintenance/i],
    ['/admin/maintenance-schedules/create', /create.*schedule|new.*schedule/i],
    ['/admin/warranty-policies/create', /create.*warranty|new.*warranty/i],
] as Array<[string, RegExp]>) {
    test(`Support create form renders without altering data: ${path}`, { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open(path);
        await expect(browser).toHaveURL(new RegExp(path.replaceAll('/', '\\/') + '(?:\\?|$)'));
        const headingText = await browser.evaluate(() => document.querySelector('h1')?.textContent?.trim() ?? '');
        expect(headingText).toMatch(heading);
    });
}

test('Knowledge Base direct route follows the configured rollout gate', { session: 'qa-admin' }, async ({ app, browser }) => {
    await app.open('/admin/knowledge-articles');
    const result = await browser.evaluate(() => ({
        heading: document.querySelector('h1')?.textContent?.trim() ?? '',
        body: document.body?.innerText ?? '',
        path: window.location.pathname,
    }));
    const enabled = /^SUPPORT_KNOWLEDGE_BASE_ENABLED=true$/mi.test(readFileSync(join(process.cwd(), '.env'), 'utf8'));
    if (enabled) {
        expect(result.heading).toMatch(/Knowledge Articles/i);
        expect(result.body).not.toMatch(/403 Forbidden|404 Not Found|Internal Server Error/i);
    } else {
        expect(result.heading).not.toMatch(/^(?:Knowledge Articles)$/i);
        expect(result.body + ' ' + result.path).toMatch(/403|404|forbidden|not found|\/admin(?:\/)?$/i);
    }
});
