import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// Variable form branches must be checked as actual Livewire state transitions.
test('Variant Set count scope exposes its required dependent variant selector', { session: 'qa-admin' }, async ({ app, agent, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/inventory-counts/create');

    await expect(browser.locator('label:has-text("Product Variants")').first()).not.toBeVisible();

    await agent.act('In the Scope dropdown, select Variant Set, then stop. Do not open a new count, do not save, and do not change the warehouse.');
    await expect(browser.locator('label:has-text("Product Variants")').first()).toBeVisible();
    await expect(browser.locator('h1').first()).toContainText('Create Inventory Count');
});

test('Condition Change form shows only fields belonging to the selected document type', { session: 'qa-admin' }, async ({ app, agent, browser }) => {
    await app.open('/locale/en');
    await app.open('/admin/inventory-condition-changes/create');
    await expect(browser.locator('h1').first()).toContainText('Create Inventory Condition Change');

    // Quarantine Disposition is the default and must expose disposition.
    await expect(browser.locator('label:has-text("Disposition")').first()).toBeVisible();

    await agent.act('Change Document Type from Quarantine Disposition to Damage. Do not save or create a document.');
    await expect(browser.locator('label:has-text("Product Variant")').first()).toBeVisible();
    await expect(browser.locator('label:has-text("Disposition")').first()).not.toBeVisible();

    await agent.act('Change Document Type from Damage to Damage Recovery. Do not save or create a document.');
    await expect(browser.locator('label:has-text("Damage Document")').first()).toBeVisible();
    await expect(browser.locator('label:has-text("Product Variant")').first()).not.toBeVisible();

    await agent.act('Change Document Type from Damage Recovery to Disposal. Do not save or create a document.');
    await expect(browser.locator('label:has-text("Authorised By")').first()).toBeVisible();
    await expect(browser.locator('label:has-text("Damage Document")').first()).not.toBeVisible();
});
