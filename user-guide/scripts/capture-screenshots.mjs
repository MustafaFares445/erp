import { chromium } from 'playwright-core'
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const guideRoot = path.resolve(here, '..')
const repoRoot = path.resolve(guideRoot, '..')
const targetFile = path.join(repoRoot, 'tmp', 'user-guide-capture-targets.json')
const reportFile = path.join(repoRoot, 'tmp', 'user-guide-capture-report.json')

const args = new Set(process.argv.slice(2))
const valueArg = (prefix) => process.argv.slice(2).find((arg) => arg.startsWith(prefix))?.slice(prefix.length)

const mode = args.has('--capture') ? 'capture' : args.has('--check') ? 'check' : args.has('--sales-livewire-qa') ? 'sales-livewire-qa' : 'plan'
const moduleFilter = valueArg('--module=')
const idFilter = valueArg('--id=')
const idsFilter = valueArg('--ids=')?.split(',').map((id) => id.trim()).filter(Boolean) ?? []
const localeArg = valueArg('--locale=') ?? 'en'
const limitArg = Number.parseInt(valueArg('--limit=') ?? '0', 10)
const baseUrl = process.env.IERP_GUIDE_DEMO_URL ?? 'http://127.0.0.1:8011'

if (!fs.existsSync(targetFile)) {
  console.error('Missing tmp/user-guide-capture-targets.json. Run npm run docs:capture:targets first.')
  process.exit(1)
}

const locales = localeArg === 'both' ? ['en', 'ar'] : [localeArg]

if (locales.some((locale) => !['en', 'ar'].includes(locale))) {
  console.error('Use --locale=en, --locale=ar or --locale=both.')
  process.exit(1)
}

if (mode === 'capture' && process.env.IERP_GUIDE_CAPTURE_CONFIRM !== '1') {
  console.error('Capture mode is write-enabled. Set IERP_GUIDE_CAPTURE_CONFIRM=1 explicitly before using --capture.')
  process.exit(1)
}

const allTargets = JSON.parse(fs.readFileSync(targetFile, 'utf8'))
let targets = allTargets.filter((target) => target.capture_ready === true)

if (moduleFilter) {
  targets = targets.filter((target) => target.module === moduleFilter)
}

if (idFilter) {
  targets = targets.filter((target) => target.id === idFilter)
}

if (idsFilter.length > 0) {
  const selectedIds = new Set(idsFilter)
  targets = targets.filter((target) => selectedIds.has(target.id))
}

if (limitArg > 0) {
  targets = targets.slice(0, limitArg)
}

const plannedCount = targets.length * locales.length
console.log(`User-guide screenshot ${mode}: ${targets.length} route(s), ${locales.join('+')}, ${plannedCount} page visit(s).`)

for (const target of targets.slice(0, 20)) {
  console.log(`- [${target.module}] ${target.id}: ${target.capture_url}`)
}
if (targets.length > 20) {
  console.log(`... and ${targets.length - 20} more route(s)`)
}

if (mode === 'plan') {
  process.exit(0)
}

const browserCandidates = [
  process.env.IERP_GUIDE_BROWSER,
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
].filter(Boolean)

const executablePath = browserCandidates.find((candidate) => fs.existsSync(candidate))

if (!executablePath) {
  console.error('No supported local Chromium browser was found. Set IERP_GUIDE_BROWSER to an Edge/Chrome executable.')
  process.exit(1)
}

const browser = await chromium.launch({
  executablePath,
  headless: true,
  args: ['--disable-gpu'],
})

const context = await browser.newContext({
  viewport: { width: 1440, height: 1100 },
  colorScheme: 'light',
  locale: 'en-US',
})

const page = await context.newPage()
const report = []

