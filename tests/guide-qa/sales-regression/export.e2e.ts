import { test } from '@e2e-dev/web';
import { expect } from 'e2e';

test('authorized quotation CSV export produces an actual browser download', { session: 'qa-admin', timeout: 90000 }, async ({ app, screen, browser }) => {
  await app.open('/locale/en');
  await app.open('/admin/quotations');
  const exportButton = screen.getByRole('button', 'Export CSV');
  await expect(exportButton).toBeVisible();
  const file = await browser.waitForDownload(() => exportButton.click(), { timeout: 45000 });
  expect(file.suggestedFilename.endsWith('.csv')).toBe(true);
  // The E2E API returns an artifact-relative download path, not a process-CWD path.
  expect(file.path.includes('downloads/')).toBe(true);
});
