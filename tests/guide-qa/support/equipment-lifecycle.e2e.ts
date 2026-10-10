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
function record(text: string) {
    const found = maintenance.find(x => x.description.includes(text));
    if (!found) throw new Error('Missing installed demo work: ' + text);
    return found;
}

test('installed customer unit exposes one Support-owned installation and commissioning workspace', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    await app.open(`/admin/maintenance-requests/${record('[DEMO-INSTALL] Install and commission').id}`);
    await expect(screen.getByRole('tab', { name: 'Installation & Commissioning' })).toBeVisible();
    await expect(screen.getByRole('tab', { name: 'Calibration' })).not.toBeVisible();
    const body = await browser.evaluate(() => document.body.innerText ?? '');
    expect(body).toMatch(/Install and commission the dental sintering furnace/i);
});

test('calibration service owns measurements, result and certificate, not installation', { session: 'qa-admin' }, async ({ app, screen }) => {
    await app.open(`/admin/maintenance-requests/${record('[DEMO-CALIB] Annual temperature calibration').id}`);
    await expect(screen.getByRole('tab', { name: 'Calibration' })).toBeVisible();
    await expect(screen.getByRole('tab', { name: 'Installation & Commissioning' })).not.toBeVisible();
    await screen.getByRole('tab', { name: 'Calibration' }).tap();
    await expect(screen.getByRole('columnheader', { name: 'Result' }).first()).toBeVisible();
    await expect(screen.getByRole('columnheader', { name: 'Certificate' })).toBeVisible();
});

test('failed calibration remains a visible failure with a separate follow-up repair', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    await app.open(`/admin/maintenance-requests/${record('[DEMO-CALIB] Speed accuracy validation').id}`);
    await expect(screen.getByRole('tab', { name: 'Calibration' })).toBeVisible();
    await screen.getByRole('tab', { name: 'Calibration' }).tap();
    const state = await browser.evaluate(() => document.body.innerText ?? '');
    expect(state).toMatch(/Failed|failure/i);
    const followup = record('Follow-up to failed calibration');
    await app.open(`/admin/maintenance-requests/${followup.id}`);
    await expect(browser).toHaveURL(new RegExp(`/admin/maintenance-requests/${followup.id}(?:\\?|$)`));
    await expect(screen.getByRole('button', { name: 'Start Repair' })).toBeVisible();
});
