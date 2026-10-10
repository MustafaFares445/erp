import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const fixtureFile = join(process.cwd(), 'tmp/qa-advanced-fixtures.json');
const scenario = existsSync(fixtureFile)
    ? JSON.parse(readFileSync(fixtureFile, 'utf8')) as Record<string, number>
    : null;

const workspaces: Array<[string, string, string]> = [
    ['support-team', 'support-teams', 'Technical Service Desk'],
    ['support-skill', 'support-skills', 'Calibration and equipment diagnostics'],
    ['support-queue', 'support-queues', 'Calibration follow-up queue'],
    ['support-routing-rule', 'support-routing-rules', 'Calibration service triage - demo'],
    ['support-automation-rule', 'support-automation-rules', 'SLA breach internal note - demo'],
    ['knowledge-article-category', 'knowledge-article-categories', 'Equipment calibration and diagnostics'],
    ['knowledge-article', 'knowledge-articles', 'Verify calibration preconditions before inspection'],
];

for (const [key, resource, value] of scenario ? workspaces : []) {
    test(`authorized isolated demo edit is accessible: ${resource}`, { session: 'qa-admin' }, async ({ app, browser }) => {
        const id = scenario?.[key];
        if (typeof id !== 'number' || !Number.isInteger(id) || id < 1) throw new Error(`No genuine seeded record for ${key}`);
        const path = `/admin/${resource}/${id}/edit`;
        await app.open(path);
        await expect(browser).toHaveURL(new RegExp(path.replaceAll('/', '\\/') + '(?:\\?|$)'));
        const rendered = await browser.evaluate(() => ({
            heading: document.querySelector('h1')?.textContent?.trim() ?? '',
            fields: Array.from(document.querySelectorAll('input,textarea')).map(el => (el as HTMLInputElement).value),
            text: document.body.innerText ?? '',
        }));
        expect(rendered.heading).toMatch(/edit|modify/i);
        expect(rendered.fields.some(x => x.includes(value)), `Missing prefilled ${resource} data`).toBe(true);
        expect(rendered.text).not.toMatch(/403 Forbidden|404 Not Found|Internal Server Error/i);
    });
}
