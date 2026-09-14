// Browser acceptance cho Xuất Excel Dong tien tai khoan cong ty (Phase 1.1, spec §38).
import pkg from '../../../node_modules/playwright-core/index.js';
import fs from 'fs';
const { chromium } = pkg;

const BASE = 'http://127.0.0.1:8000';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function log(...args) { console.log(new Date().toISOString().slice(11, 19), ...args); }

async function login(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await Promise.all([
    page.waitForURL(/^http:\/\/127\.0\.0\.1:8000\/(?!login).*$/, { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
  if (page.url().includes('/login')) throw new Error(`Login thất bại cho ${email}`);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, headless: true });
  const errors = [];

  try {
    // ── 1-8: user CÓ quyền export ──────────────────────────────────────────
    const page = await browser.newPage();
    await login(page, 'e2e-export@minierp.local', 'E2eExport@123');
    log('Login (export user) OK');

    await page.goto(`${BASE}/reports/company-cashflow?bank_account_id=33&from=2026-09-01&to=2026-09-30`, { waitUntil: 'networkidle' });

    const exportBtn = page.locator('a:has-text("Xuất Excel")');
    const btnCount = await exportBtn.count();
    if (btnCount === 0) {
      errors.push('Không thấy nút "Xuất Excel" cho user có quyền export');
    } else {
      log('Nút "Xuất Excel" hiển thị đúng cho user có quyền');

      const href = await exportBtn.first().getAttribute('href');
      log('Export URL:', href);
      if (!href || !href.includes('bank_account_id=33') || !href.includes('from=2026-09-01')) {
        errors.push(`Export URL không mang đúng filter hiện tại trên màn hình: ${href}`);
      } else {
        log('Export URL mang đúng filter hiện tại — OK');
      }

      // Tải trực tiếp qua fetch trong context đã đăng nhập (Playwright download qua <a target=_blank>
      // không tin cậy trong headless — fetch tương đương downloading, vẫn qua đúng session cookie).
      const downloadResult = await page.evaluate(async (url) => {
        const res = await fetch(url, { credentials: 'include' });
        const blob = await res.blob();
        const buf = await blob.arrayBuffer();
        return {
          status: res.status,
          contentDisposition: res.headers.get('content-disposition'),
          contentType: res.headers.get('content-type'),
          size: buf.byteLength,
          bytes: Array.from(new Uint8Array(buf)),
        };
      }, href.startsWith('http') ? href : `${BASE}${href}`);

      if (downloadResult.status !== 200) {
        errors.push(`Download export trả status ${downloadResult.status}, expected 200`);
      } else {
        log(`Download OK — status 200, size ${downloadResult.size} bytes, content-type=${downloadResult.contentType}`);
        log('Content-Disposition:', downloadResult.contentDisposition);
        if (!downloadResult.contentDisposition?.includes('Bao_cao_dong_tien_20260901_20260930.xlsx')) {
          errors.push(`Filename sai: ${downloadResult.contentDisposition}`);
        } else {
          log('Filename đúng — Bao_cao_dong_tien_20260901_20260930.xlsx');
        }

        const outPath = 'C:/Users/V170192/AppData/Local/Temp/claude/c--Mini-erp/46becd45-7fce-4d95-862f-527710b471be/scratchpad/e2e-export.xlsx';
        fs.writeFileSync(outPath, Buffer.from(downloadResult.bytes));
        log('Đã lưu file để verify workbook:', outPath, `(${fs.statSync(outPath).size} bytes)`);
      }
    }

    // ── 9-10: user KHÔNG có quyền export ───────────────────────────────────
    const page2 = await browser.newPage();
    await login(page2, 'e2e-viewonly@minierp.local', 'E2eView@123');
    log('Login (view-only user) OK');

    await page2.goto(`${BASE}/reports/company-cashflow`, { waitUntil: 'networkidle' });
    const exportBtnForViewer = await page2.locator('a:has-text("Xuất Excel")').count();
    if (exportBtnForViewer !== 0) {
      errors.push('User KHÔNG có quyền export vẫn thấy nút "Xuất Excel"');
    } else {
      log('User view-only KHÔNG thấy nút "Xuất Excel" — OK');
    }

    const directResult = await page2.evaluate(async (url) => {
      const res = await fetch(url, { credentials: 'include' });
      return { status: res.status };
    }, `${BASE}/reports/company-cashflow/export`);
    if (directResult.status !== 403) {
      errors.push(`Direct endpoint cho user thiếu quyền trả status ${directResult.status}, expected 403`);
    } else {
      log('Direct endpoint export cho user thiếu quyền -> 403 — OK');
    }
  } catch (e) {
    errors.push(`FATAL: ${e.stack || e.message}`);
  } finally {
    await browser.close();
  }

  log('=== SUMMARY ===');
  if (errors.length === 0) log('NO ERRORS DETECTED');
  else errors.forEach((e) => log('ERROR:', e));
  process.exit(errors.length ? 1 : 0);
})();
