import {chromium} from 'playwright-core';
import fs from 'node:fs';

const root='C:/laragon/www/ierp-new';
const targets=JSON.parse(fs.readFileSync(root+'/tmp/user-guide-capture-targets.json','utf8'))
  .filter(t=>/^inventory-.*-view$/.test(t.id))
  .map(t=>({id:t.id,path:t.id==='inventory-package-view'?'/admin/packages/1':t.capture_url}));
const batch=Number(process.argv.find(x=>x.startsWith('--batch='))?.split('=')[1]||0);
const selected=targets.filter((_,i)=>i%2===batch);
const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-gpu']});
const ctx=await browser.newContext({viewport:{width:390,height:844},colorScheme:'light'});
const p=await ctx.newPage();
const results=[];
try{
 await p.goto('http://127.0.0.1:8012/admin/login',{waitUntil:'domcontentloaded',timeout:70000});
 await p.locator('input[type=email]').first().fill('demo.admin@ierp.test');
 await p.locator('input[type=password]').first().fill('password');
 await p.locator('button[type=submit]').first().click();
 await p.locator('input[type=email]').first().waitFor({state:'hidden',timeout:80000});
 for(const locale of ['en','ar']){
  await p.goto('http://127.0.0.1:8012/locale/'+locale,{waitUntil:'domcontentloaded',timeout:45000});
  for(const [id,path] of selected.map(x=>[x.id,x.path])){
   const result={locale,id,path};
   if(!path){result.error='missing_route';results.push(result);console.log('FAIL '+JSON.stringify(result));continue;}
   const problems=[];
   const onerror=e=>problems.push(e.message);
   p.on('pageerror',onerror);
   try{
    const res=await p.goto('http://127.0.0.1:8012'+path,{waitUntil:'domcontentloaded',timeout:60000});
    await p.locator('.fi-main h1').first().waitFor({state:'visible',timeout:60000});
    await p.waitForTimeout(350);
    Object.assign(result, await p.evaluate(()=>{
     const main=document.querySelector('.fi-main');
     const body=main?.innerText??'';
     const raw=[...new Set((body.match(/(?:inventory_view_ux|inventory_view_explanations|admin\.[a-z_]+)\.[a-z0-9_.]+/gi)??[]))].slice(0,12);
     const help=document.querySelectorAll('[data-term-help]').length;
     return {title:main?.querySelector('h1')?.innerText.slice(0,90)||'',diagnostic:!!main?.querySelector('[data-inventory-health]'),
      help,summary:(main?.querySelector('[data-inventory-health]')?.innerText||'').slice(0,190),
      untranslated:raw,overflow:document.documentElement.scrollWidth-innerWidth,hasArabic:/[\u0600-\u06ff]/.test(body),
      informationSections:main?.querySelectorAll('section').length??0};
    }));
    result.http=res?.status();
    result.jsErrors=problems.slice(0,4);
    const isSetupView=['inventory-package-type-view','inventory-product-variant-view'].includes(id);
    const setupTitle=locale==='en' ? (id==='inventory-package-type-view'?'Package type settings':'Product identity and tracking') : (id==='inventory-package-type-view'?'إعدادات نوع الطرد':'هوية متغير المنتج والتتبع');
    const setupValid=isSetupView && (await p.locator('.fi-main').innerText()).includes(setupTitle) && !p.url().includes('/admin/products/');
    result.ok=result.http===200 && result.title.length>2 && (isSetupView ? setupValid:(result.diagnostic && result.help>0)) &&
        !result.untranslated.length && result.overflow<=8 && !problems.length && (locale==='en'||result.hasArabic);
    if(result.ok && id==='inventory-outbound-fulfillment-view'){
      const helpBefore=await p.locator('[data-term-help]').first().getAttribute('open');
      await p.locator('[data-term-help] > summary').first().click();
      result.helpOpens=await p.locator('[data-term-help]').first().evaluate(x=>x.open);
      result.ok &&=result.helpOpens&&!helpBefore;
    }
   }catch(e){result.ok=false;result.error=String(e.message||e).slice(0,180);}
   p.off('pageerror',onerror);
   results.push(result);
   console.log((result.ok?'OK ':'FAIL ')+JSON.stringify(result));
  }
 }
}finally{await browser.close();}
fs.mkdirSync(root+'/tmp/inventory-ux-audit',{recursive:true});
fs.writeFileSync(root+'/tmp/inventory-ux-audit/views-batch-'+batch+'.json',JSON.stringify(results,null,2)+'\n');
const fails=results.filter(x=>!x.ok);
console.log('AUDIT_VIEW_BATCH '+(results.length-fails.length)+'/'+results.length);
if(fails.length)process.exitCode=2;
