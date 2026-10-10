import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
const routes=['/admin/products','/admin/warehouses','/admin/inventory-lots','/admin/inventory-reservations','/admin/packages','/admin/inventory-alerts'];
for(const locale of ['en','ar'] as const){
  test(`Agent 2 Inventory core list screens retain readable ${locale} headings on mobile`,
    {session:'qa-admin',timeout:120000},async ({app,browser})=>{
    await browser.setViewport({width:390,height:844});
    await app.open('/locale/'+locale);
    for(const url of routes){
      await app.open(url);
      await expect(browser.locator('h1').first()).toBeVisible();
      const state=await browser.evaluate(()=>({
        heading:document.querySelector('h1')?.textContent?.trim()??'',
        width:innerWidth,scrollWidth:document.documentElement.scrollWidth,
        lang:document.documentElement.lang,dir:document.documentElement.dir
      }));
      expect(state.heading.length,url).toBeGreaterThan(2);
      expect(state.lang,url).toBe(locale);
      expect(state.dir,url).toBe(locale==='ar'?'rtl':'ltr');
      expect(state.scrollWidth,url+' page-level overflow').toBeLessThanOrEqual(state.width+8);
    }
  });
}
