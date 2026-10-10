import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { execFileSync } from 'node:child_process';

const gateway = execFileSync('ip', ['-4', 'route', 'show', 'default'], { encoding: 'utf8' })
  .match(/default via (\d+\.\d+\.\d+\.\d+)/)?.[1];
if (!gateway) throw new Error('Independent guide preview gateway missing');
const guide = process.env.IERP_GUIDE_PREVIEW_URL ?? 'http://' + gateway + ':8218/guide/';

test('standalone guide loads English and Arabic on an independent port', async ({ app, browser }) => {
  await app.open(guide);
  await expect(browser).toHaveURL(/\/guide\/$/);
  const english = await browser.evaluate(() => ({
    lang: document.documentElement.lang, links: document.querySelectorAll('a[href*="/guide/"]').length,
  }));
  expect(english.lang).toBe('en');
  expect(english.links > 0).toBe(true);
  await app.open(guide + 'ar/');
  const arabic = await browser.evaluate(() => ({
    lang: document.documentElement.lang, dir: document.documentElement.dir,
    links: document.querySelectorAll('a[href*="/guide/ar/"]').length,
  }));
  expect(arabic.lang).toBe('ar');
  expect(arabic.dir).toBe('rtl');
  expect(arabic.links > 0).toBe(true);
});

test('bilingual onboarding and Reports/System pages load without broken visible images', async ({ app, browser }) => {
  for (const route of [
    'getting-started/navigation', 'screens/reports/audit-log', 'screens/system/dashboard-user',
    'ar/getting-started/navigation', 'ar/screens/reports/audit-log', 'ar/screens/system/dashboard-user',
  ]) {
    await app.open(guide + route);
    await expect(browser.locator('html')).toHaveAttribute('lang', route.startsWith('ar/') ? 'ar' : 'en');
    const evidence = await browser.evaluate(() => ({
      title: document.querySelector('h1')?.textContent?.trim() ?? '',
      missing: Array.from(document.images).filter(img => img.complete && img.naturalWidth === 0).map(img => img.src),
      wrongBase: Array.from(document.querySelectorAll('a[href^="/"]')).some(a => !a.getAttribute('href')?.startsWith('/guide/')),
    }));
    expect(evidence.title.length > 0).toBe(true);
    expect(evidence.missing.length).toBe(0);
    expect(evidence.wrongBase).toBe(false);
  }
});

test('mobile Arabic guide renders without page-wide horizontal overflow', async ({ app, browser }) => {
  await browser.setViewport({ width: 390, height: 844 });
  await app.open(guide + 'ar/getting-started/navigation');
  await expect(browser.locator('html')).toHaveAttribute('dir', 'rtl');
  const mobile = await browser.evaluate(() => ({
    dir: document.documentElement.dir,
    viewport: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    sidebarButtons: document.querySelectorAll('button').length,
  }));
  expect(mobile.dir).toBe('rtl');
  expect(mobile.documentWidth <= mobile.viewport + 8).toBe(true);
  expect(mobile.sidebarButtons > 0).toBe(true);
});

test('local guide search opens and accepts an English query', async ({ app, browser }) => {
  await app.open(guide);
  const trigger = browser.locator('.DocSearch-Button').first();
  await expect(trigger).toBeVisible();
  await trigger.click();
  await expect(browser.locator('input[placeholder="Search"]').first()).toBeVisible();
  const search = browser.locator('input[placeholder="Search"]').first();
  await search.fill('quotation');
  await expect(search).toBeVisible();
  await expect(browser.locator('.VPLocalSearchBox a.result[href*="/screens/sales/quotation"]').first()).toBeVisible();
});

test('Arabic local guide search returns a real workflow article', async ({ app, browser }) => {
  await app.open(guide + 'ar/');
  await browser.locator('.DocSearch-Button').first().click();
  const search = browser.locator('#localsearch-input');
  await expect(search).toBeVisible();
  await search.fill('المحاسبة');
  await expect(browser.locator('.VPLocalSearchBox a.result[href*="/guide/ar/"]').first()).toBeVisible();
});
