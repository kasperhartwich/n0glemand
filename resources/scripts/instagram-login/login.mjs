// Logs in to Instagram with a headless Chromium (Playwright) and prints ONE
// JSON line on stdout. Started by App\Services\Instagram\InstagramBrowserLoginClient.
//
// Input, all by environment (never argv, which `ps` shows):
//   INSTAGRAM_LOGIN_USERNAME, INSTAGRAM_LOGIN_PASSWORD
//   INSTAGRAM_PROFILE_DIR      persistent browser profile (device cookies, local storage)
//   INSTAGRAM_BROWSER_DIR      folder that holds node_modules/playwright (default: this folder)
//   INSTAGRAM_FORCE_FRESH      "1": drop the profile's session cookies first and sign in again
//   INSTAGRAM_LOGIN_TIMEOUT    seconds before the script gives up by itself (default 40)
//   INSTAGRAM_CODE_WAIT        seconds to wait for a code or an approval once Instagram asks for
//                              one (default 0: a challenge then ends as checkpoint_required)
//   INSTAGRAM_CHALLENGE_METHOD sms (default) or email: how a challenge page is asked to send its code
//   PLAYWRIGHT_BROWSERS_PATH   where `playwright install` put Chromium
//
// Output: ONE final line {"status":"ok","cookies":[...]} or
// {"status":"<status>","message":"..."} with status ok | invalid_credentials |
// checkpoint_required | two_factor_required | invalid_code | timeout | unknown.
// Before it, while the browser waits for a human, event lines without a status:
//   {"event":"code_required","method":"sms|email|app"}   a code is wanted
//   {"event":"approval_required"}                        approve the login in the Instagram app
// The code arrives as digits in the file `code` inside INSTAGRAM_PROFILE_DIR
// (written 0600 by the app); the script types it in and deletes the file.
// Never prints the password, a code or a cookie value anywhere but the ok
// line's cookie list.

import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const username = process.env.INSTAGRAM_LOGIN_USERNAME ?? '';
const password = process.env.INSTAGRAM_LOGIN_PASSWORD ?? '';
const profileDir = process.env.INSTAGRAM_PROFILE_DIR ?? '';
const browserDir = process.env.INSTAGRAM_BROWSER_DIR || here;
const forceFresh = process.env.INSTAGRAM_FORCE_FRESH === '1';
const timeoutMs = Math.max(5, Number(process.env.INSTAGRAM_LOGIN_TIMEOUT) || 40) * 1000;

const codeWaitMs = Math.max(0, Number(process.env.INSTAGRAM_CODE_WAIT) || 0) * 1000;
const challengeMethod = process.env.INSTAGRAM_CHALLENGE_METHOD === 'email' ? 'email' : 'sms';
const codeFile = path.join(profileDir, 'code');

const LOGIN_URL = 'https://www.instagram.com/accounts/login/';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

const SESSION_COOKIES = new Set(['sessionid', 'ds_user_id', 'csrftoken', 'rur', 'shbid', 'shbts']);

const deadline = Date.now() + timeoutMs;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const timeLeft = () => Math.max(1, deadline - Date.now());

function emit(payload) {
    process.stdout.write(JSON.stringify(payload) + '\n');
}

let context = null;

async function finish(payload, code = 0) {
    emit(payload);
    await context?.close().catch(() => {});
    process.exit(code);
}

/** The page URL without query or fragment, so a message never carries a token. */
function safeUrl(page) {
    try {
        const url = new URL(page.url());

        return url.origin + url.pathname;
    } catch {
        return 'unknown';
    }
}

function instagramCookies(cookies) {
    return cookies
        .filter((c) => {
            const domain = c.domain.replace(/^\./, '');

            return domain === 'instagram.com' || domain.endsWith('.instagram.com');
        })
        .map((c) => ({
            name: c.name,
            value: c.value,
            domain: c.domain,
            path: c.path,
            secure: c.secure,
            expires: c.expires > 0 ? Math.floor(c.expires) : 0,
        }));
}

function hasLiveSession(cookies) {
    const now = Date.now() / 1000;

    return cookies.some((c) => c.name === 'sessionid' && c.value !== '' && (c.expires <= 0 || c.expires > now));
}

async function dismissCookieConsent(page) {
    const button = page
        .getByRole('button', { name: /decline optional cookies|only allow essential cookies|allow all cookies|allow essential and optional cookies/i })
        .first();

    try {
        await button.click({ timeout: 4000 });
    } catch {
        // No dialog for this visit.
    }
}

