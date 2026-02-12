/**
 * Form helpers compatible with FormField (label + container + input/select).
 * Prefer data-testid when present; otherwise use label-based targeting.
 */

import type { Page, Locator } from '@playwright/test';

/**
 * Find the form field container by label text (FormField wraps label + div).
 * Then fill the input/select inside it.
 */
export async function fillInputByLabel(
  page: Page,
  label: string | RegExp,
  value: string
): Promise<void> {
  const field = page.getByRole('textbox', { name: label }).or(
    page.locator(`label:has-text("${typeof label === 'string' ? label.replace(/"/g, '\\"') : label}")`).locator('..').locator('input')
  );
  await field.fill(value);
}

/**
 * More robust: target only real <label> elements, then find input/textarea in container.
 * Avoids matching table headers or other non-form text.
 */
export async function fillInputByLabelRobust(
  page: Page,
  labelText: string,
  value: string
): Promise<void> {
  const label = page.locator('label').filter({ hasText: labelText }).first();
  await label.waitFor({ state: 'visible' });
  const parent = label.locator('xpath=..');
  let input = parent.locator('input, textarea').first();
  if ((await input.count()) === 0) {
    const grandparent = label.locator('xpath=../..');
    input = grandparent.locator('input, textarea').first();
  }
  if ((await input.count()) === 0) {
    throw new Error(`fillInputByLabelRobust: no input or textarea found for label "${labelText}"`);
  }
  await input.fill(value);
}

/**
 * Select an option by visible label (select element found by associated label).
 * optionValueOrText: string (value or label) or RegExp to match option label.
 */
export async function selectByLabel(
  page: Page,
  labelText: string,
  optionValueOrText: string | RegExp
): Promise<void> {
  const label = page.getByText(labelText, { exact: false }).first();
  await label.waitFor({ state: 'visible' });
  const container = label.locator('..');
  const select = container.locator('select').first();
  if (optionValueOrText instanceof RegExp) {
    await select.selectOption({ label: optionValueOrText });
  } else {
    await select.selectOption({ value: optionValueOrText }).catch(() =>
      select.selectOption({ label: optionValueOrText })
    );
  }
}

/**
 * Click a button by role and optional name.
 */
export async function clickByRole(
  page: Page,
  role: 'button' | 'link',
  name?: string | RegExp
): Promise<void> {
  if (name !== undefined) {
    await page.getByRole(role, { name }).click();
  } else {
    await page.getByRole(role).first().click();
  }
}

/**
 * Fill input by label. Uses FormField structure (label text + container input) only,
 * to avoid matching table headers or other elements with aria-label.
 */
export async function fillByLabel(page: Page, label: string, value: string): Promise<void> {
  await fillInputByLabelRobust(page, label, value);
}

/**
 * Select by label (tries getByLabel first, then label text + container select).
 * valueOrLabel can be string or RegExp for option label match.
 */
export async function selectByLabelOption(
  page: Page,
  label: string,
  valueOrLabel: string | RegExp
): Promise<void> {
  const byLabel = page.getByLabel(label, { exact: false });
  if (await byLabel.count() > 0) {
    if (valueOrLabel instanceof RegExp) {
      await byLabel.first().selectOption({ label: valueOrLabel });
    } else {
      await byLabel.first().selectOption({ value: valueOrLabel }).catch(() =>
        byLabel.first().selectOption({ label: valueOrLabel })
      );
    }
    return;
  }
  await selectByLabel(page, label, valueOrLabel);
}
