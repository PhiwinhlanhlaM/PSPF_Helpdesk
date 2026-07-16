import { test, expect, Page } from '@playwright/test';

/**
 * Daily UI smoke tests for the PSPF Helpdesk app.
 *
 * These verify the things that break most often and matter most every morning:
 *   1. The sign-in page loads and renders its form (app is up, PHP not fatal-erroring).
 *   2. A wrong password is rejected with the expected error (auth path is alive).
 *   3. A valid account logs in and lands on the dashboard / role picker.
 *
 * Tests 1 and 2 need no secrets and always run. Test 3 runs only when a test
 * account is provided via the TEST_EMAIL / TEST_PASSWORD env vars (see .env.example).
 */

const SIGNIN_PATH = '/pspf_crm/api/signin/index.php';

const TEST_EMAIL = process.env.TEST_EMAIL;
const TEST_PASSWORD = process.env.TEST_PASSWORD;

async function fillLogin(page: Page, email: string, password: string) {
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button.login-btn');
}

test.describe('Sign-in page', () => {
  test('loads and renders the login form', async ({ page }) => {
    const response = await page.goto(SIGNIN_PATH);

    // Server responded OK (not 500 / 404).
    expect(response, 'no HTTP response from signin page').not.toBeNull();
    expect(response!.status(), 'signin page returned a non-2xx status').toBeLessThan(400);

    await expect(page).toHaveTitle(/Sign in CRM/i);

    // A raw PHP fatal would render "Fatal error" / "Warning" text instead of the app.
    await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error|Uncaught/i);

    // Core form controls are present.
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button.login-btn')).toBeVisible();
  });

  test('rejects an invalid login with an error message', async ({ page }) => {
    await page.goto(SIGNIN_PATH);
    await fillLogin(page, 'not-a-real-user@example.com', 'definitely-wrong-password');

    // The app re-renders the signin page with a .error box on bad credentials.
    await expect(page.locator('.error')).toBeVisible();
    await expect(page.locator('.error')).toContainText(/Invalid email or password/i);

    // We must NOT have been redirected into the app.
    await expect(page).toHaveURL(/signin\/index\.php/i);
  });
});

test.describe('Authenticated flow', () => {
  test.skip(
    !TEST_EMAIL || !TEST_PASSWORD,
    'Set TEST_EMAIL and TEST_PASSWORD in ui-tests/.env to run the real login check.',
  );

  test('a valid account logs in and reaches the dashboard', async ({ page }) => {
    await page.goto(SIGNIN_PATH);
    await fillLogin(page, TEST_EMAIL!, TEST_PASSWORD!);

    // On success the app redirects to dashboard.php, or to select_role.php when
    // the account holds more than one selectable role. Either proves login worked.
    await page.waitForURL(/dashboard\.php|select_role\.php/i);

    const url = page.url();
    if (/select_role\.php/i.test(url)) {
      await expect(page).toHaveTitle(/Select Role/i);
      await expect(page.locator('#roleForm')).toBeVisible();
    } else {
      await expect(page).toHaveTitle(/PSPF Helpdesk Dashboard/i);
    }

    // Whichever landing page, it should not be a PHP error page.
    await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error|Uncaught/i);
  });
});
