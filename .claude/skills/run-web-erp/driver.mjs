/**
 * Mini ERP — Playwright driver
 * Run from web_erp/: node .claude/skills/run-web-erp/driver.mjs [action]
 * Actions: smoke (default), screenshot <url-path>
 *
 * Requires: playwright-core in web_erp/node_modules (npm install --save-dev playwright-core)
 * Requires: Google Chrome installed at default Windows path
 * Requires: php artisan serve running on localhost:8000
 */
import pkg from '../../../node_modules/playwright-core/index.js';
const { chromium } = pkg;
import { mkdirSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CHROME    = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE      = 'http://localhost:8000';
const OUTDIR    = path.join(__dirname, 'screenshots');
const ACTION    = process.argv[2] ?? 'smoke';
const ARG       = process.argv[3];

mkdirSync(OUTDIR, { recursive: true });

const browser = await chromium.launch({
  executablePath: CHROME,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
  headless: true,
});

const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

async function login() {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]',    'admin@minierp.local');
  await page.fill('input[type="password"]', 'Admin@123');
  await Promise.all([
    page.waitForURL(`${BASE}/**`, { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForLoadState('networkidle');
}

async function ss(name) {
  const file = path.join(OUTDIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log('screenshot:', file);
  return file;
}

if (ACTION === 'smoke') {
  await login();
  console.log('logged in:', page.url());
  await ss('01-dashboard');

  await page.goto(`${BASE}/accounting/bank-accounts`, { waitUntil: 'networkidle' });
  await ss('02-bank-accounts');

  await page.goto(`${BASE}/accounting/internal-bank-accounts`, { waitUntil: 'networkidle' });
  await ss('03-internal-bank-accounts');

  await page.goto(`${BASE}/accounting/internal-transfers`, { waitUntil: 'networkidle' });
  await ss('04-internal-transfers');

  console.log('smoke done — screenshots in', OUTDIR);
}

if (ACTION === 'screenshot') {
  const urlPath = ARG ?? '/';
  await login();
  await page.goto(`${BASE}${urlPath}`, { waitUntil: 'networkidle' });
  const slug = urlPath.replace(/\//g, '-').replace(/^-/, '') || 'home';
  await ss(slug);
}

const errors = [];
page.on('pageerror', e => errors.push(e.message));
if (errors.length) console.warn('JS errors:', errors);

await browser.close();
