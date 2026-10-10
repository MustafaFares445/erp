import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

for(const locale of ['en','ar'] as const) {
 test(`Legacy Product Variant View redirects to canonical parent Variants tab in ${locale}`,
 {session:'qa-admin',timeout:90000},async ({app,browser})=>{
   await app.open('/locale/'+locale);
   await app.open('/admin/product-variants/1');
   await expect(browser).toHaveURL(/\/admin\/products\/1\/variants(?:\?|$)/);
   await expect(browser.locator('h1').first()).toBeVisible();
   await expect(browser.locator('html')).toHaveAttribute('dir',locale==='ar'?'rtl':'ltr');
 });
}
