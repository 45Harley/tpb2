/**
 * generate-user-guides.js
 * =======================
 * Playwright-powered doc generator for TPB2.
 * Navigates the site, captures screenshots at each step,
 * and writes JSON manifests for the PHP guide renderer.
 *
 * Usage:  npm run generate-docs
 * Requires: XAMPP Apache running on localhost
 */

const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE_URL = process.env.SITE_URL || 'http://localhost';
const SCREENSHOT_DIR = path.join(__dirname, '..', 'help', 'screenshots');
const DATA_DIR = path.join(__dirname, '..', 'help', 'data');

// ── Flow definitions ─────────────────────────────────────────────────
// Each flow is an array of steps. Each step has:
//   title       — short heading shown to user
//   description — plain-language explanation (for Maria, Tom, Jamal)
//   alt         — image alt text for accessibility
//   action      — async fn(page) to navigate/interact (null = description-only)
//   screenshot  — options for page.screenshot() (null = no screenshot)
//   slug        — filename slug for the screenshot

const flows = [
    {
        id: 'onboarding',
        title: 'Getting Started',
        subtitle: 'Join, verify your email, set your town, and find your representatives.',
        steps: [
            {
                title: 'Visit The People\'s Branch',
                description: 'Go to the home page. You\'ll see an interactive map of the United States and links to get involved in your community.',
                alt: 'The People\'s Branch home page with interactive USA map',
                slug: 'home',
                action: async (page) => {
                    await page.goto('/', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(1000);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Click "Login" then "New User"',
                description: 'In the top-right corner of the navigation bar, click "Login" to open the dropdown, then click "New User." This takes you to the sign-up page where you can create your account.',
                alt: 'The Join page with email sign-up form',
                slug: 'join',
                action: async (page) => {
                    await page.goto('/join.php', { waitUntil: 'networkidle' });
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Enter your information',
                description: 'Type your email address. You can also add your first name, last name, and age range — these are optional but help us serve you better. Then click "Continue with Email."',
                alt: 'Join form filled in with email and name fields',
                slug: 'fill-form',
                action: async (page) => {
                    await page.fill('#email', 'maria@example.com');
                    await page.fill('#firstName', 'Maria');
                    await page.fill('#lastName', 'Garcia');
                    await page.selectOption('#ageBracket', '25-44');
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Check your email',
                description: 'We\'ll send you a verification link to the email you entered. Open your email and click the link. This confirms your identity — one email equals one citizen. The link works on any device.',
                alt: null,
                slug: 'check-email',
                action: null,
                screenshot: null
            },
            {
                title: 'Set your town',
                description: 'After verifying your email, visit your Profile page. Select your state, then type your town name to set your location. This connects you to your local civic community and lets you see local representatives.',
                alt: 'Profile page with state and town selection fields',
                slug: 'profile',
                action: async (page) => {
                    await page.goto('/profile.php', { waitUntil: 'networkidle' });
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Find your representatives',
                description: 'Click "My Reps" in the navigation menu to see your federal, state, and local representatives. Know who speaks for you — from Congress all the way to your town hall.',
                alt: 'Representatives page showing federal and state officials',
                slug: 'reps',
                action: async (page) => {
                    await page.goto('/reps.php', { waitUntil: 'networkidle' });
                },
                screenshot: { fullPage: false }
            }
        ]
    },

    // ── Talk Flow ─────────────────────────────────────────────────────
    {
        id: 'talk',
        title: 'Using Talk',
        subtitle: 'Share ideas, vote, reply, and brainstorm with your community.',
        steps: [
            {
                title: 'Open USA Talk',
                description: 'Click "USA Talk" in the main navigation bar. This is the national civic brainstorming stream where citizens share ideas, ask questions, and propose solutions.',
                alt: 'The Talk stream page showing ideas from citizens across the USA',
                slug: 'stream',
                action: async (page) => {
                    await page.goto('/talk/', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(1000);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Write your idea',
                description: 'If you\'re verified, you\'ll see a text box at the top. Type your thought, question, or idea. You can write up to 2,000 characters. Press Enter or click the send arrow to post.',
                alt: 'The Talk input area with text box and send button',
                slug: 'input',
                action: async (page) => {
                    var input = page.locator('#ts0-input');
                    if (await input.count() > 0) {
                        await input.fill('What if every town had a public civic calendar?');
                    }
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Use AI Brainstorm',
                description: 'Click the "AI" button next to the text box to toggle AI brainstorm mode. When enabled, after you post your idea, an AI assistant will respond with analysis and suggestions to help refine your thinking.',
                alt: 'The AI toggle button highlighted next to the text input',
                slug: 'ai-toggle',
                action: null,
                screenshot: null
            },
            {
                title: 'Vote on ideas',
                description: 'Every idea has thumbs-up and thumbs-down buttons. Vote to signal agreement or disagreement. Your vote helps the community see which ideas have support.',
                alt: 'An idea card showing vote buttons',
                slug: 'vote',
                action: null,
                screenshot: null
            },
            {
                title: 'Filter by status',
                description: 'Use the filter buttons at the top of the stream — All, Raw, Refining, Distilled, Actionable. Ideas mature as the community discusses and refines them.',
                alt: 'Filter bar showing status options: All, Raw, Refining, Distilled, Actionable',
                slug: 'filters',
                action: null,
                screenshot: null
            },
            {
                title: 'Browse by location',
                description: 'Talk works at three levels: USA (national), your state, and your town. Visit your state or town page to see local conversations. The breadcrumb at the top shows where you are: USA > State > Town.',
                alt: 'Talk page showing geographic breadcrumb navigation',
                slug: 'geo',
                action: async (page) => {
                    await page.goto('/talk/?state=7', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                },
                screenshot: { fullPage: false }
            }
        ]
    },

    // ── Elections / The Fight Flow ────────────────────────────────────
    {
        id: 'elections',
        title: 'Elections & The Fight',
        subtitle: 'Take pledges, land knockouts, rate threats, and hold power accountable.',
        steps: [
            {
                title: 'Open Elections',
                description: 'Click "Elections" in the main navigation bar. This is the Elections 2026 hub — tracking threats to democracy, citizen pledges, and races that matter.',
                alt: 'Elections 2026 landing page with threat count and citizen stats',
                slug: 'landing',
                action: async (page) => {
                    await page.goto('/elections/', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Go to The Fight',
                description: 'Click "The Fight" in the sub-navigation. This is where citizens take pledges and land knockouts — concrete civic actions you commit to before Election Day.',
                alt: 'The Fight page showing pledges on the left and knockouts on the right',
                slug: 'the-fight',
                action: async (page) => {
                    await page.goto('/elections/the-fight.php', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Take a pledge',
                description: 'On the left column, check the boxes next to pledges you commit to — like "I will vote in Nov 2026" or "I will register voters." Each pledge you take earns civic points and lights up an arrow.',
                alt: 'Pledge checkboxes with green checks and lit arrows',
                slug: 'pledge',
                action: null,
                screenshot: null
            },
            {
                title: 'Land a knockout',
                description: 'On the right column, check off knockouts as you achieve them — like "Registered someone else to vote" or "Rep changed position publicly." These are real-world results that prove your pledge.',
                alt: 'Knockout checkboxes on the right side of the grid',
                slug: 'knockout',
                action: null,
                screenshot: null
            },
            {
                title: 'Rate threats',
                description: 'Click "Threats" in the sub-navigation to see executive threats to democracy ranked by severity. Rate each threat and share it so others can see what\'s happening.',
                alt: 'Threats page showing severity-ranked executive threats',
                slug: 'threats',
                action: async (page) => {
                    await page.goto('/elections/threats.php', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Join the community stream',
                description: 'Scroll down on The Fight page to find the Community Stream. Post ideas, share evidence, and discuss what the Golden Rule demands right now. This is where citizens think together.',
                alt: 'Community Stream section on The Fight page with input area',
                slug: 'stream',
                action: async (page) => {
                    await page.goto('/elections/the-fight.php', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                    // Scroll to the talk stream section
                    await page.evaluate(() => {
                        var el = document.querySelector('.talk-stream');
                        if (el) el.scrollIntoView({ behavior: 'instant', block: 'start' });
                    });
                    await page.waitForTimeout(300);
                },
                screenshot: { fullPage: false }
            }
        ]
    },

    // ── Profile & Trust Journey Flow ─────────────────────────────────
    {
        id: 'profile',
        title: 'Your Profile & Trust Journey',
        subtitle: 'Build your civic identity step by step — from new user to fully verified citizen.',
        steps: [
            {
                title: 'Open your Profile',
                description: 'After logging in, click "My TPB" in the navigation bar, then click "My Profile." This is your civic identity — who you are, where you live, and your trust level.',
                alt: 'Profile page showing the Trust Journey progress bar',
                slug: 'overview',
                action: async (page) => {
                    await page.goto('/profile.php', { waitUntil: 'networkidle' });
                    await page.waitForTimeout(500);
                },
                screenshot: { fullPage: false }
            },
            {
                title: 'Your Trust Journey',
                description: 'At the top of your profile, you\'ll see your Trust Journey — a row of steps showing your progress. Each step you complete raises your trust level and unlocks more features. The steps are: Email, Location, Name, Email Verified, Phone 2FA.',
                alt: 'Trust Journey showing completed and pending steps',
                slug: 'journey',
                action: null,
                screenshot: null
            },
            {
                title: 'Set your location',
                description: 'Click "Set My Location" and enter your zip code or pick your state and town. Your location connects you to your local civic community — you\'ll see local representatives, local Talk conversations, and town-specific information.',
                alt: 'Location section with state and town selector',
                slug: 'location',
                action: null,
                screenshot: null
            },
            {
                title: 'Verify your email',
                description: 'In the Verification section, click "Send Verification Link." Check your email and click the link. Once verified, your trust level rises to Level 2 (Remembered) — you can now post ideas in Talk and vote on threats.',
                alt: 'Email verification section with send link button',
                slug: 'verify-email',
                action: null,
                screenshot: null
            },
            {
                title: 'Set a password',
                description: 'After verifying your email, you can set a password. This is optional — you can always log in via email link — but a password lets you log in faster from any browser. Choose at least 8 characters.',
                alt: 'Password section with create password fields',
                slug: 'password',
                action: null,
                screenshot: null
            },
            {
                title: 'Add phone for 2FA',
                description: 'Enter your phone number and click "Verify." You\'ll receive a text message with a verification link. Once confirmed, your trust level rises to Level 3 (Verified) — the highest standard trust level. Two-factor authentication protects your account.',
                alt: 'Phone 2FA verification section',
                slug: 'phone-2fa',
                action: null,
                screenshot: null
            },
            {
                title: 'Volunteer (optional)',
                description: 'Once fully verified, a Volunteer section appears at the bottom of your profile. Apply to help build The People\'s Branch — pick your skills (technical, content, design, etc.) and join the team. Your application is reviewed by a project manager.',
                alt: 'Volunteer application section with skill checkboxes',
                slug: 'volunteer',
                action: null,
                screenshot: null
            }
        ]
    }
];

// ── Generator ────────────────────────────────────────────────────────

async function generateGuides() {
    // Ensure output directories exist
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    fs.mkdirSync(DATA_DIR, { recursive: true });

    console.log('Launching browser...');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
        deviceScaleFactor: 2,  // retina-quality screenshots
        baseURL: BASE_URL
    });

    for (const flow of flows) {
        console.log(`\nGenerating flow: ${flow.title}`);
        const page = await context.newPage();
        const manifestSteps = [];

        for (let i = 0; i < flow.steps.length; i++) {
            const step = flow.steps[i];
            const stepNum = String(i + 1).padStart(2, '0');
            const filename = `${flow.id}-${stepNum}-${step.slug}.png`;

            process.stdout.write(`  Step ${i + 1}: ${step.title}...`);

            // Execute navigation action
            if (step.action) {
                try {
                    await step.action(page);
                } catch (err) {
                    console.log(` [action failed: ${err.message}]`);
                }
            }

            // Take screenshot
            let screenshotFile = null;
            if (step.screenshot) {
                const screenshotPath = path.join(SCREENSHOT_DIR, filename);
                await page.screenshot({
                    path: screenshotPath,
                    fullPage: step.screenshot.fullPage || false,
                    type: 'png'
                });
                screenshotFile = filename;
                console.log(` saved ${filename}`);
            } else {
                console.log(' (no screenshot)');
            }

            manifestSteps.push({
                number: i + 1,
                title: step.title,
                description: step.description,
                alt: step.alt,
                screenshot: screenshotFile
            });
        }

        // Write JSON manifest
        const manifest = {
            id: flow.id,
            title: flow.title,
            subtitle: flow.subtitle,
            generated: new Date().toISOString(),
            stepCount: flow.steps.length,
            steps: manifestSteps
        };

        const manifestPath = path.join(DATA_DIR, `${flow.id}.json`);
        fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
        console.log(`  Manifest written: help/data/${flow.id}.json`);

        await page.close();
    }

    await browser.close();
    console.log('\nDone! All guides generated.');
}

generateGuides().catch(err => {
    console.error('Guide generation failed:', err);
    process.exit(1);
});
