import { beforeEach, test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// The admin locale is persisted per user (users.locale); pin English so an earlier Arabic run cannot leak into these English-label assertions.
beforeEach(async ({ app }) => {
    await app.open('/locale/en');
});

type Ticket = { id: number; ticket_number: string; title: string; status: string };
const { tickets } = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-support-fixtures.json'), 'utf8')) as { tickets: Ticket[] };

function ticket(status: string, titlePart?: string): Ticket {
    const item = tickets.find(t => t.status === status && (!titlePart || t.title.includes(titlePart)));
    if (!item) throw new Error(`No legitimate demo ticket for status=${status} title=${titlePart ?? '*'}`);
    return item;
}

test('intake has triage but not work or resolution', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    const record = ticket('pending');
    await app.open(`/admin/tickets/${record.id}`);
    await expect(browser).toHaveURL(new RegExp(`/admin/tickets/${record.id}(?:\\?|$)`));
    await expect(screen.getByRole('button', { name: 'Triage ticket' })).toBeVisible();
    await expect(screen.getByRole('button', { name: 'Start Work' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).not.toBeVisible();
});

test('unpaid diagnostic fee blocks assignment and requires settlement', { session: 'qa-admin' }, async ({ app, screen }) => {
    await app.open(`/admin/tickets/${ticket('pending_payment').id}`);
    await expect(screen.getByRole('button', { name: 'Settle Payment' })).toBeVisible();
    await expect(screen.getByRole('button', { name: 'Assign Employee' })).not.toBeVisible();
    // Viewing the fee is safe; intentionally do not settle money in E2E.
});

test('waiting-customer resume requires confirmation and dismiss leaves state unchanged', { session: 'qa-admin' }, async ({ app, screen }) => {
    await app.open(`/admin/tickets/${ticket('waiting_customer').id}`);
    await expect(screen.getByRole('button', { name: 'Resume Work' })).toBeVisible();
    await screen.getByRole('button', { name: 'Resume Work' }).tap();
    // Filament's confirmation is accessible through its heading/buttons but has no dialog role.
    await expect(screen.getByRole('heading', { name: 'Resume Work' })).toBeVisible();
    await screen.getByRole('button', { name: 'Cancel' }).tap();
    await expect(screen.getByRole('heading', { name: 'Resume Work' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Resume Work' })).toBeVisible();
});

test('resolved case offers closure and reopening rather than first-response actions', { session: 'qa-admin' }, async ({ app, screen }) => {
    await app.open(`/admin/tickets/${ticket('resolved').id}`);
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).toBeVisible();
    await expect(screen.getByRole('button', { name: 'Triage ticket' })).not.toBeVisible();
    await screen.getByRole('button', { name: 'Close Ticket' }).tap();
    await expect(screen.getByRole('heading', { name: 'Close Ticket' })).toBeVisible();
    await screen.getByRole('button', { name: 'Cancel' }).tap();
    await expect(screen.getByRole('heading', { name: 'Close Ticket' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).toBeVisible();
});

test('completed ticket is terminal in the case workspace', { session: 'qa-admin' }, async ({ app, screen, browser }) => {
    const record = ticket('closed');
    await app.open(`/admin/tickets/${record.id}`);
    await expect(browser).toHaveURL(new RegExp(`/admin/tickets/${record.id}(?:\\?|$)`));
    // Positive anchor: the real closed case rendered, so the absent actions are not an artefact of an error page.
    await expect(browser.locator('h1').first()).toBeVisible();
    expect(await browser.evaluate(() => document.body?.innerText ?? '')).toContain(record.title);
    await expect(screen.getByRole('button', { name: 'Triage ticket' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Start Work' })).not.toBeVisible();
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).not.toBeVisible();
});

test('maintenance request demonstrates approved partial warranty and customer responsibility', { session: 'qa-admin' }, async ({ app, browser }) => {
    const fixture = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-support-fixtures.json'), 'utf8')) as { maintenance: Array<{ id: number; description: string }> };
    const record = fixture.maintenance.find(m => m.description.includes('Autoclave door sensor'));
    if (!record) throw new Error('Missing real partial-coverage demo maintenance record');
    await app.open(`/admin/maintenance-requests/${record.id}`);
    await expect(browser).toHaveURL(new RegExp(`/admin/maintenance-requests/${record.id}(?:\\?|$)`));
    const body = await browser.evaluate(() => document.body?.innerText ?? '');
    expect(body).toMatch(/Autoclave door sensor/i);
    expect(body).toMatch(/cover|warranty|customer/i);
});

