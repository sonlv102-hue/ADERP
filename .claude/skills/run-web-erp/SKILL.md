---
name: run-web-erp
description: Run, screenshot, and drive the Mini ERP web app. Use when asked to start the app, take a screenshot, verify a feature works, test a page, or confirm a UI change. Covers: run, start, screenshot, verify, test, open browser.
---

Mini ERP là Laravel 12 + Vue 3 + Inertia.js, chạy trên `localhost:8000`. Driver là `driver.mjs` dùng Playwright + system Chrome (Windows). Tất cả lệnh chạy từ `web_erp/`.

## Prerequisites

- Google Chrome cài tại `C:/Program Files/Google/Chrome/Application/chrome.exe`
- `playwright-core` đã cài trong `node_modules` (đã có sẵn trong package.json)

```bash
# Nếu chưa có:
npm install --save-dev playwright-core
```

## Build

```bash
npm run build    # production assets
# hoặc
npm run dev      # Vite HMR (background)
```

## Start server

```bash
php artisan serve --host=0.0.0.0
# App chạy tại http://localhost:8000
# Ctrl-C để dừng
```

## Run (agent path) — driver

Driver nhận action qua CLI args. Chạy từ `web_erp/`:

```bash
# Smoke test — login + 4 trang chính, screenshot vào .claude/skills/run-web-erp/screenshots/
node .claude/skills/run-web-erp/driver.mjs smoke

# Screenshot một URL cụ thể
# Dùng MSYS_NO_PATHCONV=1 để Git Bash không convert /path thành Windows path
MSYS_NO_PATHCONV=1 node .claude/skills/run-web-erp/driver.mjs screenshot /accounting/internal-transfers
MSYS_NO_PATHCONV=1 node .claude/skills/run-web-erp/driver.mjs screenshot /accounting/bank-accounts
```

Screenshots lưu tại `.claude/skills/run-web-erp/screenshots/`. Đọc file PNG bằng Read tool để xem kết quả.

### Login

Driver dùng account admin mặc định:
- Email: `admin@minierp.local`
- Password: `Admin@123`

Form login dùng `input[type="email"]` và `input[type="password"]` (không có `name` attribute — Vue `v-model`).

Sau khi click submit, Inertia.js xử lý bằng fetch (không reload trang truyền thống). Phải dùng `waitForURL` glob pattern, không dùng `waitForNavigation` hay `waitForTimeout`:

```js
await Promise.all([
  page.waitForURL(`${BASE}/**`, { timeout: 15000 }),
  page.click('button[type="submit"]'),
]);
```

## Gotchas

- **`waitForURL` không nhận callback trên playwright-core** — chỉ nhận string/regex. Dùng glob `http://localhost:8000/**` thay vì `url => !url.includes('/login')`.
- **playwright-core là CJS module** — import phải dùng default: `import pkg from '...; const { chromium } = pkg;`, không dùng named import trực tiếp.
- **Relative path từ skill dir**: import playwright-core từ `driver.mjs` cần `../../../node_modules/playwright-core/index.js` (3 cấp lên để đến `web_erp/`).
- **Session driver = database** — DB PostgreSQL phải đang chạy để session hoạt động.
- **Vite dev server không cần thiết** cho smoke test — app serve static assets từ `public/build/` khi đã `npm run build`.

## Troubleshooting

| Triệu chứng | Fix |
|---|---|
| Login redirect về `/login` sau khi submit | `waitForTimeout` quá ngắn; dùng `waitForURL` glob pattern |
| `Cannot find module playwright-core` | Chạy `npm install --save-dev playwright-core` từ `web_erp/` |
| `Named export 'chromium' not found` | Dùng `import pkg from '...'; const { chromium } = pkg;` |
| Trang trả 302 về login sau khi nav | PHP session chưa start — kiểm tra DB kết nối và `php artisan serve` đang chạy |
| `waitForURL` TypeError: url.includes is not a function | Đang dùng callback; đổi sang string glob |
| URL navigate thành `localhost:8000C:/Program Files/Git/...` | Git Bash convert `/path`; thêm `MSYS_NO_PATHCONV=1` trước lệnh |
