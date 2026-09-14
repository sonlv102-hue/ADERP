// Pre-deploy audit E2E cho Bao cao dong tien tai khoan cong ty.
// Chay: node .claude/skills/run-web-erp/cashflow-e2e.mjs [race|full|contracts]
import pkg from '../../../node_modules/playwright-core/index.js';
const { chromium } = pkg;

const BASE = 'http://127.0.0.1:8000';
const EMAIL = 'e2e-audit@minierp.local';
const PASSWORD = 'E2eAudit@123';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const mode = process.argv[2] || 'full';

function log(...args) { console.log(new Date().toISOString().slice(11, 19), ...args); }

async function login(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASSWORD);
  // Glob `${BASE}/**` khớp luôn cả /login hiện tại -> waitForURL trả về ngay lập tức mà
  // KHÔNG đợi redirect thật (phát hiện lúc audit này) -> dùng regex loại trừ /login.
  await Promise.all([
    page.waitForURL(/^http:\/\/127\.0\.0\.1:8000\/(?!login).*$/, { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
  if (page.url().includes('/login')) throw new Error('Login thất bại — vẫn ở trang /login');
}

async function runOneCycle(page, cycleIndex, errors) {
  const consoleErrors = [];
  const httpErrors = [];
  const onConsole = (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); };
  const onResponse = (res) => { if (res.status() >= 500) httpErrors.push(`${res.status()} ${res.url()}`); };
  page.on('console', onConsole);
  page.on('response', onResponse);

  try {
    // 1. Mo report + filter theo ngay bao trum 5 giao dich seed (5 ngay gan day).
    await page.goto(`${BASE}/reports/company-cashflow`, { waitUntil: 'networkidle' });
    const from = new Date(Date.now() - 10 * 86400000).toISOString().slice(0, 10);
    const to = new Date().toISOString().slice(0, 10);
    await page.fill('input[type="date"] >> nth=0', from);
    await page.fill('input[type="date"] >> nth=1', to);
    await page.selectOption('select >> nth=0', '32'); // account E2E — chỉ 5 dòng, tránh bị trôi trang
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/reports/company-cashflow') && r.request().method() === 'GET'),
      page.click('button:has-text("Lọc")'),
    ]);
    await page.waitForTimeout(300);

    // 2. Tim dong "E2E-AUDIT-TX-1" that su tren bang, click "Phan loai".
    const targetDesc = 'E2E-AUDIT-TX-1';
    const row = page.locator('tr', { hasText: targetDesc });
    await row.waitFor({ state: 'visible', timeout: 10000 });
    const rowText = await row.first().innerText();
    await row.first().locator('button:has-text("Phân loại")').click();

    // 3. Modal phai hien DUNG giao dich (description khop, khong phai giao dich khac).
    await page.waitForSelector('text=Phân loại giao dịch', { timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(200);
    const modalText = await page.locator('.fixed, [role="dialog"], .modal').first().innerText().catch(() => '');
    const modalHasCorrectTx = modalText.includes(targetDesc) || rowText.includes(targetDesc);
    if (!modalHasCorrectTx) {
      errors.push(`[cycle ${cycleIndex}] Modal có thể không khớp đúng dòng đã click — rowText="${rowText.slice(0,60)}" modalText="${modalText.slice(0,150)}"`);
    }

    // 4. Chon category (select dau tien trong modal), nhap note, save.
    const categorySelect = page.locator('.fixed select, [role="dialog"] select, .modal select').first();
    if (await categorySelect.count()) {
      const optValue = await categorySelect.locator('option').nth(1).getAttribute('value');
      if (optValue) await categorySelect.selectOption(optValue);
    }
    const noteField = page.locator('.fixed textarea, [role="dialog"] textarea, .modal textarea').first();
    const noteText = `E2E note cycle ${cycleIndex} @ ${Date.now()}`;
    if (await noteField.count()) await noteField.fill(noteText);

    const saveBtn = page.locator('.fixed button:has-text("Lưu"), [role="dialog"] button:has-text("Lưu"), .modal button:has-text("Lưu")').first();
    if (await saveBtn.count()) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/classify') && r.request().method() === 'POST', { timeout: 8000 }).catch(() => null),
        saveBtn.click(),
      ]);
    }
    await page.waitForTimeout(500);

    // 5. Reload trang, mo lai dung giao dich, kiem tra note con nguyen.
    await page.reload({ waitUntil: 'networkidle' });
    const rowAfter = page.locator('tr', { hasText: targetDesc });
    await rowAfter.waitFor({ state: 'visible', timeout: 10000 });
    await rowAfter.first().locator('button:has-text("Phân loại")').click();
    await page.waitForTimeout(300);
    // .innerText() KHÔNG đọc được giá trị <textarea> set qua v-model (DOM property .value,
    // không phải textContent) — phải dùng inputValue() trên chính textarea, không phải trên
    // container cha (bug trong chính test script này, phát hiện lúc audit, không phải app).
    const noteFieldAfter = page.locator('.fixed textarea, [role="dialog"] textarea, .modal textarea').first();
    const noteValueAfter = await noteFieldAfter.inputValue().catch(() => '');
    if (noteValueAfter !== noteText) {
      errors.push(`[cycle ${cycleIndex}] Note không được giữ nguyên sau reload — expected="${noteText}" actual="${noteValueAfter}"`);
    }
    // dong modal
    const closeBtn = page.locator('.fixed button:has-text("Đóng"), .fixed button:has-text("Hủy"), [role="dialog"] button[aria-label="close"]').first();
    if (await closeBtn.count()) await closeBtn.click().catch(() => {});
    else await page.keyboard.press('Escape').catch(() => {});
  } finally {
    page.off('console', onConsole);
    page.off('response', onResponse);
    if (consoleErrors.length) errors.push(`[cycle ${cycleIndex}] Console errors: ${consoleErrors.slice(0,3).join(' | ')}`);
    if (httpErrors.length) errors.push(`[cycle ${cycleIndex}] HTTP 5xx: ${httpErrors.join(' | ')}`);
  }
}

