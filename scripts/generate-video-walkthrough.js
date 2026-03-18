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

async function injectCursor(page) {
    await page.evaluate(() => {
        if (document.getElementById('fake-cursor')) return;
        const cursor = document.createElement('div');
        cursor.id = 'fake-cursor';
        cursor.style.cssText = `
            position: fixed; top: 0; left: 0; width: 20px; height: 20px;
            z-index: 9999999; pointer-events: none;
            transition: left 0.05s linear, top 0.05s linear;
        `;
        cursor.innerHTML = `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M2 2L8.5 18L10.5 11L17.5 9L2 2Z" fill="white" stroke="black" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>`;
        document.body.appendChild(cursor);
    });
}

async function updateCursor(page, x, y) {
    await page.evaluate(({ x, y }) => {
        const cursor = document.getElementById('fake-cursor');
        if (cursor) {
            cursor.style.left = x + 'px';
            cursor.style.top = y + 'px';
        }
    }, { x, y });
}

async function clickFlash(page, x, y) {
    // Brief yellow ring on click
    await page.evaluate(({ x, y }) => {
        const ring = document.createElement('div');
        ring.style.cssText = `
            position: fixed; left: ${x - 15}px; top: ${y - 15}px;
            width: 30px; height: 30px; border-radius: 50%;
            border: 2px solid #d4af37; pointer-events: none;
            z-index: 9999998; animation: clickRing 0.4s ease-out forwards;
        `;
        if (!document.getElementById('click-ring-style')) {
            const style = document.createElement('style');
            style.id = 'click-ring-style';
            style.textContent = '@keyframes clickRing { from { transform:scale(0.5); opacity:1; } to { transform:scale(1.5); opacity:0; } }';
            document.head.appendChild(style);
        }
        document.body.appendChild(ring);
        setTimeout(() => ring.remove(), 400);
    }, { x, y });
}

async function slowMove(page, x, y, steps = 20) {
    await page.mouse.move(x, y, { steps });
    await updateCursor(page, x, y);
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
    await clickFlash(page, pos.x, pos.y);
    await page.mouse.click(pos.x, pos.y);
    await page.waitForTimeout(opts.pauseAfter || 600);
}

async function slowType(page, selector, text, delay = 70) {
    await clickElement(page, selector);
    await page.type(selector, text, { delay });
    await page.waitForTimeout(300);
}

async function hoverElement(page, selector, duration = 1500) {
    const pos = await moveToElement(page, selector);
    if (!pos) return;
    // Show native title tooltip by injecting a visible tooltip
    const title = await page.locator(selector).first().getAttribute('title');
    if (title) {
        await page.evaluate(({ x, y, title, duration }) => {
            const tip = document.createElement('div');
            tip.id = 'hover-tooltip';
            tip.textContent = title;
            tip.style.cssText = `
                position: fixed; left: ${x + 15}px; top: ${y - 35}px;
                background: #222; color: #fff; font-size: 13px; font-family: sans-serif;
                padding: 6px 12px; border-radius: 6px; z-index: 9999998;
                box-shadow: 0 2px 8px rgba(0,0,0,0.4); pointer-events: none;
                max-width: 300px; white-space: nowrap;
                animation: tipFade 0.2s ease-in;
            `;
            if (!document.getElementById('tip-style')) {
                const s = document.createElement('style');
                s.id = 'tip-style';
                s.textContent = '@keyframes tipFade { from { opacity:0; } to { opacity:1; } }';
                document.head.appendChild(s);
            }
            document.body.appendChild(tip);
            setTimeout(() => { tip.style.opacity = '0'; tip.style.transition = 'opacity 0.3s'; }, duration - 300);
            setTimeout(() => tip.remove(), duration);
        }, { x: pos.x, y: pos.y, title, duration });
    }
    await page.hover(selector);
    await page.waitForTimeout(duration);
}

