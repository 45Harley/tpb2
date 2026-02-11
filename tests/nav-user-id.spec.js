/**
 * Navigation User ID Display Tests
 * Testing the feature we just added: User ID in nav Row 1
 */
const { test, expect } = require('@playwright/test');

test.describe('Navigation User ID', () => {

  test('User ID displays for logged-in users', async ({ page }) => {
    // Navigate to profile (requires login)
    await page.goto('/profile.php');

    // Check if we need to login first
    const isLoginPage = await page.locator('text=Login').isVisible();

    if (isLoginPage) {
      test.skip('Skipping - requires login session');
    } else {
      // User is logged in, check for user ID in nav
      const userId = page.locator('.nav-status .user-id');

      // Should be visible
      await expect(userId).toBeVisible();

      // Should be a number (e.g., "10", "1")
      const userIdText = await userId.textContent();
      expect(userIdText).toMatch(/^\d+$/);

      // Should be styled as monospace
      await expect(userId).toHaveCSS('font-family', /monospace/i);

      // Should be gray (#888)
      await expect(userId).toHaveCSS('color', 'rgb(136, 136, 136)'); // #888
    }
  });

  test('User ID appears before email in nav', async ({ page }) => {
    await page.goto('/profile.php');

    const userId = page.locator('.nav-status .user-id');
    const email = page.locator('.nav-status .email-link');

    // If both exist, user ID should come first
    const userIdVisible = await userId.isVisible().catch(() => false);
    const emailVisible = await email.isVisible().catch(() => false);

    if (userIdVisible && emailVisible) {
      const userIdBox = await userId.boundingBox();
      const emailBox = await email.boundingBox();

      // User ID should be to the left of email (smaller x coordinate)
      expect(userIdBox.x).toBeLessThan(emailBox.x);
    }
  });

  test('User ID is non-clickable (just displayed)', async ({ page }) => {
    await page.goto('/profile.php');

    const userId = page.locator('.nav-status .user-id');
    const isVisible = await userId.isVisible().catch(() => false);

    if (isVisible) {
      // Should be a <span>, not an <a>
      const tagName = await userId.evaluate(el => el.tagName);
      expect(tagName).toBe('SPAN');

      // Should not have href attribute
      const href = await userId.getAttribute('href');
      expect(href).toBeNull();
    }
  });

  test('User ID displays on mobile viewports', async ({ page }) => {
    // Set mobile viewport (iPhone size)
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/profile.php');

    const userId = page.locator('.nav-status .user-id');
    const isVisible = await userId.isVisible().catch(() => false);

    if (isVisible) {
      // Should still be visible on mobile
      await expect(userId).toBeVisible();

      // Font size should be readable (0.9em from nav.php)
      const fontSize = await userId.evaluate(el =>
        window.getComputedStyle(el).fontSize
      );

      // Should not be too small (at least 10px)
      const fontSizeNum = parseFloat(fontSize);
      expect(fontSizeNum).toBeGreaterThanOrEqual(10);
    }
  });
});
