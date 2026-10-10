import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('quotation PDF preview and download endpoints return actual PDF bytes', { session: 'qa-admin', timeout: 120000 }, async ({ app, browser, screen }) => {
  // Sent, unexpired quotation from the isolated deterministic worker demo.
  await app.open('/locale/en');
  await app.open('/admin/quotations/12');
  await expect(browser).toHaveURL(/\/admin\/quotations\/12/);
  const generate = screen.getByRole('button', 'Generate PDF');
  await expect(generate).toBeVisible();
  await generate.click();
  await browser.reload();

  const links = await browser.evaluate(() => Array.from(
    document.querySelectorAll<HTMLAnchorElement>('a[href*="/admin/quotations/12/media/"]'),
  ).map(a => a.href));
  const preview = links.find(url => url.endsWith('/preview'));
  const download = links.find(url => url.endsWith('/download'));
  expect(Boolean(preview)).toBe(true);
  expect(Boolean(download)).toBe(true);
  const responses = await browser.evaluate(async (urls) => {
    const data = [];
    for (const url of urls) {
      const response = await fetch(url, { credentials: 'include' });
      const bytes = new Uint8Array(await response.arrayBuffer());
      data.push({
        status: response.status,
        type: response.headers.get('content-type') ?? '',
        disposition: response.headers.get('content-disposition') ?? '',
        signature: String.fromCharCode(...bytes.slice(0, 4)),
        size: bytes.byteLength,
      });
    }
    return data;
  }, [preview, download]);
  expect(responses.length).toBe(2);
  for (const response of responses) {
    expect(response.status).toBe(200);
    expect(response.type.includes('application/pdf')).toBe(true);
    expect(response.signature).toBe('%PDF');
    expect(response.size > 1000).toBe(true);
  }
  expect(responses[0].disposition.includes('inline')).toBe(true);
  expect(responses[1].disposition.includes('attachment')).toBe(true);
  // This validates binary PDF responses, not visual rendering inside a browser PDF viewer.
});
