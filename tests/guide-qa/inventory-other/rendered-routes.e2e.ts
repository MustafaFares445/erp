import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { readFileSync, appendFileSync } from 'node:fs';
import { join } from 'node:path';

type Target = { id: string; capture_url: string | null; capture_ready: boolean };
if (!process.cwd().replaceAll('\\', '/').endsWith('/tmp/qa-workspaces/worker-04')) throw Error('Wrong QA worker');
const targets = JSON.parse(readFileSync(join(process.cwd(), 'tmp/qa-handoff/AGENT_2_RESOLVED_TARGETS.json'), 'utf8')) as Target[];
const ready = targets.filter(t => t.capture_ready && t.capture_url);
const batches: Target[][] = [];
for (let i = 0; i < ready.length; i += 5) batches.push(ready.slice(i, i + 5));

for (const locale of ['en', 'ar'] as const) for (const [i, batch] of batches.entries()) {
  test(`Inventory-other ${locale} rendered batch ${i + 1}`, { session: 'qa-admin', timeout: 120_000 },
    async ({ app, browser }) => {
      await browser.setViewport({ width: 1440, height: 1100 });
      await app.open('/locale/' + locale);
      for (const target of batch) {
        await app.open(target.capture_url!);
        await expect(browser.locator('h1').first()).toBeVisible({ timeout: 12_000 });
        const result = await browser.evaluate(() => ({
          url: location.pathname, heading: document.querySelector('h1')?.textContent?.trim() ?? '',
          body: document.body?.innerText ?? '', lang: document.documentElement.lang, dir: document.documentElement.dir,
        }));
        expect(result.url, target.id).toBe(target.capture_url!.split('?')[0]);
        expect(result.heading.length, target.id).toBeGreaterThan(2);
        expect(result.body.length, target.id).toBeGreaterThan(130);
        expect(result.body, target.id).not.toMatch(/Internal Server Error|Whoops|404 Not Found|403 Forbidden/i);
        expect(result.lang, target.id).toBe(locale);
        expect(result.dir, target.id).toBe(locale === 'ar' ? 'rtl' : 'ltr');
        appendFileSync(join(process.cwd(), 'tmp/qa-handoff/AGENT_2_RENDERED_OBSERVATIONS.jsonl'),
          JSON.stringify({ id: target.id, locale, url: result.url, heading: result.heading, approved: false }) + '\n');
      }
    });
}
