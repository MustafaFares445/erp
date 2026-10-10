import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { appendFileSync } from 'node:fs';
import { join } from 'node:path';

type Entry = { id: string; input: string; destination: string; text: string };
const scenarios: Entry[] = [
 { id: 'inventory-package-view', input: '/admin/packages/1', destination: '/admin/packages/1', text: 'QA Demo' },
 { id: 'inventory-package-edit', input: '/admin/packages/1/edit', destination: '/admin/packages/1/edit', text: 'QA Demo' },
 { id: 'inventory-product-variant-view', input: '/admin/product-variants/1', destination: '/admin/products/1/variants', text: 'Surgical Guide Resin' },
];
if (!process.cwd().replaceAll('\\', '/').endsWith('/tmp/qa-workspaces/worker-04')) throw Error('Wrong QA worker');
for (const locale of ['en', 'ar'] as const) {
  test(`Recovered Inventory Package and Variant redirect surfaces render in ${locale}`,
    { session: 'qa-admin', timeout: 120000 }, async ({ app, browser }) => {
      await browser.setViewport({ width: 1440, height: 1100 });
      await app.open('/locale/' + locale);
      for (const target of scenarios) {
        await app.open(target.input);
        await expect(browser).toHaveURL(new RegExp(target.destination.replaceAll('/', '\\/') + '(?:\\?|$)'));
        await expect(browser.locator('h1').first()).toBeVisible({timeout: 15000});
        const state = await browser.evaluate(() => ({
          path: location.pathname,
          heading: document.querySelector('h1')?.textContent?.trim() ?? '',
          content: document.body?.innerText ?? '',
          dir: document.documentElement.dir,
          lang: document.documentElement.lang,
          hasForm: !!document.querySelector('input[id$=".name"]'),
          packageNameValue: (document.querySelector('input[id$=".name"]') as HTMLInputElement | null)?.value ?? '',
        }));
        expect(state.path, target.id).toBe(target.destination);
        expect(state.lang, target.id).toBe(locale);
        expect(state.dir, target.id).toBe(locale === 'ar' ? 'rtl' : 'ltr');
        if (target.id === 'inventory-package-edit') {
          expect(state.packageNameValue, target.id).toContain(target.text);
        } else {
          expect(state.content, target.id).toContain(target.text);
        }
        expect(state.content.length, target.id).toBeGreaterThan(170);
        expect(state.content, target.id).not.toMatch(/Internal Server Error|404 Not Found|403 Forbidden|Whoops/i);
        if (target.id === 'inventory-package-edit') expect(state.hasForm).toBe(true);
        appendFileSync(join(process.cwd(), 'tmp/qa-handoff/AGENT_2_RENDERED_OBSERVATIONS.jsonl'),
          JSON.stringify({ id: target.id, locale, url: state.path, heading: state.heading,
            kind: target.id === 'inventory-product-variant-view' ? 'canonical_redirect_alias' : 'normal',
            approved: false }) + '\n');
      }
    });
}