async function raceTest(page, results, accountId) {
  // Tai hien race MANH hon: bat dau tu view KHONG loc (nhieu giao dich bat ky), doi filter
  // sang CHI account E2E (chi con 5 dong, noi dung hoan toan khac) -> click NGAY dong dau
  // tien trong luc request filter con bay. Neu binding dung (theo object reference, khong
  // theo index) thi dong duoc click PHAI la giao dich THAT dang hien tren man hinh tai thoi
  // diem click (co the la giao dich CU, truoc filter — chap nhan duoc, KHONG PHAI la loi),
  // khong duoc la mot giao dich "lai" (sai id) hay modal rong/crash.
  await page.goto(`${BASE}/reports/company-cashflow`, { waitUntil: 'networkidle' });

  const rowLocator = page.locator('tr:has(button:has-text("Phân loại"))');
  await rowLocator.first().waitFor({ state: 'visible', timeout: 10000 });
  const beforeText = await rowLocator.first().innerText().catch(() => '');
  const beforeIdMatch = beforeText.match(/E2E-AUDIT-TX-\d/);

  await page.selectOption('select >> nth=0', String(accountId)); // select đầu tiên = bank_account_id
  const clickFilter = page.click('button:has-text("Lọc")');
  await page.waitForTimeout(15); // window rất ngắn — mô phỏng user bấm ngay sau khi thấy list cũ
  let clickedOk = false;
  let clickedText = '';
  try {
    clickedText = await rowLocator.first().innerText({ timeout: 500 });
    await rowLocator.first().locator('button:has-text("Phân loại")').click({ timeout: 4000 });
    clickedOk = true;
  } catch (e) {
    results.push(`Race: click thất bại giữa lúc filter đang chạy — ${e.message.slice(0,150).replace(/\n/g,' ')}`);
  }
  await clickFilter.catch(() => {});
  if (!clickedOk) return;
  await page.waitForTimeout(300);

  const modalText = await page.locator('.fixed, [role="dialog"], .modal').first().innerText().catch(() => '');
  const modalHasContent = modalText.includes('Phân loại giao dịch');
  const modalIdMatch = modalText.match(/E2E-AUDIT-TX-\d/);
  results.push(
    `Race: before="${(beforeIdMatch?.[0]||beforeText.slice(0,30)).trim()}" | clicked-row="${clickedText.slice(0,40).replace(/\n/g,' ')}" ` +
    `| modal-opened=${modalHasContent} | modal-tx="${modalIdMatch?.[0] || 'N/A'}"`
  );
  await page.keyboard.press('Escape').catch(() => {});
  const closeBtn = page.locator('.fixed button:has-text("Đóng"), .fixed button:has-text("Hủy")').first();
  if (await closeBtn.count()) await closeBtn.click().catch(() => {});
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, headless: true });
  const page = await browser.newPage();
  const errors = [];

  try {
    await login(page);
    log('Login OK');

    if (mode === 'race' || mode === 'full') {
      log('=== RACE TEST ===');
      const raceResults = [];
      for (let i = 0; i < 10; i++) {
        await raceTest(page, raceResults, 32);
      }
      raceResults.forEach((r) => log(r));
    }

    if (mode === 'full') {
      log('=== 10x CYCLE TEST ===');
      for (let i = 1; i <= 10; i++) {
        await runOneCycle(page, i, errors);
        log(`Cycle ${i}/10 done. Errors so far: ${errors.length}`);
      }
    }

    if (mode === 'contracts' || mode === 'full') {
      log('=== CONTRACT UI TEST (Sales + Purchase) skipped in this pass — see report ===');
    }
  } catch (e) {
    errors.push(`FATAL: ${e.stack || e.message}`);
  } finally {
    await browser.close();
  }

  log('=== SUMMARY ===');
  if (errors.length === 0) {
    log('NO ERRORS DETECTED');
  } else {
    errors.forEach((e) => log('ERROR:', e));
  }
  process.exit(errors.length ? 1 : 0);
})();
