/**
 * generate-video-walkthrough.js
 * =============================
 * Playwright-powered video walkthrough generator for TPB2.
 * Records a browser session with slow mouse movements and typing
 * to create tutorial videos.
 *
 * Usage:  node scripts/generate-video-walkthrough.js
 * Requires: XAMPP Apache running on localhost
 */

const { chromium } = require('@playwright/test');
const path = require('path');

const BASE_URL = process.env.SITE_URL || 'http://localhost/tpb2';
const AUTH_USER_ID = process.env.AUTH_USER_ID || '1';
const VIDEO_DIR = path.join(__dirname, '..', 'help', 'videos');

// ── Helpers ──────────────────────────────────────────────────────────

async function slowMove(page, x, y, steps = 20) {
    await page.mouse.move(x, y, { steps });
    await page.waitForTimeout(200);
}

async function moveToElement(page, selector, offset = {}) {
    const el = page.locator(selector).first();
    const box = await el.boundingBox();
    if (!box) { console.log(`  [!] Element not found: ${selector}`); return null; }
    const tx = box.x + box.width / 2 + (offset.x || 0);
    const ty = box.y + box.height / 2 + (offset.y || 0);
    await slowMove(page, tx, ty, 25);
    return { x: tx, y: ty };
}

async function clickElement(page, selector, opts = {}) {
    const pos = await moveToElement(page, selector, opts.offset || {});
    if (!pos) return;
    await page.waitForTimeout(opts.pauseBefore || 400);
    await page.mouse.click(pos.x, pos.y);
    await page.waitForTimeout(opts.pauseAfter || 600);
}

async function slowType(page, selector, text, delay = 70) {
    await clickElement(page, selector);
    await page.type(selector, text, { delay });
    await page.waitForTimeout(300);
}

async function pause(page, ms = 1500) {
    await page.waitForTimeout(ms);
}

async function caption(page, text, duration = 2500) {
    // Inject a temporary caption overlay
    await page.evaluate(({ text, duration }) => {
        const existing = document.getElementById('walkthrough-caption');
        if (existing) existing.remove();

        const el = document.createElement('div');
        el.id = 'walkthrough-caption';
        el.textContent = text;
        el.style.cssText = `
            position: fixed; bottom: 40px; left: 50%; transform: translateX(-50%);
            background: rgba(0,0,0,0.85); color: #fff; font-size: 20px; font-family: sans-serif;
            padding: 12px 28px; border-radius: 10px; z-index: 999999;
            letter-spacing: 0.5px; text-align: center; max-width: 80%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            animation: captionFade 0.3s ease-in;
        `;
        // Add animation
        if (!document.getElementById('caption-style')) {
            const style = document.createElement('style');
            style.id = 'caption-style';
            style.textContent = '@keyframes captionFade { from { opacity:0; transform:translateX(-50%) translateY(10px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }';
            document.head.appendChild(style);
        }
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; }, duration - 500);
        setTimeout(() => el.remove(), duration);
    }, { text, duration });
    await page.waitForTimeout(duration);
}

// ── Walkthrough: Discuss & Draft ─────────────────────────────────────

