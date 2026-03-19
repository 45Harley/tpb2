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
const FFMPEG_PATH = process.env.FFMPEG_PATH ||
    'C:\\Users\\harle\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-8.1-full_build\\bin\\ffmpeg.exe';

// ── Timing log for audio sync ────────────────────────────────────────
let recordingStartTime = 0;
let captionLog = [];

function startTiming() {
    recordingStartTime = Date.now();
    captionLog = [];
}

function logCaption(text) {
    const elapsed = (Date.now() - recordingStartTime) / 1000;
    captionLog.push({ time: elapsed, text });
}

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
    // 1. Get all options and position
    const options = await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return [];
        return Array.from(el.options).map(o => ({ value: o.value, text: o.textContent.trim() }));
    }, selector);
    const box = await page.locator(selector).first().boundingBox();
    if (!box || options.length === 0) return;

    // 2. Move cursor to the select and click
    await moveToElement(page, selector);
    await clickFlash(page, box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForTimeout(300);

    // 3. Inject fake dropdown overlay
    await page.evaluate(({ options, box, targetValue }) => {
        const overlay = document.createElement('div');
        overlay.id = 'fake-dropdown';
        overlay.style.cssText = `
            position: fixed; left: ${box.x}px; top: ${box.y + box.height + 2}px;
            width: ${Math.max(box.width, 160)}px; max-height: 220px; overflow-y: auto;
            background: #1a1a2e; border: 1px solid #444; border-radius: 6px;
            z-index: 9999997; box-shadow: 0 4px 16px rgba(0,0,0,0.6);
            animation: dropOpen 0.15s ease-out;
        `;
        if (!document.getElementById('drop-style')) {
            const s = document.createElement('style');
            s.id = 'drop-style';
            s.textContent = `
                @keyframes dropOpen { from { opacity:0; transform:scaleY(0.8); transform-origin:top; } to { opacity:1; transform:scaleY(1); } }
                .fake-drop-item { padding: 8px 12px; color: #ccc; font-size: 14px; font-family: sans-serif; cursor: pointer; }
                .fake-drop-item:hover, .fake-drop-item.highlighted { background: #d4af37; color: #000; }
            `;
            document.head.appendChild(s);
        }
        options.forEach(opt => {
            if (!opt.value) return; // skip empty placeholder
            const item = document.createElement('div');
            item.className = 'fake-drop-item';
            item.dataset.value = opt.value;
            item.textContent = opt.text;
            overlay.appendChild(item);
        });
        document.body.appendChild(overlay);
    }, { options, box, targetValue: value });
    await page.waitForTimeout(500);

    // 4. Cursor moves down through a few options, then lands on the target
    const items = await page.locator('#fake-dropdown .fake-drop-item').all();
    let targetIndex = -1;
    for (let i = 0; i < items.length; i++) {
        const val = await items[i].getAttribute('data-value');
        if (val === value) { targetIndex = i; break; }
    }
    // Hover through a few items leading up to the target
    const startAt = Math.max(0, targetIndex - 2);
    for (let i = startAt; i <= targetIndex && i < items.length; i++) {
        const itemBox = await items[i].boundingBox();
        if (itemBox) {
            await slowMove(page, itemBox.x + itemBox.width / 2, itemBox.y + itemBox.height / 2, 10);
            await page.evaluate((idx) => {
                document.querySelectorAll('.fake-drop-item').forEach((el, j) => {
                    el.classList.toggle('highlighted', j === idx);
                });
            }, i);
            await page.waitForTimeout(350);
        }
    }

    // 5. Click the target option
    if (targetIndex >= 0 && targetIndex < items.length) {
        const targetBox = await items[targetIndex].boundingBox();
        if (targetBox) {
            await clickFlash(page, targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2);
        }
    }
    await page.waitForTimeout(200);

    // 6. Remove overlay and actually set the value
    await page.evaluate(() => {
        const dd = document.getElementById('fake-dropdown');
        if (dd) { dd.style.opacity = '0'; dd.style.transition = 'opacity 0.15s'; }
        setTimeout(() => { if (dd) dd.remove(); }, 150);
    });
    await page.selectOption(selector, value);
    await page.waitForTimeout(400);

    if (displayText) {
        await caption(page, displayText, 1500);
    }
}

