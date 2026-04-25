// tests/portal.spec.js
const { test, expect } = require('@playwright/test');

const BASE_PREVIEW = process.env.STAGING_BASE_URL;
const TEST_TOKEN   = process.env.TEST_TOKEN;

test.describe('RSU Medical Portal Staging Tests', () => {

  test.beforeAll(() => {
    if (!BASE_PREVIEW || !TEST_TOKEN) {
      throw new Error('STAGING_BASE_URL and TEST_TOKEN must be set');
    }
  });

  test('should login and display the hub page', async ({ page }) => {
    await page.goto(`${BASE_PREVIEW}user/hub.php?test_token=${TEST_TOKEN}`);

    const heading = page.locator('h1');
    await expect(heading).toContainText(/RSU Medical/);

    await page.screenshot({ path: 'test-results/hub-screenshot.png' });
  });

  test('profile page should load without 500 error', async ({ page }) => {
    await page.goto(`${BASE_PREVIEW}user/profile.php?test_token=${TEST_TOKEN}`);

    const bodyText = await page.innerText('body');
    expect(bodyText).not.toContain('Internal Server Error');
    expect(bodyText).not.toContain('500');

    const saveBtn = page.locator('button:has-text("บันทึก")');
    await expect(saveBtn).toBeVisible();

    await page.screenshot({ path: 'test-results/profile-screenshot.png' });
  });

});
