import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(import.meta.dirname, '..')
const repo = path.resolve(root, '..')
const readJson = (name) => JSON.parse(fs.readFileSync(path.join(root, 'content', name), 'utf8'))
const manifest = readJson('admin-manifest.json')
const coverage = readJson('coverage.json')
const screenshots = readJson('screenshots.manifest.json')
const resourcePurposes = readJson('resource-purpose.json')
const verifiedScreenshots = readJson('verified-screenshots.json')

const failures = []
const expect = (condition, message) => {
  if (!condition) failures.push(message)
}

const sourceFingerprint = (files) => {
  const hash = crypto.createHash('sha256')
  for (const relative of files) {
    const absolute = path.join(repo, ...relative.split('/'))
    if (!fs.existsSync(absolute)) {
      failures.push(`Missing guide source file: ${relative}`)
      continue
    }
    hash.update(relative)
    hash.update(Buffer.from([0]))
    hash.update(fs.readFileSync(absolute))
    hash.update(Buffer.from([0]))
  }
  return hash.digest('hex')
}

expect(manifest.length === 9, `Expected 9 dashboard modules, found ${manifest.length}`)
expect(coverage.workflow_count >= 21, `Expected at least 21 workflows, found ${coverage.workflow_count}`)
expect(coverage.article_count >= 100, `Expected at least 100 screen articles, found ${coverage.article_count}`)

const screenshotIds = new Set()
for (const shot of screenshots) {
  expect(!screenshotIds.has(shot.id), `Duplicate screenshot id: ${shot.id}`)
  screenshotIds.add(shot.id)
  expect(shot.capture_status === 'planned' || shot.capture_status === 'captured', `Invalid capture status: ${shot.id}`)
  expect(Array.isArray(shot.locales) && shot.locales.includes('en') && shot.locales.includes('ar'), `Screenshot must cover EN/AR: ${shot.id}`)
  expect(typeof shot.route_uri === 'string' && shot.route_uri.length > 0, `Screenshot is missing capture route metadata: ${shot.id}`)
  expect(Array.isArray(shot.route_parameters), `Screenshot is missing route parameter metadata: ${shot.id}`)
  if (Array.isArray(shot.route_parameters)) {
    const supportedParameters = shot.route_parameters.length === 0
      || (shot.route_parameters.length === 1 && shot.route_parameters[0] === 'record')
    expect(supportedParameters, `Screenshot has unsupported capture route parameters: ${shot.id}`)
    if (shot.route_parameters.includes('record')) {
      expect(typeof shot.model === 'string' && shot.model.length > 0, `Record screenshot is missing model metadata: ${shot.id}`)
    }
  }
}

const expectedPages = new Set()
const transferCreation = screenshots.find((shot) => shot.id === 'inventory-inventory-operation-create')
expect(transferCreation?.capture_query?.operation_type === 'internal_transfer', 'Transfer creation capture requires its operation_type context')

for (const [id, images] of Object.entries(verifiedScreenshots)) {
  const screenshot = screenshots.find((shot) => shot.id === id)
  expect(Boolean(screenshot), `Verified image references unknown screenshot id: ${id}`)
  for (const [locale, image] of Object.entries(images)) {
    const validPath = ['en', 'ar'].includes(locale)
      && typeof image === 'string'
      && image.startsWith(`/images/${locale}/`)
      && !image.includes('..')
    expect(validPath, `Invalid verified screenshot path: ${id}/${locale}`)
    if (validPath) {
      expect(fs.existsSync(path.join(root, 'public', image)), `Missing verified screenshot file: ${id}/${locale}`)
    }
    expect(screenshot?.images?.[locale] === image, `Regeneration lost verified screenshot: ${id}/${locale}`)
  }
  if (images.en && images.ar) {
    expect(screenshot?.capture_status === 'captured', `Regeneration lost capture status: ${id}`)
  }
}

const addPages = (entry) => {
  for (const page of entry.pages ?? []) {
    expectedPages.add(`${entry.class}::${page.key}`)
  }
}

const expectedClasses = new Set()
for (const group of manifest) {
  for (const item of group.items) {
    expectedClasses.add(item.class)
    addPages(item)
    for (const tab of item.tabs ?? []) {
      expectedClasses.add(tab.class)
      addPages(tab)
    }
    for (const tool of item.tools ?? []) {
      if (typeof tool.class === 'string' && tool.class.startsWith('App\\')) expectedClasses.add(tool.class)
    }
  }
  for (const contextual of group.contextual ?? []) {
    expectedClasses.add(contextual.class)
    addPages(contextual)
  }
}

const coveredClasses = new Set(coverage.articles.map((article) => article.class))
for (const className of expectedClasses) {
  expect(coveredClasses.has(className), `Missing guide article for dashboard class: ${className}`)
}

const purposeKey = (article) => {
  if (article.slug !== 'dashboard') return article.slug
  return {
    sales: 'sales-dashboard',
    accounting: 'accounting-dashboard',
    inventory: 'inventory-dashboard',
    vendors: 'purchasing-dashboard',
    crm: 'crm-dashboard',
    employees: 'employees-dashboard',
    support: 'support-dashboard',
  }[article.module] ?? article.slug
}