async function settle({ full = false, requireHeading = false } = {}) {
  await page.waitForLoadState('domcontentloaded')
  if (full) {
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {})
    if (requireHeading) {
      await page.locator('h1').first().waitFor({ state: 'visible', timeout: 15000 })
      await waitForOpaqueMain()
    }
    await page.waitForTimeout(1200)
    if (requireHeading) {
      await waitForStableMain()

      if (await page.evaluate(desktopTableProblem)) {
        await page.reload({ waitUntil: 'domcontentloaded' })
        await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {})
        await waitForOpaqueMain()
        await page.waitForTimeout(1200)
        await waitForStableMain()
      }
    }
    return
  }

  await page.waitForTimeout(100)
}

// Filament renders .fi-main-ctn with opacity 0 until Alpine binds opacity:1 (an x-cloak substitute).
// Playwright's isVisible() ignores opacity, so a slow Alpine boot produced sidebar-only screenshots.
const mainIsOpaque = () => {
  const heading = document.querySelector('.fi-main h1')

  for (let element = heading; element && element !== document.documentElement; element = element.parentElement) {
    if (Number.parseFloat(getComputedStyle(element).opacity) < 1) {
      return false
    }
  }

  return heading !== null
}

async function waitForOpaqueMain() {
  try {
    await page.waitForFunction(mainIsOpaque, null, { timeout: 15000 })
  } catch {
    // The single-threaded demo server occasionally stalls an Alpine asset; one reload recovers it.
    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {})
    await page.waitForFunction(mainIsOpaque, null, { timeout: 15000 })
  }
}

// The single-threaded demo server can still be answering a Livewire update after the network looks idle; a
// capture then caught a table mid-morph (one stacked row under duplicated headers). Two identical readings of
// the main region's text and table geometry, 400ms apart, mean the page has settled.
const mainLayoutSignature = () => {
  const main = document.querySelector('.fi-main')
  const tables = [...document.querySelectorAll('.fi-main .fi-ta')].map((table) => {
    const box = table.getBoundingClientRect()

    return [table.querySelectorAll('thead th').length, table.querySelectorAll('tbody tr').length, Math.round(box.width), Math.round(box.height)].join(':')
  })

  return `${main?.innerText.length ?? 0}|${Math.round(main?.getBoundingClientRect().height ?? 0)}|${tables.join(',')}`
}

// A table caught between two Livewire renders can stay put in a hybrid state: the phone-only cell labels show
// and the header row is gone although the viewport is desktop-wide. Report it so the page is reloaded.
const desktopTableProblem = () => {
  if (window.innerWidth < 640) {
    return null
  }

  for (const table of document.querySelectorAll('.fi-main table.fi-ta-table')) {
    const visible = (element) => element !== null && element.getClientRects().length > 0 && getComputedStyle(element).display !== 'none'

    // The label is a child of the cell; a column named "label" also gives its own <td> this class.
    if ([...table.querySelectorAll('td > .fi-ta-cell-label')].some(visible)) {
      return 'a table shows its phone-only cell labels at desktop width'
    }

    if (table.querySelector('tbody tr.fi-ta-row') && !visible(table.querySelector('thead tr:not(.fi-ta-table-stacked-header-row)'))) {
      return 'a table lost its header row at desktop width'
    }
  }

  return null
}

async function waitForStableMain() {
  let previous = await page.evaluate(mainLayoutSignature)

  for (let attempt = 0; attempt < 25; attempt++) {
    await page.waitForTimeout(400)
    const current = await page.evaluate(mainLayoutSignature)

    if (current === previous) {
      return current
    }

    previous = current
  }

  throw new Error('Capture rejected: the main region kept changing for 10 seconds.')
}

