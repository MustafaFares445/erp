import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const env = readFileSync(join(process.cwd(), '.env'), 'utf8');
const enabled = (key: string): boolean => new RegExp(`^${key}=true$`, 'mi').test(env);

for (const resource of ['/admin/tickets', '/admin/maintenance-requests', '/admin/support-equipment']) {
    test(`Support direct route is authenticated: ${resource}`, async ({ app, browser }) => {
        await app.open(resource);
        await expect(browser).toHaveURL(/\/admin\/login(?:\?|$)/);
        const body = await browser.evaluate(() => document.body?.innerText ?? '');
        expect(body).toMatch(/log in|sign in|email|password/i);
    });
}

const gatedRoutes: Array<[string, string]> = [
    ['/admin/support-teams', 'SUPPORT_SMART_ROUTING_ENABLED'],
    ['/admin/support-skills', 'SUPPORT_SMART_ROUTING_ENABLED'],
    ['/admin/support-queues', 'SUPPORT_SMART_ROUTING_ENABLED'],
    ['/admin/support-routing-rules', 'SUPPORT_SMART_ROUTING_ENABLED'],
    ['/admin/support-automation-rules', 'SUPPORT_AUTOMATION_ENABLED'],
    ['/admin/knowledge-articles', 'SUPPORT_KNOWLEDGE_BASE_ENABLED'],
    ['/admin/knowledge-article-categories', 'SUPPORT_KNOWLEDGE_BASE_ENABLED'],
];

for (const [resource, gate] of gatedRoutes) {
    const isEnabled = enabled(gate);
    test(`Support rollout gate ${gate}=${isEnabled} protects ${resource}`, { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open(resource);
        const state = await browser.evaluate(() => ({
            heading: document.querySelector('h1')?.textContent?.trim() ?? '',
            text: document.body?.innerText ?? '',
            path: window.location.pathname,
        }));
        if (isEnabled) {
            expect(state.heading.length).toBeGreaterThan(3);
            expect(state.text).not.toMatch(/403 Forbidden|404 Not Found|Internal Server Error/i);
        } else {
            const label = resource.split('/').at(-1)?.replaceAll('-', ' ').toLowerCase() ?? '';
            expect(state.heading.toLowerCase()).not.toBe(label);
            expect(state.text + ' ' + state.path).toMatch(/403|404|forbidden|not found|\/admin(?:\/)?$/i);
        }
    });
}
