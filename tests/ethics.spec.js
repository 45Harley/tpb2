/**
 * Ethics Compliance Tests - Golden Rule Foundation
 * ==================================================
 * Testing TPB's ethical standards across all pages
 *
 * Standards:
 * 1. Accuracy over speed
 * 2. Official sources (.gov)
 * 3. Plain language (no jargon)
 * 4. Non-partisan (serve ALL citizens)
 * 5. Cite sources
 */
const { test, expect } = require('@playwright/test');

test.describe('Golden Rule Ethics Compliance', () => {

  test('Home page is non-partisan', async ({ page }) => {
    await page.goto('/');

    // Get all text content
    const bodyText = await page.locator('body').textContent();

    // Flag partisan words that editorialize
    const partisanWords = /\b(waste|wasteful|bloated|radical|extreme|dangerous|disaster|corrupt|rigged)\b/gi;
    const matches = bodyText.match(partisanWords);

    // Should not contain partisan language
    expect(matches).toBeNull();
  });

  test('State pages use official .gov sources', async ({ page }) => {
    // Test Connecticut state page (when it exists)
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      // Get all benefit/program links
      const benefitLinks = await page.locator('#benefits a, [data-section="benefits"] a').all();

      for (const link of benefitLinks) {
        const href = await link.getAttribute('href');

        if (href && !href.startsWith('#')) {
          // External links should be official sources
          expect(href).toMatch(/\.(gov|edu|us)($|\/)/);

          // Should NOT be Wikipedia (convenience over trust)
          expect(href).not.toContain('wikipedia.org');
        }
      }
    } else {
      test.skip('CT state page not yet built');
    }
  });

  test('Town page (Putnam) uses plain language', async ({ page }) => {
    await page.goto('/z-states/ct/putnam/');

    // Check for unexplained acronyms/jargon
    const bodyText = await page.locator('body').textContent();

    // Common jargon that should be translated for Tom (67, not tech-savvy)
    const jargonPatterns = [
      /\bCHFA\b/, // Should be "Connecticut Housing Finance Authority (CHFA)"
      /\bDECD\b/, // Should be "Dept of Economic & Community Development"
      /\bQCD\b/,  // Qualified Charitable Distribution (unexplained)
    ];

    // If jargon appears, it should be explained first
    for (const pattern of jargonPatterns) {
      if (pattern.test(bodyText)) {
        // Check if it's explained (appears after full name)
        const fullNamePattern = new RegExp(`\\(${pattern.source}\\)`);
        if (!fullNamePattern.test(bodyText)) {
          console.warn(`Found unexplained jargon: ${pattern.source}`);
        }
      }
    }
  });

  test('Benefits pages have source citations', async ({ page }) => {
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      const hasBenefits = await page.locator('#benefits').isVisible().catch(() => false);

      if (hasBenefits) {
        // Should have a sources section
        const hasSources = await page.locator('text=/sources/i, text=/references/i').isVisible();

        expect(hasSources).toBeTruthy();
      }
    }
  });

  test('No "click here" links (accessibility)', async ({ page }) => {
    await page.goto('/');

    // "Click here" is not descriptive for screen readers (Tom might use one)
    const clickHereLinks = await page.locator('a:has-text("click here"), a:has-text("here")').all();

    expect(clickHereLinks.length).toBe(0);
  });

  test('Forms have clear error messages', async ({ page }) => {
    await page.goto('/join.php');

    // Try submitting empty form
    const submitButton = page.locator('button[type="submit"], input[type="submit"]').first();
    await submitButton.click();

    // Should show helpful error, not technical jargon
    const errorText = await page.locator('.error, [role="alert"]').textContent().catch(() => '');

    // Should NOT have technical errors
    expect(errorText).not.toMatch(/null|undefined|500|exception/i);

    // Should have helpful guidance (if error exists)
    if (errorText) {
      expect(errorText.length).toBeGreaterThan(10); // Not just "Error"
    }
  });

  test('Benefit amounts are specific (accuracy for Maria)', async ({ page }) => {
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      const benefitsText = await page.locator('#benefits').textContent().catch(() => '');

      // If mentioning money, should be specific amounts
      if (benefitsText.includes('$') || benefitsText.match(/\d+,\d+/)) {
        // Good: "$9,600/year", "Up to $15,000"
        // Bad: "financial assistance available"

        // Check that dollar amounts have context
        const moneyMentions = benefitsText.match(/\$[\d,]+/g);
        if (moneyMentions) {
          // Should have units (per year, per month, total, etc.)
          expect(benefitsText).toMatch(/year|month|total|maximum|up to/i);
        }
      }
    }
  });

  test('Page loads quickly (Maria on slow connection)', async ({ page }) => {
    const start = Date.now();
    await page.goto('/');
    const loadTime = Date.now() - start;

    // Should load in under 3 seconds (Maria might be on mobile data)
    expect(loadTime).toBeLessThan(3000);
  });
});

test.describe('Golden Rule Personas - User Stories', () => {

  test('Maria (34, single mom) can find childcare help', async ({ page }) => {
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      // Should have a Benefits section
      await page.click('text=Benefits');

      // Should mention childcare programs
      const benefitsText = await page.locator('#benefits').textContent();
      const hasChildcare = /childcare|child care|Care 4 Kids/i.test(benefitsText);

      expect(hasChildcare).toBeTruthy();
    } else {
      test.skip('CT state page not yet built');
    }
  });

  test('Tom (67, retired) can understand senior programs clearly', async ({ page }) => {
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      await page.click('text=Benefits');

      // Should have senior-specific section
      const seniorsSection = page.locator('#seniors, [data-section="seniors"]');
      const hasSeniors = await seniorsSection.isVisible().catch(() => false);

      if (hasSeniors) {
        const text = await seniorsSection.textContent();

        // Should use clear language for Tom
        expect(text).toMatch(/property tax|energy|heating|cooling/i);

        // Should NOT have unexplained jargon
        expect(text).not.toMatch(/\b[A-Z]{3,}\b(?!.*\()/); // Acronyms without parentheses
      }
    }
  });

  test('Jamal (22, first-time buyer) can find homeownership help', async ({ page }) => {
    const response = await page.goto('/z-states/ct/');

    if (response.status() === 200) {
      await page.click('text=Benefits');

      const benefitsText = await page.locator('#benefits').textContent();

      // Should mention first-time homebuyer programs
      const hasHomebuyer = /first.time|homebuyer|down payment|CHFA|housing/i.test(benefitsText);

      expect(hasHomebuyer).toBeTruthy();
    }
  });
});
