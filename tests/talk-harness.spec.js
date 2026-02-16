/**
 * Talk Test Harness — Playwright CI Wrapper
 * ==========================================
 * Loads the PHP test harness in auto-run mode and asserts all tests pass.
 *
 * Run against localhost:  npm run test:talk
 * Run against staging:    npm run test:talk-staging
 */

const { test, expect } = require('@playwright/test');

const SITE = process.env.SITE_URL || 'http://localhost';

test('Talk multi-user harness passes all scenarios', async ({ page }) => {
    test.setTimeout(120000);

    await page.goto(`${SITE}/tests/talk-harness.php?auto=1`);

    // Wait for harness to complete (auto-run adds #harness-complete on success)
    await page.waitForSelector('#harness-complete', { state: 'attached', timeout: 90000 });

    // Read summary
    const summary = await page.$eval('#harness-summary', el =>
        JSON.parse(el.dataset.results)
    );

    console.log(`Talk harness: ${summary.passed} passed, ${summary.failed} failed, ${summary.expected_fail} expected-fail (${summary.duration_ms}ms)`);

    expect(summary.failed).toBe(0);
    expect(summary.passed + summary.expected_fail).toBeGreaterThan(0);
});
