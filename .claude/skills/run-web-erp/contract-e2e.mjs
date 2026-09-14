// E2E cho luong chon Sales Contract / Purchase Contract trong ClassifyModal (spec audit §9).
import pkg from '../../../node_modules/playwright-core/index.js';
const { chromium } = pkg;

const BASE = 'http://127.0.0.1:8000';
const EMAIL = 'e2e-audit@minierp.local';
const PASSWORD = 'E2eAudit@123';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function log(...args) { console.log(new Date().toISOString().slice(11, 19), ...args); }

async function login(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASSWORD);
  await Promise.all([
    page.waitForURL(/^http:\/\/127\.0\.0\.1:8000\/(?!login).*$/, { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
  if (page.url().includes('/login')) throw new Error('Login thất bại');
}

async function testContractFlow(page, { txDesc, contractTypeLabel, contractCode, errors }) {
  await page.goto(`${BASE}/reports/company-cashflow?bank_account_id=32&from=2026-09-04&to=2026-09-14`, { waitUntil: 'networkidle' });
  const row = page.locator('tr', { hasText: txDesc });
  await row.first().waitFor({ state: 'visible', timeout: 10000 });
  await row.first().locator('button:has-text("Phân loại")').click();
  await page.waitForTimeout(400);

  // Chon "Loai hop dong" = Hop dong ban / Hop dong mua
  const contractTypeSelect = page.locator('.fixed select, [role="dialog"] select, .modal select').filter({ hasText: 'Không liên kết hợp đồng' });
  await contractTypeSelect.selectOption({ label: contractTypeLabel });
  await page.waitForTimeout(300);

  // RemoteSearchSelect cho contract — placeholder đổi động khi focus (isOpen=true ->
  // "Nhập để tìm kiếm...") nên KHÔNG dùng placeholder làm locator (race với chính hành
  // động click/fill). Thứ tự field cố định trong modal: [0]=dự án, [1]=đối tượng,
  // [2]=hợp đồng, [3]=người phụ trách — dùng index ổn định thay vì placeholder.
  const searchInput = page.locator('input[type="text"]').nth(2);
  await searchInput.click();
  await searchInput.fill(contractCode);
  await page.waitForTimeout(1200); // debounce cua RemoteSearchSelect

  // Dropdown kết quả dùng <Teleport to="body"> -> nằm NGOÀI modal container, options là
  // <button> (không phải <li>) — phải query toàn trang, không scope theo .fixed/.modal.
  const option = page.locator('button', { hasText: contractCode });
  const optCount = await option.count();
  if (optCount === 0) {
    errors.push(`[${contractTypeLabel}] Không tìm thấy gợi ý contract "${contractCode}" trong dropdown search`);
    return false;
  }
  await option.first().click();
  await page.waitForTimeout(200);

  const saveBtn = page.locator('.fixed button:has-text("Lưu"), [role="dialog"] button:has-text("Lưu"), .modal button:has-text("Lưu")').first();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/classify') && r.request().method() === 'POST', { timeout: 8000 }).catch(() => null),
    saveBtn.click(),
  ]);
  await page.waitForTimeout(500);

  // Reload, mo lai, kiem tra label + contract_id con nguyen
  await page.reload({ waitUntil: 'networkidle' });
  const rowAfter = page.locator('tr', { hasText: txDesc });
  await rowAfter.first().waitFor({ state: 'visible', timeout: 10000 });
  const rowText = await rowAfter.first().innerText();
  await rowAfter.first().locator('button:has-text("Phân loại")').click();
  await page.waitForTimeout(400);
  // RemoteSearchSelect hiện giá trị ĐÃ CHỌN qua thuộc tính placeholder (selectedLabel),
  // KHÔNG qua input value/textContent -> .innerText() không đọc được (cùng loại lỗi
  // test-script như phát hiện ở note textarea, không phải bug app — đã tự xác minh dữ liệu
  // persist đúng qua DB + transactionDto() trực tiếp trước khi viết lại check này).
  const contractDisplay = await page.locator('input[type="text"]').nth(2).getAttribute('placeholder').catch(() => '');
  const labelPersisted = (contractDisplay || '').includes(contractCode);
  if (!labelPersisted) {
    errors.push(`[${contractTypeLabel}] Sau reload, contract "${contractCode}" không còn hiện trong modal — placeholder="${contractDisplay}"`);
  } else {
    log(`[${contractTypeLabel}] OK — contract "${contractCode}" giữ nguyên sau reload+reopen`);
  }

  // Doi note KHONG mat contract (kiem tra rieng, spec audit §9)
  const noteField = page.locator('.fixed textarea, [role="dialog"] textarea, .modal textarea').first();
  await noteField.fill(`note update ${Date.now()}`);
  const saveBtn2 = page.locator('.fixed button:has-text("Lưu"), [role="dialog"] button:has-text("Lưu"), .modal button:has-text("Lưu")').first();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/classify') && r.request().method() === 'POST', { timeout: 8000 }).catch(() => null),
    saveBtn2.click(),
  ]);
  await page.waitForTimeout(500);
  await page.reload({ waitUntil: 'networkidle' });
  const rowAfter2 = page.locator('tr', { hasText: txDesc });
  await rowAfter2.first().waitFor({ state: 'visible', timeout: 10000 });
  await rowAfter2.first().locator('button:has-text("Phân loại")').click();
  await page.waitForTimeout(400);
  const contractDisplay2 = await page.locator('input[type="text"]').nth(2).getAttribute('placeholder').catch(() => '');
  if (!(contractDisplay2 || '').includes(contractCode)) {
    errors.push(`[${contractTypeLabel}] Sau khi sửa NOTE, contract "${contractCode}" bị mất — placeholder="${contractDisplay2}"`);
  } else {
    log(`[${contractTypeLabel}] OK — sửa note không làm mất contract`);
  }

  await page.keyboard.press('Escape').catch(() => {});
  return true;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, headless: true });
  const page = await browser.newPage();
  const errors = [];
  try {
    await login(page);
    log('Login OK');

    log('=== SALES CONTRACT FLOW ===');
    await testContractFlow(page, { txDesc: 'E2E-AUDIT-TX-4', contractTypeLabel: 'Hợp đồng bán', contractCode: 'HD-E2E-AUDIT', errors });

    log('=== PURCHASE CONTRACT FLOW ===');
    await testContractFlow(page, { txDesc: 'E2E-AUDIT-TX-5', contractTypeLabel: 'Hợp đồng mua', contractCode: 'HD-MH-E2E-AUDIT', errors });
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
