import { test, expect } from '@playwright/test';

async function assertNoHorizontalPageOverflow(page: import('@playwright/test').Page) {
  // Allow a tiny tolerance for subpixel rounding.
  const ok = await page.evaluate(() => {
    const tol = 2;
    const w = window.innerWidth;
    const doc = document.documentElement;
    const body = document.body;
    const docOk = doc.scrollWidth <= w + tol;
    const bodyOk = body.scrollWidth <= w + tol;
    return { docOk, bodyOk, w, docScrollWidth: doc.scrollWidth, bodyScrollWidth: body.scrollWidth };
  });
  expect(ok.docOk, `documentElement scrollWidth=${ok.docScrollWidth} > innerWidth=${ok.w}`).toBeTruthy();
  expect(ok.bodyOk, `body scrollWidth=${ok.bodyScrollWidth} > innerWidth=${ok.w}`).toBeTruthy();
}

const MOBILE = { width: 375, height: 812 };
const TABLET = { width: 820, height: 1180 };

test.describe('@responsive layout smoke', () => {
  test.describe.configure({ timeout: 120_000 });

  test.afterEach(async ({ context }) => {
    // Be explicit: long multi-route smoke tests can occasionally leave the browser in a bad state
    // for the next test in the same worker on Windows CI/dev machines.
    await context.close().catch(() => undefined);
  });

  test.describe('mobile', () => {
    test.use({ viewport: MOBILE });

    test('dashboard, list, form, report: no horizontal overflow', async ({ page }) => {
      await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Settings/admin representative page
      await page.goto('/app/admin/modules', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Inventory/ops representative page
      await page.goto('/app/inventory', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Machinery/ops representative page (filters + table)
      await page.goto('/app/machinery/services', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Representative list page (filters + table)
      await page.goto('/app/accounting/journals', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Representative form page (multi-column collapses + table container)
      await page.goto('/app/accounting/journals/new', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Detail page (best-effort): open first sale if present
      await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      const firstRow = page.locator('tbody tr').first();
      if (await firstRow.count()) {
        await firstRow.click();
        await page.getByTestId('app-shell').waitFor();
        await assertNoHorizontalPageOverflow(page);
      }

      // Modal/footer actions (best-effort): open worker create modal if present
      await page.goto('/app/labour/workers', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      const newWorkerBtn = page.getByRole('button', { name: /New Worker/i });
      if (await newWorkerBtn.count()) {
        await newWorkerBtn.click();
        await assertNoHorizontalPageOverflow(page);
      }

      // Representative report page (dense accounting table)
      await page.goto('/app/reports/profit-loss', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await expect(page.locator('body')).toContainText(/Profit|Loss|P&L|Income/i);
      await assertNoHorizontalPageOverflow(page);

      // Additional accounting/report page
      await page.goto('/app/reports/general-ledger', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);
    });
  });

  test.describe('tablet', () => {
    test.use({ viewport: TABLET });

    test('dashboard, list, form, report: no horizontal overflow (tablet)', async ({ page }) => {
      await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      await page.goto('/app/admin/modules', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      await page.goto('/app/inventory/items', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      await page.goto('/app/accounting/journals/new', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      await page.goto('/app/machinery/charges', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      await page.goto('/app/accounting/journals', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);

      // Detail page (best-effort): open first journal if present
      const journalRow = page.locator('tbody tr').first();
      if (await journalRow.count()) {
        await journalRow.click();
        await page.getByTestId('app-shell').waitFor();
        await assertNoHorizontalPageOverflow(page);
      }

      await page.goto('/app/reports/balance-sheet', { waitUntil: 'domcontentloaded' });
      await page.getByTestId('app-shell').waitFor();
      await assertNoHorizontalPageOverflow(page);
    });
  });
});

