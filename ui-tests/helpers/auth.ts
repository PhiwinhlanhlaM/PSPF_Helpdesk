import { Page, expect } from '@playwright/test';

export const SIGNIN_PATH = '/pspf_crm/api/signin/index.php';

/**
 * The role dashboards a successful login can land on. dashboard.php (the
 * redirector) bounces to one of these based on the account's active role.
 */
export const ROLE_DASHBOARDS = [
  { role: 'user', urlRe: /user_dashboard\.php/i, titleRe: /Help Center/i },
  { role: 'agent', urlRe: /agent\/agent_dashboard\.php/i, titleRe: /Agent Dashboard/i },
  { role: 'admin', urlRe: /admin\/admin_dashboard\.php/i, titleRe: /PSPF Helpdesk/i },
] as const;

export const TEST_EMAIL = process.env.TEST_EMAIL;
export const TEST_PASSWORD = process.env.TEST_PASSWORD;
// Optional: for multi-role accounts, which role to pick on the chooser.
export const TEST_ROLE = process.env.TEST_ROLE;

export const hasCreds = !!TEST_EMAIL && !!TEST_PASSWORD;

/**
 * Signs in and returns the final landing URL. Handles the role picker that
 * appears when an account holds more than one selectable role.
 */
export async function login(
  page: Page,
  email = TEST_EMAIL!,
  password = TEST_PASSWORD!,
  preferredRole = TEST_ROLE,
): Promise<string> {
  await page.goto(SIGNIN_PATH);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button.login-btn');

  // Success goes to dashboard.php (which redirects on to a role dashboard),
  // or to select_role.php when multiple roles are available.
  await page.waitForURL(/dashboard\.php|select_role\.php/i);

  if (/select_role\.php/i.test(page.url())) {
    const card = preferredRole
      ? page.locator(`label.role-card[for="role_${preferredRole}"]`)
      : page.locator('label.role-card').first();
    await card.click(); // checks the hidden radio and enables Continue
    await page.click('#continueBtn');
    await page.waitForURL(/dashboard\.php/i);
  }

  return page.url();
}

/** Fails the test if the current page is a raw PHP error rather than the app. */
export async function assertNotPhpError(page: Page) {
  await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error|Uncaught/i);
}
