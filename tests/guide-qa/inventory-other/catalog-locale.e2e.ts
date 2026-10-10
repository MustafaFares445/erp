import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('Arabic product supplier references display a localized relationship title after Livewire page load',
  {session:'qa-admin',timeout:90000},async ({app,browser})=>{
    await app.open('/locale/ar');
    await app.open('/admin/products/1/vendors');
    const heading=browser.locator('h1').first();
    await expect(heading).toBeVisible();
    await expect(heading).not.toContainText('Supplier Product References');
    const text=await heading.textContent();
    expect(text??'').toMatch(/[\u0600-\u06FF]/);
    await expect(browser.locator('html')).toHaveAttribute('dir','rtl');
  });
