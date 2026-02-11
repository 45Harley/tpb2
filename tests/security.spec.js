/**
 * Site Security Tests — External Access Verification
 * ====================================================
 * Tests .htaccess rules from OUTSIDE the site.
 * Based on the "who needs what" map.
 *
 * THREE checks:
 *   1. Public files EXIST (not 404)
 *   2. Public files are ACCESSIBLE (200, not 403)
 *   3. Blocked files are DENIED (403, not 200)
 *
 * Run against localhost:  npm run test:security
 * Run against staging:    npm run test:staging
 * Run against production: SITE_URL=https://4tpb.org npx playwright test tests/security.spec.js
 */
const { test, expect } = require('@playwright/test');

const SITE = process.env.SITE_URL || 'http://localhost';

// ============================================
// THE MAP: who needs what
// ============================================

// BLOCKED — dev-only files, should return 403
const BLOCKED = [
  // Dev config
  { path: '/CLAUDE.md',                    reason: 'Project instructions — dev only' },
  { path: '/package.json',                 reason: 'Node dependencies — dev only' },
  { path: '/package-lock.json',            reason: 'Dependency lock — dev only' },
  { path: '/playwright.config.js',         reason: 'Test config — dev only' },
  // Dev directories
  { path: '/tests/ethics.spec.js',         reason: 'Test code — dev only' },
  { path: '/tests/nav-user-id.spec.js',    reason: 'Test code — dev only' },
  { path: '/tests/README.md',              reason: 'Test docs — dev only' },
  { path: '/scripts/db/create-state-build-tasks.sql', reason: 'SQL scripts — dev/DBA only' },
  { path: '/scripts/deploy/state-page.sh', reason: 'Deploy scripts — dev only' },
  // Internal docs (NOT the volunteer build kit)
  { path: '/docs/media-management.md',     reason: 'Internal docs — dev only' },
  { path: '/docs/TPB-PHS-Partnership-Proposal.docx', reason: 'Internal docs — private' },
  { path: '/docs/tpb_refactoring_summary.html', reason: 'Internal docs — dev only' },
  // Secrets
  { path: '/config.php',                   reason: 'DB credentials — never public' },
  { path: '/config-claude.php',            reason: 'API keys — never public' },
  { path: '/.env',                         reason: 'Environment vars — never public' },
  { path: '/.git/config',                  reason: 'Git internals — never public' },
];

// PUBLIC — volunteers and users need these, should return 200
const PUBLIC = [
  // Core pages (everyone)
  { path: '/',                             who: 'Everyone' },
  { path: '/story.php',                    who: 'Everyone' },
  { path: '/join.php',                     who: 'New users' },
  { path: '/profile.php',                  who: 'Logged-in users' },
  { path: '/poll/',                        who: 'Everyone' },
  { path: '/constitution/',                who: 'Everyone' },
  // Town pages
  { path: '/z-states/ct/putnam/',          who: 'CT residents' },
  // Volunteer system
  { path: '/volunteer/',                   who: 'Volunteers' },
  // Volunteer Build Kit (docs/state-builder/)
  { path: '/docs/state-builder/VOLUNTEER-ORIENTATION.md', who: 'Volunteers — orientation' },
  { path: '/docs/state-builder/STATE-BUILDER-AI-GUIDE.md', who: 'Volunteers — AI guide' },
  { path: '/docs/state-builder/ETHICS-FOUNDATION.md', who: 'Volunteers — ethics' },
  { path: '/docs/state-builder/state-data-template.json', who: 'Volunteers — data template' },
  { path: '/docs/state-builder/README.md', who: 'Volunteers — getting started' },
  { path: '/docs/state-builder/state-build-checklist.md', who: 'Volunteers — checklist' },
];

// Attack probes (common hacker/scanner URLs)
const PROBES = [
  { path: '/wp-login.php',   reason: 'WordPress probe' },
  { path: '/wp-admin/',      reason: 'WordPress probe' },
  { path: '/xmlrpc.php',     reason: 'WordPress probe' },
  { path: '/.env',           reason: 'Environment file probe' },
  { path: '/owa/',           reason: 'Outlook Web Access probe' },
];

// ============================================
// TEST 1: Public files EXIST (not missing)
// ============================================
test.describe('Inventory: Public files exist on server', () => {
  for (const item of PUBLIC) {
    test(`EXISTS: ${item.path} (${item.who})`, async ({ request }) => {
      const response = await request.get(`${SITE}${item.path}`);
      const status = response.status();

      // Should NOT be 404 (missing)
      expect(status, `${item.path} is MISSING (404) — ${item.who} needs this file`).not.toBe(404);
    });
  }
});

// ============================================
// TEST 2: Public files are ACCESSIBLE (not blocked)
// ============================================
test.describe('Access: Public files return 200', () => {
  for (const item of PUBLIC) {
    test(`ACCESSIBLE: ${item.path} (${item.who})`, async ({ request }) => {
      const response = await request.get(`${SITE}${item.path}`);
      const status = response.status();

      // Should NOT be 403 (blocked by .htaccess)
      expect(status, `${item.path} is BLOCKED (403) — but ${item.who} needs access!`).not.toBe(403);

      // Should be 200
      expect(status, `${item.path} returned ${status} — expected 200 for ${item.who}`).toBe(200);
    });
  }
});

// ============================================
// TEST 3: Blocked files are DENIED
// ============================================
test.describe('Security: Blocked files return 403', () => {
  for (const item of BLOCKED) {
    test(`BLOCKED: ${item.path} (${item.reason})`, async ({ request }) => {
      const response = await request.get(`${SITE}${item.path}`);
      const status = response.status();

      // Should NOT be 200 (exposed!)
      expect(status, `SECURITY RISK: ${item.path} is EXPOSED (200) — ${item.reason}`).not.toBe(200);

      // Should be 403
      expect(status, `${item.path} returned ${status} — expected 403`).toBe(403);
    });
  }
});

// ============================================
// TEST 4: Attack probes are blocked
// ============================================
test.describe('Security: Attack probes blocked', () => {
  for (const item of PROBES) {
    test(`PROBE: ${item.path} (${item.reason})`, async ({ request }) => {
      const response = await request.get(`${SITE}${item.path}`);
      expect(response.status(), `${item.path} should be blocked`).toBe(403);
    });
  }
});