async function classify(page, context) {
    const url = page.url();

    if (/\/challenge\b/.test(url) || /\/accounts\/suspended\b/.test(url)) {
        return { status: 'checkpoint_required', message: 'Instagram asked for a checkpoint challenge.' };
    }

    if (/two_factor/.test(url) || (await page.locator('input[name="verificationCode"]').count()) > 0) {
        return { status: 'two_factor_required', message: 'Instagram asks for a two-factor code.' };
    }

    if (hasLiveSession(await context.cookies()) && !/\/accounts\/login\b/.test(url)) {
        return { status: 'ok' };
    }

    const alert = page.locator('#slfErrorAlert, [role="alert"]').first();

    if ((await alert.count()) > 0) {
        const text = ((await alert.textContent({ timeout: 1000 }).catch(() => '')) ?? '').toLowerCase();

        if (/incorrect|wrong password|password.*(was|is) not correct|isn.t connected to an account|no users? found|find an account/.test(text)) {
            return { status: 'invalid_credentials', message: 'Instagram rejected the username or password.' };
        }
    }

    return null;
}

const VERDICT_RESERVE = 35000;

const CODE_INPUT = 'input[name="security_code"], input[name="verificationCode"], input[autocomplete="one-time-code"], input[name="email_confirmation_code"]';

async function pageText(page) {
    return ((await page.locator('body').innerText({ timeout: 2000 }).catch(() => '')) ?? '').toLowerCase();
}

/** The digits an admin handed over, or null. The file is deleted either way. */
function takeCode() {
    try {
        const code = fs.readFileSync(codeFile, 'utf8').trim();

        fs.unlinkSync(codeFile);

        return /^\d{4,8}$/.test(code) ? code : null;
    } catch {
        return null;
    }
}

function dropCodeFile() {
    try {
        fs.unlinkSync(codeFile);
    } catch {
        // None there.
    }
}

/** Presses the first visible button or link whose text matches, true when one was pressed. */
async function press(page, pattern) {
    const target = page.locator('button, [role="button"], a, label').filter({ hasText: pattern }).first();

    try {
        await target.click({ timeout: 3000 });

        return true;
    } catch {
        return false;
    }
}

/**
 * Instagram wants a human. Works out what the challenge page asks for, tells
 * the app (event line) and waits up to INSTAGRAM_CODE_WAIT for a code in the
 * code file (typed in, then the page is judged again) or for the page to move
 * on by itself (approval in the Instagram app). Returns a final outcome.
 */
async function waitForHuman(page, context) {
    // One absolute budget: the login deadline plus the code wait, so the whole run stays inside the
    // PHP process timeout (browser timeout plus code wait). A code is only taken while VERDICT_RESERVE
    // is left, which covers typing it (10 s) and Instagram's verdict (15 s).
    const waitDeadline = Math.min(Date.now() + codeWaitMs, deadline + codeWaitMs);
    dropCodeFile();

    let kind = null;
    let method = challengeMethod;

    // A challenge may first offer a choice of where to send the code: pick ours and send.
    if ((await page.locator(CODE_INPUT).count()) === 0) {
        const text = await pageText(page);

        if (/was this you|approve|check your .*(app|device)|we sent a notification|waiting for approval/.test(text) && (await page.locator('input').count()) === 0) {
            kind = 'approval';
        } else {
            await press(page, challengeMethod === 'email' ? /email/i : /text message|sms|phone/i);
            await press(page, /^\s*(send (security )?code|send|continue|next)\s*$/i);
            await page.waitForSelector(CODE_INPUT, { timeout: 8000 }).catch(() => {});
        }
    }

    if (kind === null) {
        if ((await page.locator(CODE_INPUT).count()) === 0) {
            return { status: 'checkpoint_required', message: `Instagram asked for a challenge this script cannot work with on ${safeUrl(page)}.` };
        }

        const text = await pageText(page);

        method = /authentication app|authenticator/.test(text) ? 'app' : /email/.test(text) && !/text message|sms/.test(text) ? 'email' : /text message|sms|phone/.test(text) ? 'sms' : challengeMethod;
        kind = 'code';
    }

    emit(kind === 'approval' ? { event: 'approval_required' } : { event: 'code_required', method });

    while (Date.now() < waitDeadline) {
        await sleep(1000);

        if (kind === 'code') {
            if (waitDeadline - Date.now() < VERDICT_RESERVE) {
                break;
            }

            const code = takeCode();

            if (code === null) {
                continue;
            }

            await page.fill(CODE_INPUT, code, { timeout: 10000 });
            await page.keyboard.press('Enter');
            await sleep(500);

            // Wait for Instagram's verdict: a session, or the code input still there with an error.
            const verdictDeadline = Date.now() + 15000;

            while (Date.now() < verdictDeadline) {
                await sleep(500);

                if (hasLiveSession(await context.cookies()) && !/\/challenge\b|two_factor/.test(page.url())) {
                    return { status: 'ok' };
                }
            }

            return { status: 'invalid_code', message: `Instagram did not accept the code on ${safeUrl(page)}.` };
        }

        if (hasLiveSession(await context.cookies()) && !/\/challenge\b/.test(page.url())) {
            return { status: 'ok' };
        }
    }

    return { status: 'timeout', message: `Nobody ${kind === 'code' ? 'sent a code' : 'approved the login'} in time.` };
}

