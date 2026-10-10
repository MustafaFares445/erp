import { chromium } from 'playwright-core'
import fs from 'node:fs'
import path from 'node:path'

const root = 'C:/laragon/www/ierp-new'
const base = 'http://127.0.0.1:8011'
const targetIds = [
  'inventory-inventory-count-index','inventory-inventory-count-create','inventory-inventory-count-view',
  'inventory-adjustment-index','inventory-adjustment-create','inventory-adjustment-view','inventory-adjustment-edit',
  'inventory-inventory-correction-index','inventory-inventory-correction-view',
  'inventory-return-index','inventory-return-view',
  'inventory-inventory-condition-change-index','inventory-inventory-condition-change-create','inventory-inventory-condition-change-view',
]
const targets = JSON.parse(fs.readFileSync(path.join(root,'tmp/user-guide-capture-targets.json'),'utf8'))
  .filter(t => targetIds.includes(t.id))
const browser = await chromium.launch({
  executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  headless: true,
  args: ['--disable-gpu'],
})
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, colorScheme: 'light' })
const page = await context.newPage()
const observations = []
let currentErrors = []
page.on('pageerror', err => currentErrors.push('pageerror: '+err.message))
page.on('console', msg => { if (msg.type()==='error') currentErrors.push('console: '+msg.text()) })

async function settle({ requireHeading = true } = {}) {
  await page.waitForLoadState('domcontentloaded')
  await page.waitForLoadState('networkidle', {timeout:5000}).catch(()=>{})
  if (requireHeading) {
    await page.locator('h1').first().waitFor({state:'visible',timeout:15000})
  }
  await page.waitForTimeout(700)
}

async function login() {
  await page.goto(base+'/admin/login',{waitUntil:'domcontentloaded'})
  await page.locator('input[type=email]').fill('demo.admin@ierp.test')
  await page.locator('input[type=password]').fill('password')
  await page.locator('button[type=submit]').click()
  await page.locator('input[type=email]').waitFor({state:'hidden',timeout:30000})
  await settle({ requireHeading: false })
}

console.log('Phase 4 rendered QA starting')
await login()
console.log('Authenticated demo admin')

for (const locale of ['en','ar']) {
  await page.goto(base+'/locale/'+locale,{waitUntil:'domcontentloaded'})
  await page.waitForTimeout(400)
  for (const target of targets) {
    currentErrors = []
    await page.goto(new URL(target.capture_url,base).toString(),{waitUntil:'domcontentloaded',timeout:30000})
    await settle()
    const h1 = (await page.locator('h1').first().innerText()).trim()
    const headerActions = await page.locator('.fi-header-actions-ctn:visible button:visible, .fi-header-actions-ctn:visible a:visible')
      .evaluateAll(els => els.map(e => (e.innerText||e.textContent||'').replace(/\s+/g,' ').trim()).filter(Boolean))
    const buttons = await page.locator('button:visible').evaluateAll(els => els.map(e => (e.innerText||e.textContent||'').replace(/\s+/g,' ').trim()).filter(Boolean))
    const labels = await page.locator('label:visible').evaluateAll(els => els.map(e => (e.innerText||e.textContent||'').replace(/\s+/g,' ').trim()).filter(Boolean))
    const overflow = await page.evaluate(() => ({innerWidth:window.innerWidth,scrollWidth:document.documentElement.scrollWidth}))
    const dir = await page.locator('html').getAttribute('dir')
    const body = (await page.locator('body').innerText()).replace(/\s+/g,' ').trim().slice(0,12000)
    const previewDir = path.join(root,'tmp/user-guide-qa-phase-4/previews',locale)
    fs.mkdirSync(previewDir,{recursive:true})
    const file = path.join(previewDir,target.id+'.jpg')
    await page.screenshot({path:file,type:'jpeg',quality:85,fullPage:false})
    observations.push({locale,id:target.id,url:page.url(),h1,headerActions,buttons,labels,overflow,dir,errors:[...new Set(currentErrors)],body,file})
    console.log('QA',locale,target.id,h1,'actions=',JSON.stringify(headerActions),'errors=',currentErrors.length)
  }
}
await browser.close()
fs.writeFileSync(path.join(root,'tmp/user-guide-qa-phase-4/rendered-observations.json'),JSON.stringify(observations,null,2)+'\n')
