import { beforeEach, test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// The admin locale is persisted per user (users.locale); pin English so an earlier Arabic run cannot leak into these English-label assertions.
beforeEach(async ({ app }) => {
    await app.open('/locale/en');
});

type Job = { id: number; description: string; status: string };
const { maintenance } = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-support-fixtures.json'), 'utf8')) as { maintenance: Job[] };
function realJob(needle: string): Job {
    const item = maintenance.find(x => x.description.includes(needle));
    if (!item) throw new Error('Missing worker-only demo job: ' + needle);
    return item;
}

test('commissioned installation keeps its maintenance and equipment history visible', { session: 'qa-admin' }, async ({ app, browser }) => {
    const item = realJob('[DEMO-INSTALL]');
    await app.open(`/admin/maintenance-requests/${item.id}`);
    await expect(browser).toHaveURL(new RegExp(`/admin/maintenance-requests/${item.id}(?:\\?|$)`));
    const state = await browser.evaluate(() => document.body.innerText ?? '');
    expect(state).toMatch(/Install and commission the dental sintering furnace/);
    expect(state).toMatch(/Installation|Commissioning/i);
    expect(state).toMatch(/Warranty|Customer/i);
});

test('completed calibration shows measurements and service context', { session: 'qa-admin' }, async ({ app, browser }) => {
    const item = realJob('[DEMO-CALIB] Annual temperature');
    await app.open(`/admin/maintenance-requests/${item.id}`);
    const state = await browser.evaluate(() => document.body.innerText ?? '');
    expect(state).toMatch(/Annual temperature calibration/);
    expect(state).toMatch(/Calibration/i);
    expect(state).toMatch(/Certificate|Measurements|Result|calibration/i);
});

test('failed calibration has linked corrective follow-up, not successful repair completion', { session: 'qa-admin' }, async ({ app, browser }) => {
    const item = realJob('Follow-up to failed calibration');
    await app.open(`/admin/maintenance-requests/${item.id}`);
    const state = await browser.evaluate(() => document.body.innerText ?? '');
    expect(state).toMatch(/bearing replacement|follow-up/i);
    expect(state).toMatch(/Ready for repair|Repair/i);
    expect(state).not.toMatch(/404 Not Found|Internal Server Error/i);
});

for (const [section, expected] of [
    ['service_desk', /SLA|Response|Resolution|Compliance/i],
    ['equipment_lifecycle', /Installation|Calibration|Loaner|RMA/i],
    ['financial', /Service margin|Revenue|Cost|Financial/i],
] as const) {
    test(`authorized Support report loads actual ${section} section`, { session: 'qa-admin' }, async ({ app, browser }) => {
        await app.open(`/admin/support-reports?section=${section}`);
        const state = await browser.evaluate(() => ({ text: document.body.innerText ?? '', heading: document.querySelector('h1')?.textContent ?? '' }));
        expect(state.heading).toMatch(/Support Reports/i);
        expect(state.text).toMatch(expected);
        expect(state.text).not.toMatch(/403 Forbidden|404 Not Found|Internal Server Error/i);
    });
}