async function assertMainContentRendered(moment) {
  const heading = page.locator('.fi-main h1').first()
  if (!await heading.isVisible()) {
    throw new Error(`Capture rejected: the page heading is not visible in the main region (${moment} screenshot).`)
  }

  if (!await page.evaluate(mainIsOpaque)) {
    throw new Error(`Capture rejected: the main region is transparent while Alpine initialises (${moment} screenshot).`)
  }

  const mainTextLength = (await page.locator('.fi-main').first().innerText()).trim().length
  if (mainTextLength < 20) {
    throw new Error(`Capture rejected: the main region is empty (${moment} screenshot).`)
  }

  const tableProblem = await page.evaluate(desktopTableProblem)
  if (tableProblem) {
    throw new Error(`Capture rejected: ${tableProblem} (${moment} screenshot).`)
  }
}


// The user-menu illustration must show the topbar dropdown open; a plain page visit only paints the avatar.
// The panel is teleported to <body>, so it is found as the one dropdown panel that is rendered and fully faded in.
const openDropdownPanelVisible = () => [...document.querySelectorAll('.fi-dropdown-panel')].some((panel) =>
  panel.getClientRects().length > 0 && getComputedStyle(panel).display !== 'none' && Number.parseFloat(getComputedStyle(panel).opacity) === 1,
)

async function openUserMenu() {
  await page.locator('.fi-topbar .fi-user-menu-trigger').first().click()
  await page.waitForFunction(openDropdownPanelVisible, null, { timeout: 10000 })
  await page.waitForTimeout(300)
}

async function selectSearchableFilamentOption(buttonSelector, searchTerm, optionText) {
  const button = page.locator(buttonSelector)
  await button.waitFor({ state: 'visible', timeout: 15000 })

  const controls = await button.getAttribute('aria-controls')
  if (!controls) {
    throw new Error(`Combobox ${buttonSelector} has no aria-controls target.`)
  }

  await button.click()

  const dropdown = page.locator(`#${controls}`)
  const searchInput = dropdown.locator('input').first()
  await searchInput.waitFor({ state: 'visible', timeout: 5000 })
  await searchInput.fill(searchTerm)

  const option = dropdown.getByRole('option', { name: optionText }).first()
  await option.waitFor({ state: 'visible', timeout: 10000 })
  await option.click()
  await page.waitForTimeout(800)
}

async function assertContextualGuideLink(expectedSuffix, contextName) {
  const link = page.locator(`a[href$="${expectedSuffix}"]`).first()

  try {
    await link.waitFor({ state: 'visible', timeout: 5000 })
  } catch {
    throw new Error(`Missing contextual User Guide link after ${contextName}: ${expectedSuffix}`)
  }
}

async function runSalesLivewireQa(locale) {
  const localePrefix = locale === 'ar' ? '/ar' : ''
  await page.goto(`${baseUrl}/locale/${locale}`, { waitUntil: 'domcontentloaded' })
  await settle({ full: true })

  await page.goto(`${baseUrl}/admin/quotations/create`, { waitUntil: 'domcontentloaded' })
  await settle({ full: true })
  await assertContextualGuideLink(`/guide${localePrefix}/screens/sales/quotation`, 'opening Quotation Create')
  await selectSearchableFilamentOption('#form\\.customer_id', 'Al Noor', 'Al Noor Medical Center')
  await assertContextualGuideLink(`/guide${localePrefix}/screens/sales/quotation`, 'Quotation customer Livewire update')
  console.log(`OK  [${locale}] quotation customer Livewire update preserved contextual User Guide link`)

  await page.goto(`${baseUrl}/admin/sales-dashboard`, { waitUntil: 'domcontentloaded' })
  await settle({ full: true })
  await assertContextualGuideLink(`/guide${localePrefix}/screens/sales/dashboard`, 'opening Sales Dashboard')
  await selectSearchableFilamentOption('#filtersForm\\.customerId', 'Al Noor', 'Al Noor Medical Center')
  await assertContextualGuideLink(`/guide${localePrefix}/screens/sales/dashboard`, 'Sales Dashboard customer-filter Livewire update')
  console.log(`OK  [${locale}] sales dashboard customer filter preserved contextual User Guide link`)
}

