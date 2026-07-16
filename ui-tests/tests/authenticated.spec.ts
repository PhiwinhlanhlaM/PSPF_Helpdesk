import { test, expect } from '@playwright/test';
import { login, hasCreds, ROLE_DASHBOARDS, assertNotPhpError } from '../helpers/auth';

/**
 * Read-only authenticated checks. These log in with a real account but do NOT
 * write any data, so they are safe to run daily against production.
 * They run only when TEST_EMAIL / TEST_PASSWORD are set.
 */
test.describe('Authenticated (read-only)', () => {
  test.skip(!hasCreds, 'Set TEST_EMAIL and TEST_PASSWORD in ui-tests/.env to run these.');

  test('logs in and lands on a role dashboard', async ({ page }) => {
    const url = await login(page);

    const landed = ROLE_DASHBOARDS.find((d) => d.urlRe.test(url));
    expect(landed, `login landed on an unexpected page: ${url}`).toBeTruthy();
    await expect(page).toHaveTitle(landed!.titleRe);
    await assertNotPhpError(page);
  });

  test('the ticket-logging form loads with its required fields', async ({ page }) => {
    await login(page);

    // query.php is the "Log Ticket" form; loading it (GET) writes nothing.
    const response = await page.goto('/pspf_crm/api/ticket/query.php');
    expect(response!.status(), 'ticket form returned a non-2xx status').toBeLessThan(400);

    await expect(page).toHaveTitle(/Helpdesk Query/i);
    await assertNotPhpError(page);

    // The fields submit_query2.php requires must all be present.
    await expect(page.locator('#ticketForm')).toBeVisible();
    for (const name of [
      'queryTitle',
      'queryMembertype',
      'queryRegion',
      'querySource',
      'queryType',
      'queryPriority',
      'queryDescription',
    ]) {
      await expect(page.locator(`[name="${name}"]`), `missing field: ${name}`).toBeVisible();
    }
    await expect(page.locator('#ticketForm button[type="submit"]')).toBeVisible();
  });
});
