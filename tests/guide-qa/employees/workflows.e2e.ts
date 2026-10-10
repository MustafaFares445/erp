import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Employees list presents active, inactive and archived views', {session:'qa-admin'}, async ({app,browser})=>{
    await app.open('/admin/employees');
    await expect(browser.locator('h1').first()).toBeVisible();
    // The employee list uses a stacked content-grid layout, so rows render as list items, not a <table>.
    await expect(browser.locator('a[href*="/admin/employees/"][href$="/edit"]').first()).toBeVisible();
    const rendered=await browser.locator('body').first().textContent();
    expect(rendered ?? '').toMatch(/Active|نشط/i);
    expect(rendered ?? '').toMatch(/Inactive|غير نشط/i);
    expect(rendered ?? '').toMatch(/Archived|مؤرشف/i);
});

test('Visit planning list and calendar can be reached', {session:'qa-admin'}, async ({app,browser})=>{
    await app.open('/admin/visits');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/employees/visit"]').first()).toBeVisible();
    await app.open('/admin/visits/calendar');
    await expect(browser.locator('h1').first()).toBeVisible();
});

test('Employees mobile viewport retains task operations', {session:'qa-admin'}, async ({app,browser})=>{
    await browser.setViewport({width:390,height:844});
    await app.open('/admin/tasks');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/employees/task"]').first()).toBeVisible();
    const overflow=await browser.evaluate(() => document.documentElement.scrollWidth-window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(3);
});

test('Salary review remains in Employees ownership', {session:'qa-admin'}, async ({app,browser})=>{
    await app.open('/admin/salary-calculations');
    await expect(browser.locator('h1').first()).toBeVisible();
    await expect(browser.locator('a[href$="/screens/employees/salary-calculation"]').first()).toBeVisible();
});
