import { test, expect } from '@playwright/test';
import { login, hasCreds, assertNotPhpError } from '../helpers/auth';

/**
 * End-to-end ticket creation — the core user flow.
 *
 * WARNING: this WRITES a real ticket row and submit_query2.php sends
 * confirmation / assignment emails. It is therefore OPT-IN: it runs only when
 * BOTH credentials are set AND RUN_WRITE_TESTS=1. Leave RUN_WRITE_TESTS unset
 * for daily runs against production unless you truly want a ticket + emails
 * every day. The created ticket's title is tagged so ops can identify it.
 */
const WRITE_ENABLED = process.env.RUN_WRITE_TESTS === '1';

test.describe('Create ticket (writes data)', () => {
  test.skip(
    !hasCreds || !WRITE_ENABLED,
    'Set TEST_EMAIL, TEST_PASSWORD and RUN_WRITE_TESTS=1 to run the ticket-creation flow.',
  );

  test('submits the ticket form and reaches the success page', async ({ page }) => {
    await login(page);

    await page.goto('/pspf_crm/api/ticket/query.php');
    await expect(page.locator('#ticketForm')).toBeVisible();

    const marker = `[UI-TEST] automated smoke ${new Date().toISOString()}`;
    await page.fill('[name="queryTitle"]', marker);
    await page.selectOption('[name="queryMembertype"]', 'Active');
    await page.selectOption('[name="queryRegion"]', 'Headquarters');
    // E-mail source avoids the "phone number required" conditional on Phone.
    await page.selectOption('[name="querySource"]', 'E-mail');
    // Department options are built from the DB; pick the first real one.
    await page.selectOption('[name="queryType"]', { index: 1 });
    await page.selectOption('[name="queryPriority"]', 'Low');
    await page.fill(
      '[name="queryDescription"]',
      'Automated daily UI smoke test. Please ignore / auto-close.',
    );

    await page.click('#ticketForm button[type="submit"]');

    // Success redirects to ticket_success2.php?ticket_id=N and shows the ID.
    await page.waitForURL(/ticket_success2\.php/i);
    await expect(page).toHaveTitle(/Ticket Successfully Logged/i);
    await expect(page.locator('body')).toContainText(/TCK-\d{6}/);
    await assertNotPhpError(page);
  });
});