async function walkthroughDiscussAndDraft(context) {
    console.log('Recording: Discuss & Draft Walkthrough');

    const page = await context.newPage();

    // 1. Start on home page
    await page.goto('/', { waitUntil: 'networkidle' });
    await pause(page, 1000);
    await caption(page, 'Welcome to The People\'s Branch');

    // 2. Navigate to Talk
    await caption(page, 'Let\'s draft a mandate for your representatives', 2000);
    await page.goto('/talk/', { waitUntil: 'networkidle' });
    await pause(page, 1000);
    await caption(page, 'This is the Talk page — your civic workspace', 2500);

    // 3. Show the prompt box
    await moveToElement(page, '.mc-input textarea');
    await pause(page, 500);
    await caption(page, 'Type your idea in the prompt box', 2000);

    // 4. Type an idea and click Add
    await slowType(page, '.mc-input textarea', 'Implement 12-year term limits for all members of Congress', 50);
    await pause(page, 800);
    await caption(page, 'Click "Add" to draft without AI', 2000);
    await clickElement(page, '.mc-add', { pauseAfter: 1000 });
    await caption(page, 'Your draft appears as a bubble', 2000);

    // 5. Type another idea and use Include AI
    await slowType(page, '.mc-input textarea', 'How can we make campaign finance more transparent?', 50);
    await pause(page, 500);
    await caption(page, 'Click "Include AI" to get AI refinement', 2500);
    await clickElement(page, '.mc-ask-ai', { pauseAfter: 5000 });
    await caption(page, 'AI responds with a refined version', 2500);

    // 6. Show CRUD on a bubble — edit
    await pause(page, 500);
    const editBtn = page.locator('.mc-draft-edit').first();
    if (await editBtn.count() > 0 && await editBtn.isVisible()) {
        await caption(page, 'Edit any bubble with the pencil icon', 2000);
        await clickElement(page, '.mc-draft-edit', { pauseAfter: 800 });
        await pause(page, 1000);
        // Cancel edit
        const cancelBtn = page.locator('.mc-edit-cancel').first();
        if (await cancelBtn.count() > 0 && await cancelBtn.isVisible()) {
            await clickElement(page, '.mc-edit-cancel', { pauseAfter: 500 });
        }
    }

    // 7. Check a scope checkbox
    await caption(page, 'Check a scope to prepare for saving', 2000);
    const federalCheck = page.locator('.mc-scope-check').first().locator('input');
    if (await federalCheck.count() > 0) {
        await clickElement(page, '.mc-scope-check', { pauseAfter: 800 });
        await caption(page, 'Federal checked — Save button appears', 2000);
    }

    // 8. Click Save
    const saveBtn = page.locator('.mc-draft-save').first();
    if (await saveBtn.count() > 0 && await saveBtn.isVisible()) {
        await caption(page, 'Click Save to publish your mandate', 2000);
        await clickElement(page, '.mc-draft-save', { pauseAfter: 1500 });
        await caption(page, 'Saved! It now appears in the community summary below', 2500);
    }

    // 9. Scroll to summary — scroll to bottom of page first to ensure visibility
    await page.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }));
    await pause(page, 2000);
    await page.evaluate(() => {
        const el = document.querySelector('.mandate-summary');
        if (el) el.scrollIntoView({ behavior: 'instant', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'The community summary shows all saved mandates', 2500);

    // 10. Click filter tabs — wait for them to be in view
    await page.evaluate(() => {
        const tabs = document.querySelector('#claudia-inline-level-tabs');
        if (tabs) tabs.scrollIntoView({ behavior: 'instant', block: 'center' });
    });
    await pause(page, 500);
    const stateTab = page.locator('.level-tab[data-level="mandate-state"]');
    if (await stateTab.count() > 0 && await stateTab.isVisible()) {
        await clickElement(page, '.level-tab[data-level="mandate-state"]', { pauseAfter: 1200 });
        await caption(page, 'Filter by Federal, State, or Town', 2500);
    }

    const allTab = page.locator('.level-tab[data-level=""]');
    if (await allTab.count() > 0 && await allTab.isVisible()) {
        await clickElement(page, '.level-tab[data-level=""]', { pauseAfter: 800 });
    }

    // 11. End — scroll back up for clean finish
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    await pause(page, 1500);
    await caption(page, 'That\'s Discuss & Draft — your voice in democracy', 3000);
    await pause(page, 1000);

    await page.close();
    console.log('  Recording complete.');
}

// ── Main ─────────────────────────────────────────────────────────────

async function main() {
    const fs = require('fs');
    fs.mkdirSync(VIDEO_DIR, { recursive: true });

    console.log('Launching browser with video recording...');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
        deviceScaleFactor: 1,
        baseURL: BASE_URL,
        recordVideo: {
            dir: VIDEO_DIR,
            size: { width: 1280, height: 800 }
        }
    });

    // Set auth cookie
    const domain = new URL(BASE_URL).hostname;
    await context.addCookies([
        { name: 'tpb_user_id', value: AUTH_USER_ID, domain, path: '/' }
    ]);
    console.log(`Authenticated as user_id=${AUTH_USER_ID}`);

    await walkthroughDiscussAndDraft(context);

    await context.close();
    await browser.close();

    // Rename the video file — find the new recording (not the old named one)
    const dest = path.join(VIDEO_DIR, 'discuss-and-draft-walkthrough.webm');
    const files = fs.readdirSync(VIDEO_DIR).filter(f => f.endsWith('.webm') && path.join(VIDEO_DIR, f) !== dest);
    if (files.length > 0) {
        const latest = files.sort().pop();
        const src = path.join(VIDEO_DIR, latest);
        if (fs.existsSync(dest)) fs.unlinkSync(dest);
        fs.renameSync(src, dest);
        console.log(`\nVideo saved: help/videos/discuss-and-draft-walkthrough.webm`);
    } else if (fs.existsSync(dest)) {
        console.log(`\nVideo saved (overwritten): help/videos/discuss-and-draft-walkthrough.webm`);
    } else {
        console.log('\n[!] No video file found — check Playwright video support.');
    }
}

main().catch(err => {
    console.error('Video generation failed:', err);
    process.exit(1);
});
