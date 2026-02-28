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
