import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(import.meta.dirname, '..')
const builtRoot = path.resolve(root, '..', 'public', 'guide')

if (!fs.existsSync(builtRoot)) {
  console.error('Built guide directory is missing: public/guide')
  process.exit(1)
}

const failures = []
let htmlCount = 0
let internalLinkCount = 0
let imageCount = 0

const visit = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      visit(absolute)
      continue
    }
    if (!entry.isFile() || !entry.name.endsWith('.html')) continue

    htmlCount++
    if (path.relative(builtRoot, absolute).replaceAll('\\', '/').startsWith('content/overrides/')) {
      failures.push(`Authored override source was published as a duplicate page: ${absolute}`)
    }
    const html = fs.readFileSync(absolute, 'utf8')
    const hrefs = [...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1])
    const images = [...html.matchAll(/<img\b[^>]*\bsrc="([^"]+)"/g)].map((match) => match[1])

    for (const image of images) {
      if (!image.startsWith('/')) continue
      imageCount++
      const relative = image.replace(/^\/guide\//, '')
      if (!image.startsWith('/guide/') || !fs.existsSync(path.join(builtRoot, relative))) {
        failures.push(`${path.relative(builtRoot, absolute)}: missing or invalid image ${image}`)
      }
    }

    for (const href of hrefs) {
      if (!href.startsWith('/')) continue
      if (href.startsWith('/guide/') || href === '/guide') {
        internalLinkCount++
        continue
      }
      failures.push(`${path.relative(builtRoot, absolute).replaceAll('\\', '/')}: invalid root link ${href}`)
    }
  }
}

visit(builtRoot)

if (failures.length) {
  console.error(`Built guide link validation failed with ${failures.length} invalid root-absolute link(s):`)
  for (const failure of failures.slice(0, 100)) console.error(`- ${failure}`)
  if (failures.length > 100) console.error(`- ... and ${failures.length - 100} more`)
  process.exit(1)
}

console.log(`Built guide links OK: ${htmlCount} HTML pages checked; ${internalLinkCount} /guide/ links and ${imageCount} images validated.`)