async function login() {
  await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'domcontentloaded' })
  const email = page.locator('input[type="email"]').first()
  const password = page.locator('input[type="password"]').first()
  await email.waitFor({ state: 'visible', timeout: 15000 })
  await email.fill('demo.admin@ierp.test')
  await password.fill('password')

  const submit = page.locator('button[type="submit"]').first()
  await submit.click()
  await email.waitFor({ state: 'hidden', timeout: 30000 })
  await settle({ full: true })
}

await login()

if (mode === 'sales-livewire-qa') {
  for (const locale of locales) {
    await runSalesLivewireQa(locale)
  }

  await browser.close()
  process.exit(0)
}

for (const locale of locales) {
  await page.goto(`${baseUrl}/locale/${locale}`, { waitUntil: 'domcontentloaded' })
  await settle({ full: true })
  await page.waitForTimeout(1300)

  for (const target of targets) {
    const url = new URL(target.capture_url, baseUrl).toString()
    const startedAt = Date.now()
    let status = null
    let error = null
    let file = null

    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 })
      status = response?.status() ?? null
      await settle({ full: mode === 'capture', requireHeading: mode === 'capture' })

      if (page.url().includes('/admin/login')) {
        throw new Error('Redirected to login while visiting an authenticated capture target.')
      }

      if (status !== null && status >= 400) {
        throw new Error(`HTTP ${status}`)
      }

      if (mode === 'check' && target.module !== 'getting-started' && typeof target.article === 'string') {
        const articleSuffix = `${locale === 'ar' ? '/ar' : ''}${target.article}`
        const contextualGuideLink = page.locator(`a[href$="${articleSuffix}"]`).first()
        if (await contextualGuideLink.count() === 0) {
          throw new Error(`Missing contextual User Guide link ending in ${articleSuffix}`)
        }
      }

      if (mode === 'capture') {
        // A route can return 200 and still lose its page content during a Livewire update.
        await assertMainContentRendered('before')

        // Isolated QA workers must not publish screenshots with worker-only branding.
        const visibleText = await page.locator('body').innerText()
        if (visibleText.includes('IERP QA Worker')) {
          throw new Error('Capture rejected: QA worker branding is visible.')
        }

        if (target.id === 'global-user-menu') {
          await openUserMenu()
        }

        // Hold the image in memory until the page is re-checked: a mid-batch capture once
        // painted only the sidebar although the pre-screenshot heading check had passed.
        const layoutBefore = await page.evaluate(mainLayoutSignature)
        const image = await page.screenshot({ fullPage: false })
        await assertMainContentRendered('after')
        if (target.id === 'global-user-menu' && !await page.evaluate(openDropdownPanelVisible)) {
          throw new Error('Capture rejected: the user menu closed before the screenshot was taken.')
        }
        if (await page.evaluate(mainLayoutSignature) !== layoutBefore) {
          throw new Error('Capture rejected: the main region changed while the screenshot was taken.')
        }

        const dir = path.join(guideRoot, 'public', 'images', locale, target.module)
        fs.mkdirSync(dir, { recursive: true })
        file = path.join(dir, `${target.id}.png`)
        fs.writeFileSync(file, image)
      }

      console.log(`OK  [${locale}] ${target.id} -> ${status ?? 'SPA'}${file ? ` -> ${path.relative(repoRoot, file)}` : ''}`)
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught)
      console.error(`ERR [${locale}] ${target.id}: ${error}`)
    }

    report.push({
      id: target.id,
      module: target.module,
      locale,
      url,
      status,
      duration_ms: Date.now() - startedAt,
      file: file ? path.relative(repoRoot, file).replaceAll('\\\\', '/') : null,
      error,
    })
  }
}

await browser.close()
fs.writeFileSync(reportFile, JSON.stringify(report, null, 2) + '\n')

const failures = report.filter((item) => item.error)
console.log(`Capture ${mode} finished: ${report.length - failures.length}/${report.length} successful.`)
if (failures.length) {
  process.exit(1)
}