async function selectDropdown(page, selector, value, displayText) {
    await moveToElement(page, selector);
    await pause(page, 300);
    await page.selectOption(selector, value);
    await pause(page, 500);
    if (displayText) {
        await caption(page, displayText, 1500);
    }
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
    await injectCursor(page);
    await pause(page, 1000);
    await caption(page, 'Welcome to The People\'s Branch');

    // 2. Navigate to Talk
    await caption(page, 'Let\'s draft a mandate for your representatives', 2000);
    await page.goto('/talk/', { waitUntil: 'networkidle' });
    await injectCursor(page);
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

// ── Profile Walkthrough (Full Interactive) ───────────────────────────

async function walkthroughProfile(context) {
    console.log('Recording: Profile Walkthrough');

    const page = await context.newPage();

    // 1. Open profile — overview
    await page.goto('/profile.php', { waitUntil: 'networkidle' });
    await injectCursor(page);
    await pause(page, 1000);
    await caption(page, 'This is your Profile -- your civic identity', 2500);

    // 2. Trust Journey — hover each step to show tooltips
    await moveToElement(page, '.journey-steps');
    await pause(page, 500);
    await caption(page, 'The Trust Journey tracks your progress', 2500);
    const journeySteps = await page.locator('.journey-step').all();
    for (const step of journeySteps) {
        const box = await step.boundingBox();
        if (box) {
            await slowMove(page, box.x + box.width / 2, box.y + box.height / 2, 15);
            await page.hover('.journey-step >> nth=' + journeySteps.indexOf(step));
            await pause(page, 600);
        }
    }
    await caption(page, 'Each step earns civic points and raises your trust level', 2500);

    // 3. Civic points display
    await pause(page, 500);
    await caption(page, 'Your total civic points appear below the journey bar', 2000);

    // 4. Location section
    await page.evaluate(() => {
        const el = document.getElementById('town');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'Location -- your town, state, and districts', 2500);
    await moveToElement(page, '.location-display .town');
    await pause(page, 800);
    const districts = page.locator('.location-display .districts');
    if (await districts.count() > 0) {
        await moveToElement(page, '.location-display .districts');
        await pause(page, 800);
    }
    await caption(page, 'Your congressional district connects you to your representatives', 2500);

    // Hover the Change button
    const changeBtn = page.locator('#changeLocationBtn');
    if (await changeBtn.count() > 0 && await changeBtn.isVisible()) {
        await hoverElement(page, '#changeLocationBtn', 1500);
        await caption(page, 'Click Change to update your location', 1500);
    }

    // Hover the Go to My Town button
    const townBtn = page.locator('a:has-text("Go to My Town")');
    if (await townBtn.count() > 0 && await townBtn.isVisible()) {
        await hoverElement(page, 'a:has-text("Go to My Town")', 1500);
    }

    // 5. Identity section — interact with fields
    await page.evaluate(() => {
        const cards = document.querySelectorAll('.card h2');
        for (const h of cards) {
            if (h.textContent.includes('Identity')) {
                h.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'center' });
                break;
            }
        }
    });
    await pause(page, 1000);
    await caption(page, 'Identity -- your name and age range', 2500);

    // Hover name fields
    await hoverElement(page, '#firstName', 1000);
    await hoverElement(page, '#lastName', 1000);

    // Open age bracket dropdown
    await caption(page, 'Select your age range from the dropdown', 2000);
    await selectDropdown(page, '#ageBracket', '55-64', 'Age range: 55-64');

    // 6. Privacy settings — toggle checkboxes
    await page.evaluate(() => {
        const el = document.getElementById('showFirstName');
        if (el) el.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'Privacy Settings -- control what others see', 2500);

    // Toggle show last name off then on to demonstrate
    const showLast = page.locator('#showLastName');
    if (await showLast.count() > 0) {
        await clickElement(page, '#showLastName', { pauseAfter: 800 });
        await caption(page, 'Uncheck last name -- preview updates instantly', 2000);
        await moveToElement(page, '#displayPreview');
        await pause(page, 1000);
        await clickElement(page, '#showLastName', { pauseAfter: 800 });
        await caption(page, 'Check it back -- preview shows your full name', 2000);
        await moveToElement(page, '#displayPreview');
        await pause(page, 1000);
    }

    // Toggle age bracket
    const showAge = page.locator('#showAgeBracket');
    if (await showAge.count() > 0) {
        await clickElement(page, '#showAgeBracket', { pauseAfter: 800 });
        await caption(page, 'Toggle age bracket on or off', 1500);
        await moveToElement(page, '#displayPreview');
        await pause(page, 800);
    }

    await caption(page, 'Your user ID always shows -- name and age are opt-in', 2500);

    // Hover Save Changes button
    await hoverElement(page, '#identityForm button[type="submit"]', 1500);
    await caption(page, 'Click Save Changes to apply', 1500);

    // 7. Notifications — hover and toggle
    await page.evaluate(() => {
        const el = document.getElementById('notifyThreatBulletin');
        if (el) el.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'Notifications -- opt in to the daily threat bulletin', 2500);
    const bulletinCheck = page.locator('#notifyThreatBulletin');
    if (await bulletinCheck.count() > 0 && await bulletinCheck.isEnabled()) {
        await clickElement(page, '#notifyThreatBulletin', { pauseAfter: 600 });
        await caption(page, 'Toggle to receive or stop the daily email', 2000);
        await clickElement(page, '#notifyThreatBulletin', { pauseAfter: 400 });
    }

    // 8. Verification — hover buttons
    await page.evaluate(() => {
        const el = document.getElementById('email');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'Verification -- email, phone, and password', 2500);
    await hoverElement(page, '#emailInput', 1000);
    await caption(page, 'Verify your email to unlock drafting and voting', 2000);

    // Hover the verify/change email button
    const emailBtn = page.locator('#verifyEmailBtn, #changeEmailBtn');
    if (await emailBtn.count() > 0 && await emailBtn.isVisible()) {
        await hoverElement(page, '#verifyEmailBtn, #changeEmailBtn', 1500);
    }

    await hoverElement(page, '#phone', 1000);
    await caption(page, 'Add phone 2FA to reach Level 3 Verified', 2000);

    // Hover phone verify/change button
    const phoneBtn = page.locator('#verifyPhoneBtn, #changePhoneBtn');
    if (await phoneBtn.count() > 0 && await phoneBtn.isVisible()) {
        await hoverElement(page, '#verifyPhoneBtn, #changePhoneBtn', 1500);
    }

    // 9. Password
    const pwSection = page.locator('#password');
    if (await pwSection.count() > 0) {
        await page.evaluate(() => {
            const el = document.getElementById('password');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        await pause(page, 1000);
        await caption(page, 'Set a password to log in from any device', 2500);
        await hoverElement(page, '#newPassword', 800);
        await hoverElement(page, '#confirmPassword', 800);
        await hoverElement(page, '#savePasswordBtn', 1000);
    }

    // 10. Volunteer section — skills grid, primary dropdown
    await page.evaluate(() => {
        const cards = document.querySelectorAll('.card h2');
        for (const h of cards) {
            if (h.textContent.includes('Volunteer')) {
                h.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'center' });
                break;
            }
        }
    });
    await pause(page, 1000);
    await caption(page, 'Volunteer -- apply to help build the platform', 2500);

    // Hover skill grid items if visible
    const skillItems = await page.locator('#skillsGrid label').all();
    if (skillItems.length > 0) {
        await caption(page, 'Select your skills from the grid', 2000);
        for (let i = 0; i < Math.min(skillItems.length, 5); i++) {
            const box = await skillItems[i].boundingBox();
            if (box) {
                await slowMove(page, box.x + box.width / 2, box.y + box.height / 2, 12);
                await pause(page, 400);
            }
        }
    }

    // Primary skill dropdown
    const primarySkill = page.locator('#primarySkill');
    if (await primarySkill.count() > 0 && await primarySkill.isVisible()) {
        await caption(page, 'Choose your primary skill from the dropdown', 2000);
        await moveToElement(page, '#primarySkill');
        await pause(page, 800);
    }

    await caption(page, 'Write a bio and save your volunteer info', 2500);

    // 11. End — scroll back up
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    await pause(page, 1500);
    await caption(page, 'That\'s your Profile -- your civic identity on TPB', 3000);
    await pause(page, 1000);

    await page.close();
    console.log('  Recording complete.');
}

// ── Main ─────────────────────────────────────────────────────────────

// Which walkthrough to record (pass as CLI arg, or 'all')
const WALKTHROUGH = process.argv[2] || 'all';

async function recordWalkthrough(name, fn) {
    const fs = require('fs');
    fs.mkdirSync(VIDEO_DIR, { recursive: true });

    console.log(`\nLaunching browser for: ${name}`);
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

    const domain = new URL(BASE_URL).hostname;
    await context.addCookies([
        { name: 'tpb_user_id', value: AUTH_USER_ID, domain, path: '/' }
    ]);

    await fn(context);

    await context.close();
    await browser.close();

    // Rename the recorded file
    const destFile = name + '-walkthrough.webm';
    const dest = path.join(VIDEO_DIR, destFile);
    const files = fs.readdirSync(VIDEO_DIR)
        .filter(f => f.endsWith('.webm') && !f.includes('walkthrough') && !f.includes('narration'));
    if (files.length > 0) {
        const latest = files.sort().pop();
        const src = path.join(VIDEO_DIR, latest);
        if (fs.existsSync(dest)) fs.unlinkSync(dest);
        fs.renameSync(src, dest);
        console.log(`Video saved: help/videos/${destFile}`);
    } else {
        console.log(`[!] No new video file found for ${name}`);
    }
}

const walkthroughs = {
    'discuss-and-draft': walkthroughDiscussAndDraft,
    'profile': walkthroughProfile,
};

async function main() {
    if (WALKTHROUGH === 'all') {
        for (const [name, fn] of Object.entries(walkthroughs)) {
            await recordWalkthrough(name, fn);
        }
    } else if (walkthroughs[WALKTHROUGH]) {
        await recordWalkthrough(WALKTHROUGH, walkthroughs[WALKTHROUGH]);
    } else {
        console.error(`Unknown walkthrough: ${WALKTHROUGH}`);
        console.error(`Available: ${Object.keys(walkthroughs).join(', ')}, all`);
        process.exit(1);
    }
}

main().catch(err => {
    console.error('Video generation failed:', err);
    process.exit(1);
});
