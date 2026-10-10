import { beforeEach, test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// The admin locale is persisted per user (users.locale); pin English so an earlier Arabic run cannot leak into these English-label assertions.
beforeEach(async ({ app }) => {
    await app.open('/locale/en');
});

const { maintenance } = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-support-fixtures.json'), 'utf8')) as {
    maintenance: Array<{ id: number; status: string; description: string }>;
};
const job = (description: string) => {
    const record = maintenance.find(x => x.description.includes(description));
    if (!record) throw new Error('Missing legitimate demo job ' + description);
    return record;
};

test('partial warranty repair cannot be approved before accepted customer quotation', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    const record = job('Autoclave door sensor');
    await app.open(`/admin/maintenance-requests/${record.id}`);
    await expect(screen.getByRole('button', { name: /Customer Approved.*Ready for Repair/ })).toBeVisible();
    await expect(screen.getByRole('button', { name: 'Create Customer Quotation' })).toBeVisible();
    await screen.getByRole('button', { name: /Customer Approved.*Ready for Repair/ }).tap();
    await expect(screen.getByRole('heading', { name: /Customer Approved.*Ready for Repair/ })).toBeVisible();
    await screen.getByRole('button', { name: 'Confirm' }).tap();
    // Livewire action resolution is asynchronous; wait for its actual error toast.
    await expect(screen.getByRole('heading', { name: 'Repair cannot start yet' })).toBeVisible();
    const body = await browser.evaluate(() => document.body.innerText ?? '');
    expect(body).toMatch(/quotation must be accepted/i);
    expect(body).toMatch(/Awaiting approval/i);
});

test('ready-for-repair shows start action, but not completed billing shortcuts', { session: 'qa-admin' }, async ({ app, screen }) => {
    await app.open(`/admin/maintenance-requests/${job('Foot pedal responds intermittently').id}`);
    await expect(screen.getByRole('button', { name: 'Start Repair' })).toBeVisible();
    await expect(screen.getByRole('button', { name: 'Create Final Invoice' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Complete Maintenance' })).not.toBeVisible();
});

test('closed warranty-covered job hides direct execution actions', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    const record = job('Handpiece loses torque under sustained load');
    await app.open(`/admin/maintenance-requests/${record.id}`);
    // Positive anchor: the real closed record rendered, so the absent actions are not an artefact of an error page.
    await expect(browser).toHaveURL(new RegExp(`/admin/maintenance-requests/${record.id}(?:\\?|$)`));
    expect(await browser.evaluate(() => document.body?.innerText ?? '')).toMatch(/Handpiece loses torque under sustained load/);
    await expect(screen.getByRole('button', { name: 'Start Repair' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Send to QA' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Complete Maintenance' })).not.toBeVisible();
});