async function pause(page, ms = 1500) {
    await page.waitForTimeout(ms);
}

async function caption(page, text, duration = 2500) {
    logCaption(text);
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
    startTiming();

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
    startTiming();

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

    // Save timing log
    const timingFile = path.join(VIDEO_DIR, name + '-timing.json');
    fs.writeFileSync(timingFile, JSON.stringify(captionLog, null, 2));
    console.log(`Timing log: help/videos/${name}-timing.json (${captionLog.length} captions)`);
}

// ── Synced Audio Generation ──────────────────────────────────────────

async function generateSyncedAudio(name) {
    const fs = require('fs');
    const { execSync } = require('child_process');

    const timingFile = path.join(VIDEO_DIR, name + '-timing.json');
    if (!fs.existsSync(timingFile)) {
        console.log(`[!] No timing file for ${name} — record video first`);
        return;
    }
    const captions = JSON.parse(fs.readFileSync(timingFile, 'utf8'));

    // TTS narration map: caption text → spoken text (adapt for TTS)
    const ttsAdapt = (text) => text
        .replace(/--/g, ',')
        .replace(/"/g, '')
        .replace(/2FA/g, 'two-factor authentication')
        .replace(/TPB/g, 'T.P.B.')
        .replace(/\.\.\./g, '');

    const clipDir = path.join(VIDEO_DIR, '_clips');
    fs.mkdirSync(clipDir, { recursive: true });

    // Generate individual TTS clips
    console.log(`Generating ${captions.length} TTS clips...`);
    for (let i = 0; i < captions.length; i++) {
        const clipFile = path.join(clipDir, `clip_${String(i).padStart(3, '0')}.mp3`);
        const spokenText = ttsAdapt(captions[i].text);
        // Write a temp Python script to avoid CLI escaping issues
        const pyFile = path.join(clipDir, '_tts.py');
        fs.writeFileSync(pyFile, `
import edge_tts, asyncio
async def go():
    c = edge_tts.Communicate(${JSON.stringify(spokenText)}, 'en-US-JennyNeural', rate='-10%')
    await c.save(r'${clipFile.replace(/\\/g, '\\\\')}')
asyncio.run(go())
`);
        try {
            execSync(`python "${pyFile}"`, { stdio: 'pipe', timeout: 30000 });
        } catch (e) {
            console.log(`  [!] TTS failed for clip ${i}: ${e.message.slice(0, 80)}`);
        }
    }

    // Get duration of each clip using ffprobe
    const FFPROBE = path.join(path.dirname(FFMPEG_PATH), 'ffprobe.exe');

    function getClipDuration(file) {
        try {
            const out = execSync(
                `"${FFPROBE}" -v quiet -show_entries format=duration -of csv=p=0 "${file}"`,
                { encoding: 'utf8', timeout: 5000 }
            ).trim();
            return parseFloat(out) || 0;
        } catch { return 0; }
    }

    // Build ffmpeg concat filter with silence padding
    // Each clip starts at caption[i].time seconds into the video
    const concatList = path.join(clipDir, 'concat.txt');
    let concatContent = '';
    let currentTime = 0;

    for (let i = 0; i < captions.length; i++) {
        const clipFile = path.join(clipDir, `clip_${String(i).padStart(3, '0')}.mp3`);
        if (!fs.existsSync(clipFile)) continue;

        const targetTime = captions[i].time;
        const silenceNeeded = Math.max(0, targetTime - currentTime);

        // Add silence gap if needed
        if (silenceNeeded > 0.1) {
            const silFile = path.join(clipDir, `silence_${String(i).padStart(3, '0')}.mp3`);
            execSync(
                `"${FFMPEG_PATH}" -f lavfi -i anullsrc=r=24000:cl=mono -t ${silenceNeeded.toFixed(3)} -c:a libmp3lame -b:a 64k "${silFile}" -y`,
                { stdio: 'pipe', timeout: 30000 }
            );
            concatContent += `file '${silFile.replace(/\\/g, '/')}'\n`;
            currentTime += silenceNeeded;
        }

        concatContent += `file '${clipFile.replace(/\\/g, '/')}'\n`;
        currentTime += getClipDuration(clipFile);
    }

    fs.writeFileSync(concatList, concatContent);

    // Concatenate all clips + silences into one audio track
    const audioFile = path.join(VIDEO_DIR, name + '-narration-synced.mp3');
    execSync(
        `"${FFMPEG_PATH}" -f concat -safe 0 -i "${concatList}" -c:a libmp3lame -b:a 64k "${audioFile}" -y`,
        { stdio: 'pipe', timeout: 60000 }
    );
    console.log(`Synced audio: help/videos/${name}-narration-synced.mp3`);

    // Merge with video
    const videoFile = path.join(VIDEO_DIR, name + '-walkthrough.webm');
    const finalFile = path.join(VIDEO_DIR, name + '-final.webm');
    execSync(
        `"${FFMPEG_PATH}" -i "${videoFile}" -i "${audioFile}" -c:v copy -c:a libopus -b:a 64k "${finalFile}" -y`,
        { stdio: 'pipe', timeout: 60000 }
    );

    // Swap
    if (fs.existsSync(finalFile)) {
        fs.unlinkSync(videoFile);
        fs.renameSync(finalFile, videoFile);
        console.log(`Final video: help/videos/${name}-walkthrough.webm (synced narration)`);
    }

    // Cleanup clips
    fs.readdirSync(clipDir).forEach(f => fs.unlinkSync(path.join(clipDir, f)));
    fs.rmdirSync(clipDir);
    if (fs.existsSync(audioFile)) fs.unlinkSync(audioFile);
}

// ── Getting Started Walkthrough ──────────────────────────────────────

async function walkthroughGettingStarted(context) {
    console.log('Recording: Getting Started Walkthrough');
    startTiming();

    const page = await context.newPage();

    // Clear auth cookie so we see the logged-out state
    await context.clearCookies();

    // 1. Home page — show the map
    await page.goto('/', { waitUntil: 'networkidle' });
    await injectCursor(page);
    await pause(page, 1000);
    await caption(page, 'Welcome to The People\'s Branch', 2500);
    await caption(page, 'An interactive civic platform for every American', 2500);

    // Pan over the map
    const mapEl = page.locator('#usa-map, .map-container, canvas').first();
    if (await mapEl.count() > 0) {
        const box = await mapEl.boundingBox();
        if (box) {
            await slowMove(page, box.x + box.width * 0.3, box.y + box.height * 0.5, 30);
            await pause(page, 500);
            await slowMove(page, box.x + box.width * 0.7, box.y + box.height * 0.4, 30);
            await pause(page, 500);
        }
    }
    await caption(page, 'The map shows every state — click to explore', 2500);

    // 2. Click Login dropdown — need to hover to open the dropdown menu
    await pause(page, 500);
    await caption(page, 'To get started, click Login', 2000);
    // The nav dropdown opens on hover
    const loginLink = page.locator('.login-link, a[href="/join.php"], a:has-text("Login")').first();
    if (await loginLink.count() > 0) {
        await moveToElement(page, '.login-link, a[href="/join.php"], a:has-text("Login")');
        await pause(page, 800);
        // Try to click the dropdown toggle
        await page.locator('.login-link, a[href="/join.php"], a:has-text("Login")').first().click();
        await pause(page, 800);
    }

    // Navigate to join page
    await caption(page, 'Click New User to create your account', 2500);
    await page.goto('/join.php', { waitUntil: 'networkidle' });
    await injectCursor(page);
    await caption(page, 'This is the sign-up page', 2000);

    // 3. Fill in the form
    await pause(page, 500);
    await caption(page, 'Enter your email address', 2000);
    await slowType(page, '#email', 'maria@example.com', 60);
    await pause(page, 500);

    await caption(page, 'Add your name — optional but helps us serve you better', 2500);
    await slowType(page, '#firstName', 'Maria', 80);
    await pause(page, 300);
    await slowType(page, '#lastName', 'Garcia', 80);
    await pause(page, 500);

    // Select age bracket
    await caption(page, 'Select your age range', 2000);
    await selectDropdown(page, '#ageBracket', '25-44', 'Age: 25-44');
    await pause(page, 500);

    // Hover submit button
    await caption(page, 'Click Continue with Email', 2000);
    await moveToElement(page, '#submitBtn');
    await pause(page, 800);
    await clickFlash(page, (await page.locator('#submitBtn').boundingBox()).x + 60, (await page.locator('#submitBtn').boundingBox()).y + 20);
    await pause(page, 500);

    // 4. Email verification caption (don't actually submit)
    await caption(page, 'Check your inbox for a verification link', 2500);
    await pause(page, 500);
    await caption(page, 'One click and you\'re verified — one email, one citizen', 2500);

    // 5. Navigate to Profile (as logged-in user)
    // Re-add auth cookie for logged-in sections
    const domain = new URL(BASE_URL).hostname;
    await context.addCookies([
        { name: 'tpb_user_id', value: AUTH_USER_ID, domain, path: '/' }
    ]);
    await caption(page, 'After verifying, visit your Profile to set your location', 2500);
    await page.goto('/profile.php', { waitUntil: 'networkidle' });
    await injectCursor(page);
    await pause(page, 1000);
    await caption(page, 'This is your Profile — your civic identity', 2500);

    // Scroll to location section
    await page.evaluate(() => {
        const el = document.getElementById('town');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    await pause(page, 1000);
    await caption(page, 'Set your state and town to connect to your community', 2500);

    // Hover the change/set location button
    const locBtn = page.locator('#changeLocationBtn, #setLocationBtn');
    if (await locBtn.count() > 0 && await locBtn.isVisible()) {
        await hoverElement(page, '#changeLocationBtn, #setLocationBtn', 1500);
        await caption(page, 'Click to choose your state and type your town name', 2500);
    }

    // Show districts if present
    const districts = page.locator('.location-display .districts');
    if (await districts.count() > 0 && await districts.isVisible()) {
        await moveToElement(page, '.location-display .districts');
        await pause(page, 800);
        await caption(page, 'Your congressional district is detected automatically', 2500);
    }

    // 6. Navigate to My Reps
    await caption(page, 'Now let\'s find your representatives', 2000);
    await page.goto('/reps.php?my=1', { waitUntil: 'networkidle' });
    await injectCursor(page);
    await pause(page, 1000);
    await caption(page, 'My Reps — everyone who represents you', 2500);

    // Scroll slowly through reps cards
    await page.evaluate(() => window.scrollTo({ top: 300, behavior: 'smooth' }));
    await pause(page, 1500);
    await caption(page, 'From Congress all the way to your town hall', 2500);

    await page.evaluate(() => window.scrollTo({ top: 600, behavior: 'smooth' }));
    await pause(page, 1500);
    await caption(page, 'See their contact info, voting record, and more', 2500);

    // 7. End — scroll back up
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
    await pause(page, 1500);
    await caption(page, 'That\'s it — you\'re set up and ready to participate', 3000);
    await pause(page, 1000);

    await page.close();
    console.log('  Recording complete.');
}

const walkthroughs = {
    'discuss-and-draft': walkthroughDiscussAndDraft,
    'profile': walkthroughProfile,
    'getting-started': walkthroughGettingStarted,
};

async function main() {
    const names = WALKTHROUGH === 'all'
        ? Object.keys(walkthroughs)
        : walkthroughs[WALKTHROUGH] ? [WALKTHROUGH] : null;

    if (!names) {
        console.error(`Unknown walkthrough: ${WALKTHROUGH}`);
        console.error(`Available: ${Object.keys(walkthroughs).join(', ')}, all`);
        process.exit(1);
    }

    for (const name of names) {
        await recordWalkthrough(name, walkthroughs[name]);
        await generateSyncedAudio(name);
    }
}

main().catch(err => {
    console.error('Video generation failed:', err);
    process.exit(1);
});