if (username === '' || password === '' || profileDir === '') {
    await finish({ status: 'unknown', message: 'The login script got no credentials or profile directory.' }, 2);
}

let playwright;

try {
    playwright = createRequire(path.join(browserDir, 'package.json'))('playwright');
} catch (error) {
    await finish({ status: 'unknown', message: `Playwright is not installed in ${browserDir}: ${error.message}` }, 3);
}

for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, async () => {
        await context?.close().catch(() => {});
        process.exit(143);
    });
}

try {
    context = await playwright.chromium.launchPersistentContext(profileDir, {
        headless: true,
        userAgent: USER_AGENT,
        locale: 'en-US',
        viewport: { width: 1280, height: 800 },
    });

    if (forceFresh) {
        // The caller found the profile's session dead: keep the device cookies (mid, ig_did, ...)
        // but drop the session ones, or the reuse path below would hand the dead session back.
        const keep = (await context.cookies()).filter((c) => !SESSION_COOKIES.has(c.name));

        await context.clearCookies();

        if (keep.length > 0) {
            await context.addCookies(keep);
        }
    }

    const page = context.pages()[0] ?? (await context.newPage());
    page.setDefaultTimeout(Math.min(15000, timeLeft()));

    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: timeLeft() });
    await dismissCookieConsent(page);

    // A live sessionid in the profile moves Instagram off the login page: reuse it.
    await Promise.race([
        page.waitForSelector('input[name="username"]', { timeout: Math.min(10000, timeLeft()) }),
        page.waitForURL((url) => !/\/accounts\/login\b/.test(url.pathname), { timeout: Math.min(10000, timeLeft()) }),
    ]).catch(() => {});

    const reused = await classify(page, context);

    if (reused?.status === 'ok') {
        await finish({ status: 'ok', cookies: instagramCookies(await context.cookies()) });
    }

    if ((await page.locator('input[name="username"]').count()) === 0) {
        let outcome = reused ?? { status: 'unknown', message: `No login form on ${safeUrl(page)}.` };

        // The profile may already sit on a challenge page: wait for the human there as well.
        if (codeWaitMs > 0 && (outcome.status === 'checkpoint_required' || outcome.status === 'two_factor_required')) {
            outcome = await waitForHuman(page, context);
        }

        await finish(outcome.status === 'ok' ? { status: 'ok', cookies: instagramCookies(await context.cookies()) } : outcome);
    }

    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');

    let outcome = null;

    while (Date.now() < deadline) {
        await sleep(500);
        outcome = await classify(page, context);

        if (outcome !== null) {
            break;
        }
    }

    if (codeWaitMs > 0 && (outcome?.status === 'checkpoint_required' || outcome?.status === 'two_factor_required')) {
        outcome = await waitForHuman(page, context);
    }

    if (outcome?.status === 'ok') {
        await finish({ status: 'ok', cookies: instagramCookies(await context.cookies()) });
    }

    if (outcome === null) {
        await page.screenshot({ path: path.join(profileDir, 'last-failure.png') }).catch(() => {});
        outcome = { status: 'unknown', message: `Timed out waiting for the login result on ${safeUrl(page)}.` };
    }

    await finish(outcome);
} catch (error) {
    await finish({ status: 'unknown', message: `Browser login failed: ${String(error.message).split('\n')[0]}` }, 1);
}