const coveredPages = new Set()
for (const article of coverage.articles) {
  expect(Array.isArray(article.source_files), `Missing source_files metadata: ${article.class}`)
  expect(typeof article.source_fingerprint === 'string', `Missing source_fingerprint metadata: ${article.class}`)
  if (Array.isArray(article.source_files) && typeof article.source_fingerprint === 'string') {
    const currentFingerprint = sourceFingerprint(article.source_files)
    expect(currentFingerprint === article.source_fingerprint, `Generated guide content is stale for: ${article.class}`)
  }

  const enPath = path.join(root, article.article_en.replace(/^\//, '') + '.md')
  const arPath = path.join(root, article.article_ar.replace(/^\//, '') + '.md')
  expect(fs.existsSync(enPath), `Missing EN article: ${article.article_en}`)
  expect(fs.existsSync(arPath), `Missing AR article: ${article.article_ar}`)
  if (!fs.existsSync(enPath) || !fs.existsSync(arPath)) continue

  const enText = fs.readFileSync(enPath, 'utf8')
  const arText = fs.readFileSync(arPath, 'utf8')
  for (const [locale, articleText] of [['en', enText], ['ar', arText]]) {
    const overridePath = path.join(root, 'content', 'overrides', locale, article.module, `${article.slug}.md`)
    if (!fs.existsSync(overridePath)) continue
    const authoredBody = fs.readFileSync(overridePath, 'utf8').replaceAll('\r\n', '\n').trim()
    const generatedBody = articleText.replaceAll('\r\n', '\n').split('\n---\n').slice(1).join('\n---\n').trim()
    expect(generatedBody === authoredBody, `Generated article is stale for authored guidance: ${locale}/${article.module}/${article.slug}`)
  }
  const authoredPurpose = resourcePurposes[purposeKey(article)]
  expect(Boolean(authoredPurpose?.en), `Missing authored EN purpose: ${article.module}/${article.slug}`)
  expect(Boolean(authoredPurpose?.ar), `Missing authored AR purpose: ${article.module}/${article.slug}`)
  expect(enText.includes('## When to use this page'), `EN article is missing manual-style usage guidance: ${article.article_en}`)
  expect(enText.includes('## Typical tasks'), `EN article is missing typical tasks: ${article.article_en}`)
  expect(arText.includes('## متى تستخدم هذه الصفحة؟'), `AR article is missing manual-style usage guidance: ${article.article_ar}`)
  expect(arText.includes('## المهام الشائعة'), `AR article is missing typical tasks: ${article.article_ar}`)
  expect(!enText.includes('Use ' + path.basename(article.article_en) + ' to review and manage the records'), `EN article still contains generic purpose fallback: ${article.article_en}`)
  expect(!enText.includes('Captures the '), `EN article still contains legacy generic field prose: ${article.article_en}`)
  expect(!enText.includes('runs the named record action'), `EN article still contains legacy generic action prose: ${article.article_en}`)
  expect(!arText.includes('يسجل قيمة '), `AR article still contains legacy generic field prose: ${article.article_ar}`)
  for (const page of article.pages) {
    coveredPages.add(`${article.class}::${page}`)
  }
  for (const id of article.screenshot_ids) {
    expect(screenshotIds.has(id), `Article references missing screenshot id: ${id}`)
    expect(enText.includes(`id="${id}"`), `EN article does not render screenshot component: ${id}`)
    expect(arText.includes(`id="${id}"`), `AR article does not render screenshot component: ${id}`)

    const verified = verifiedScreenshots[id] ?? {}
    if (verified.en) {
      expect(
        enText.includes(`imageSrc="${verified.en}"`),
        `EN article does not embed verified screenshot: ${id}`,
      )
    }
    if (verified.ar) {
      expect(
        arText.includes(`imageSrc="${verified.ar}"`),
        `AR article does not embed verified screenshot: ${id}`,
      )
    }
  }
}

for (const pageKey of expectedPages) {
  expect(coveredPages.has(pageKey), `Missing registered Filament page coverage: ${pageKey}`)
}

for (const shot of screenshots.filter((item) => item.module === 'getting-started')) {
  const relative = shot.article.replace(/^\//, '')
  const enPath = path.join(root, relative + '.md')
  const arPath = path.join(root, 'ar', relative + '.md')
  expect(fs.existsSync(enPath), `Missing EN manual screenshot article: ${shot.article}`)
  expect(fs.existsSync(arPath), `Missing AR manual screenshot article: /ar/${relative}`)
  if (fs.existsSync(enPath)) {
    expect(fs.readFileSync(enPath, 'utf8').includes(`id="${shot.id}"`), `EN manual article does not render screenshot placeholder: ${shot.id}`)
  }
  if (fs.existsSync(arPath)) {
    expect(fs.readFileSync(arPath, 'utf8').includes(`id="${shot.id}"`), `AR manual article does not render screenshot placeholder: ${shot.id}`)
  }
}

for (const wf of [
  'quote-to-cash', 'customer-return-to-refund', 'procure-to-pay', 'sales-shortage-procurement',
  'warehouse-replenishment', 'inventory-transfer', 'physical-count', 'inventory-correction',
  'tracked-inventory', 'lead-to-sale', 'customer-pricing', 'accounts-receivable',
  'accounts-payable', 'period-close', 'employee-field-sales', 'employee-performance',
  'support-case', 'maintenance', 'warranty', 'field-service', 'equipment-lifecycle',
]) {
  expect(fs.existsSync(path.join(root, 'workflows', `${wf}.md`)), `Missing EN workflow: ${wf}`)
  expect(fs.existsSync(path.join(root, 'ar', 'workflows', `${wf}.md`)), `Missing AR workflow: ${wf}`)
}

if (failures.length) {
  console.error('User guide validation failed:')
  for (const failure of failures) console.error(`- ${failure}`)
  process.exit(1)
}

console.log(`User guide coverage OK: ${coverage.article_count} bilingual screen articles, ${screenshots.length} screenshot placeholders, ${coverage.workflow_count} workflows.`)
console.log(`Registered Filament pages covered: ${expectedPages.size}; dashboard classes covered: ${expectedClasses.size}.`)
