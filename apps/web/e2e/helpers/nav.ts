import { Page } from '@playwright/test';

/** Go to login page (relative to baseURL). */
export async function gotoLogin(page: Page): Promise<void> {
  await page.goto('/login');
}

/** Go to app dashboard (requires auth context set). */
export async function gotoDashboard(page: Page): Promise<void> {
  await page.goto('/app/dashboard');
}

/** Go to platform tenants (platform admin only). */
export async function gotoPlatformTenants(page: Page): Promise<void> {
  await page.goto('/app/platform/tenants');
}

/** Go to tenant admin module management. */
export async function gotoModuleManagement(page: Page): Promise<void> {
  await page.goto('/app/admin/modules');
}

/** Go to transactions list. */
export async function gotoTransactions(page: Page): Promise<void> {
  await page.goto('/app/transactions');
}

/** Go to a transaction detail by id. Uses the real app route: /app/transactions/:id */
export async function gotoTransactionDetail(page: Page, id: string): Promise<void> {
  const apiErrors: { url: string; status: number }[] = [];
  const requestFailures: { url: string; errorText: string }[] = [];
  const apiRequestStatuses: { url: string; status: number }[] = [];

  const getStatus = (res: { status?: (() => number) | number }): number =>
    typeof res.status === 'function' ? res.status() : (res.status ?? 0);

  const onResponse = (res: { url: () => string; status?: (() => number) | number }) => {
    try {
      const u = res.url();
      const status = getStatus(res);
      if (u.includes('/api/') && status >= 400) apiErrors.push({ url: u, status });
    } catch {
      // Diagnostics must not crash the test
    }
  };
  const onRequestFailed = (req: { url: () => string; failure: () => { errorText?: string } | null }) => {
    try {
      const u = req.url();
      const failure = req.failure();
      requestFailures.push({ url: u, errorText: failure?.errorText ?? 'unknown' });
    } catch {
      // Diagnostics must not crash the test
    }
  };
  const onRequestFinished = (req: { url: () => string; response: () => { status?: (() => number) | number } | null }) => {
    try {
      const u = req.url();
      if (!u.includes('/api/')) return;
      const res = req.response();
      if (res) {
        const status = getStatus(res);
        apiRequestStatuses.push({ url: u, status });
      }
    } catch {
      // Diagnostics must not crash the test
    }
  };

  page.on('response', onResponse);
  page.on('requestfailed', onRequestFailed);
  page.on('requestfinished', onRequestFinished);

  await page.goto(`/app/transactions/${id}`);
  await page.waitForLoadState('domcontentloaded');

  const detailCount = await page.getByTestId('transaction-detail').count();
  const loginCount = await page.getByTestId('login-submit').count();

  page.off('response', onResponse);
  page.off('requestfailed', onRequestFailed);
  page.off('requestfinished', onRequestFinished);

  if (detailCount === 0 && loginCount > 0) {
    throw new Error('Redirected to login; auth cookie not applied');
  }
  if (detailCount === 0) {
    const currentUrl = page.url();
    const parts: string[] = [`url=${currentUrl}`];

    if (requestFailures.length > 0) {
      const apiFailures = requestFailures.filter((f) => f.url.includes('/api/'));
      parts.push(`requestFailed(${requestFailures.length})=${requestFailures.map((f) => `${f.errorText} ${f.url}`).join('; ')}`);
      if (apiFailures.length > 0) {
        parts.push(`apiFailures=${apiFailures.map((f) => `${f.errorText} ${f.url}`).join('; ')}`);
      }
    }
    if (apiRequestStatuses.length > 0) {
      parts.push(`apiStatuses=${apiRequestStatuses.map((s) => `${s.status} ${s.url}`).join('; ')}`);
    }
    if (apiErrors.length > 0) {
      parts.push(`api4xx/5xx=${apiErrors.map((e) => `${e.status} ${e.url}`).join('; ')}`);
    }

    let notFoundHint = '';
    const hasNotFoundText =
      (await page.getByText('Not Found').count()) > 0 || (await page.getByText('404').count()) > 0;
    const hasNotFoundTestId = (await page.getByTestId('not-found').count()) > 0;
    if (hasNotFoundText || hasNotFoundTestId) {
      notFoundHint = ' Rendered NotFound UI.';
    }

    throw new Error(`Transaction detail route mismatch or NotFound. ${parts.join(' ')}${notFoundHint}`);
  }
}

/** Go to machinery services list. */
export async function gotoMachineryServices(page: Page): Promise<void> {
  await page.goto('/app/machinery/services');
}

/** Go to a machinery service detail by id. */
export async function gotoMachineryServiceDetail(page: Page, id: string): Promise<void> {
  await page.goto(`/app/machinery/services/${id}`);
}

/** Go to posting group detail by id. */
export async function gotoPostingGroupDetail(page: Page, id: string): Promise<void> {
  await page.goto(`/app/posting-groups/${id}`);
}
