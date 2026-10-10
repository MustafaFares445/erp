import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

// Guarded DemoMonthSeeder scenario IDs confirmed in AGENT_2_SEEDED_SCENARIOS.json.
test('Only an active reservation displays the authorized release action', {
  session:'qa-admin',timeout:100000
},async ({app,browser})=>{
  await app.open('/locale/en');
  for(const [id,status,shouldRelease] of [[50,'Active',true],[1,'Consumed',false],[55,'Released',false]] as const){
    await app.open('/admin/inventory-reservations/'+id);
    await expect(browser.locator('h1').first()).toBeVisible();
    const info=await browser.evaluate(()=>({
      actions:[...document.querySelectorAll('button')].filter(x=>x.getClientRects().length>0)
        .map(x=>x.textContent?.trim()??'').filter(Boolean),
      body:document.body?.innerText??''
    }));
    expect(info.body.toLowerCase(),String(id)).toContain(status.toLowerCase());
    expect(info.actions.some(x=>/release( reservation)?/i.test(x)),String(id)).toBe(shouldRelease);
  }
});
