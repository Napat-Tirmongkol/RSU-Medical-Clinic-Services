const { test, expect } = require('@playwright/test');

test('ทดสอบระบบ Login แอดมิน RSU Healthcare', async ({ page }) => {
  const username = process.env.TEST_ADMIN_USERNAME;
  const password = process.env.TEST_ADMIN_PASSWORD;

  if (!username || !password) {
    throw new Error('TEST_ADMIN_USERNAME and TEST_ADMIN_PASSWORD must be set');
  }

  await page.goto('/admin/login.php');

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  await page.locator('button[type="submit"]').click();

  await expect(page.locator('text=System Governance')).toBeVisible();

  await page.screenshot({ path: 'login-success.png' });
});
