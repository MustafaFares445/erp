import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

const { tickets } = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-support-fixtures.json'), 'utf8')) as {
    tickets: Array<{ id: number; status: string; title: string }>;
};

test('real browser transition: assigned case to completed resolution', { session: 'qa-admin' }, async ({ app, browser, screen }) => {
    await app.open('/locale/en');
    const caseRecord = tickets.find(x => x.status === 'assigned' && x.title.includes('Barcode scanner'));
    if (!caseRecord) throw new Error('Expected a legitimate assigned support demo case');
    await app.open(`/admin/tickets/${caseRecord.id}`);
    await expect(screen.getByRole('button', { name: 'Start Work' })).toBeVisible();

    await screen.getByRole('button', { name: 'Start Work' }).tap();
    await expect(screen.getByRole('heading', { name: 'Start Work' })).toBeVisible();
    await screen.getByRole('button', { name: 'Confirm' }).tap();
    // Primary header actions must update on the live page without manual refresh.
    await expect(screen.getByRole('button', { name: 'Resolve Ticket' })).toBeVisible();
    let text = await browser.evaluate(() => document.querySelector('[data-testid="ticket-case-workspace"]')?.textContent ?? '');
    expect(text).toMatch(/In progress|Active support/i);

    await screen.getByRole('button', { name: 'Resolve Ticket' }).tap();
    await expect(screen.getByRole('heading', { name: 'Resolve Ticket' })).toBeVisible();
    await screen.getByRole('textbox', { name: /Resolution summary/ }).fill('QA test: customer barcode synchronization restored and verified on test device.');
    await screen.getByRole('button', { name: 'Confirm' }).tap();
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).toBeVisible();

    await screen.getByRole('button', { name: 'Close Ticket' }).tap();
    await expect(screen.getByRole('heading', { name: 'Close Ticket' })).toBeVisible();
    await screen.getByRole('button', { name: 'Confirm' }).tap();
    await expect(screen.getByRole('button', { name: 'Close Ticket' })).not.toBeVisible();
    text = await browser.evaluate(() => document.querySelector('[data-testid="ticket-case-workspace"]')?.textContent ?? '');
    expect(text).toMatch(/Closed|Completion/i);

    const database = new DatabaseSync(join(process.cwd(), 'tmp/e2e.sqlite'), { readOnly: true });
    try {
        expect(database.prepare('SELECT status FROM tickets WHERE id = ?').get(caseRecord.id)?.status).toBe('closed');
    } finally {
        database.close();
    }
});
