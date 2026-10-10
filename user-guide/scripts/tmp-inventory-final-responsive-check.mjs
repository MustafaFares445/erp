import { chromium } from 'playwright-core';
import fs from 'node:fs';

const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-gpu']});
const context=await browser.newContext({viewport:{width:390,height:844}});
const page=await context.newPage();
const base='http://127.0.0.1:8012';
const cases=[
 ['reservation','/admin/inventory-reservations/57'],
 ['lot','/admin/inventory-lots/23'],
 ['operation','/admin/inventory-operations/1'],
 ['warehouse','/admin/warehouses/1'],
];
const results=[];
try {
  await page.goto(base+'/admin/login',{waitUntil:'domcontentloaded',timeout:60000});
  await page.locator('input[type=email]').first().fill('demo.admin@ierp.test');
  await page.locator('input[type=password]').first().fill('password');
  await page.locator('button[type=submit]').first().click();
  await page.locator('input[type=email]').first().waitFor({state:'hidden',timeout:75000});
  for(const locale of ['en','ar']) {
    await page.goto(base+'/locale/'+locale,{waitUntil:'domcontentloaded',timeout:45000});
    for(const [width,height] of [[390,844],[768,1024]]) {
      await page.setViewportSize({width,height});
      for(const [name,route] of cases) {
        const item={locale,width,name};
        try{
          const response=await page.goto(base+route,{waitUntil:'domcontentloaded',timeout:55000});
          await page.locator('.fi-main h1').first().waitFor({state:'visible',timeout:45000});
          await page.waitForTimeout(450);
          const info=await page.evaluate(()=>{
            const text=document.querySelector('.fi-main')?.innerText??'';
            const errorKeys=[...new Set(text.match(/inventory_view_ux\.[a-z0-9_.]+/gi)??[])];
            return {
              overflow:document.documentElement.scrollWidth-innerWidth,
              panel:text.includes('Operational health')||text.includes('الحالة التشغيلية'),
              untranslated:errorKeys,
              h1:document.querySelector('.fi-main h1')?.innerText.trim().slice(0,65)??'',
              hasArabic:/[\u0600-\u06FF]/.test(text),
            }
          });
          Object.assign(item,info,{status:response?.status()??0});
          item.ok=item.status===200 && item.h1.length>0 && item.panel && !item.untranslated.length && item.overflow<=8 && (locale==='en'||item.hasArabic);
        }catch(err){item.ok=false;item.error=String(err.message||err).slice(0,220);}
        results.push(item);
        console.log(JSON.stringify(item));
      }
    }
  }
}finally{await browser.close();}
fs.writeFileSync('C:/laragon/www/ierp-new/tmp/inventory-reviewed-responsive-results.json',JSON.stringify(results,null,2));
const failures=results.filter(x=>!x.ok);
console.log('FINAL_INVENTORY_RESPONSIVE '+(results.length-failures.length)+'/'+results.length);
if(failures.length)process.exit(2);
