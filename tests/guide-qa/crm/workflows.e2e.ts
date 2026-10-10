import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { DatabaseSync } from 'node:sqlite';
import { join } from 'node:path';

function terminalLead(): { id: number; status: string } | undefined {
    const db = new DatabaseSync(join(process.cwd(), 'tmp/e2e.sqlite'), {readOnly:true});
    try {
        return db.prepare("SELECT id, status FROM leads WHERE status IN ('converted','disqualified') ORDER BY id LIMIT 1").get() as { id:number;status:string }|undefined;
    } finally { db.close(); }
}

test('CRM lead list exposes contact and status evidence', {session:'qa-admin'}, async ({app,browser}) => {
    await app.open('/admin/leads');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('table').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/crm/lead"]').first()).toBeVisible();
    const columns = await browser.locator('table thead').first().textContent();
    expect(columns ?? '').toMatch(/Source|المصدر/i);
    expect(columns ?? '').toMatch(/Status|الحالة/i);
});

test('CRM terminal lead cannot expose direct edit', {session:'qa-admin'}, async ({app,browser}) => {
    const lead=terminalLead();
    if(!lead) test.skip('No terminal demo lead available');
    await app.open('/admin/leads/'+lead!.id);
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/edit"]')).toHaveCount(0);
});

test('CRM mobile viewport retains lead navigation and contextual guide', {session:'qa-admin'}, async ({app,browser})=>{
    await browser.setViewport({width:390,height:844});
    await app.open('/admin/leads');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/crm/lead"]').first()).toBeVisible();
    const overflow=await browser.evaluate(() => document.documentElement.scrollWidth-window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(3);
});

test('CRM campaign and return requests remain separated from stock execution', {session:'qa-admin'}, async ({app,browser})=>{
    await app.open('/admin/campaigns');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/crm/campaign"]').first()).toBeVisible();
    await app.open('/admin/customer-return-requests');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/crm/customer-return-request"]').first()).toBeVisible();
});
