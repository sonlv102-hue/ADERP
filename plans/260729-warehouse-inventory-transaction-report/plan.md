# Plan: Sổ chi tiết Nhập-Xuất-Tồn (Warehouse Detail Ledger Report)

## Overview
New lookup report under **Kho** menu showing, per product, chronological stock
movements (nhập/xuất) with opening/closing balance — standard "Sổ chi tiết vật
tư, hàng hoá" (Mẫu S10-DN). Brand new service/controller/page — does **not**
modify `InventoryReportService.php` (stays as-is, aggregate report under "Báo cáo").

## Phases
| Phase | File | Scope | Status |
|---|---|---|---|
| 1 | `phase-01-backend-service-controller.md` | Service, Controller, Export, route, permission, tests | Done |
| 2 | `phase-02-frontend-page-menu.md` | Vue Index page, sidebar menu entry, Excel export button | Done |

## Key Dependencies
- No new migration — reuses existing `stock_movements`, `stock_entries`,
  `stock_exits`, `stock_transfers`, `sales_returns`, `purchase_returns`,
  `inventory_counts` tables (all verified via migrations).
- Reuses permissions `reports.view` (route gate) + `warehouse.view` (menu gate)
  — both already granted to `warehouse` role in `RolePermissionSeeder.php`.
- Phase 2 depends on Phase 1's Controller prop shape being finalized first.

## Critical Design Decision: Row Pairing

**Problem:** The mockup image pairs 1 nhập-doc-column-set with 1 xuất-doc-
column-set on the same row. A real product will usually have an unequal
count of nhập vs xuất transactions in a period (e.g. 5 entries vs 2 exits),
so literal 1:1 pairing would either drop data or fabricate fake empty pairs.

**Decision:** Adopt the real Mẫu S10-DN/S12-DN structure instead: **1 row =
1 stock_movement**, listed chronologically per product. Each row is EITHER
a nhập row (nhập columns filled, xuất columns blank) OR a xuất row (xuất
columns filled, nhập columns blank) — never both. Add 1 opening-balance
header row and 1 closing-balance/summary row per product. The mockup's
equal-count pairing is read as a simplified/schematic screenshot, not a
literal row-shape requirement — real accounting software (MISA, FAST, Bravo)
renders S10-DN this way, and it's the only structure that reconciles
1:1 with `stock_movements` without inventing data.

**Example — product with 5 nhập + 2 xuất, opening 100 units / 10,000,000đ:**

| # | Diễn giải | SL tồn ĐK | Tiền tồn ĐK | CT nhập | Ngày | SL nhập | Tiền nhập | CT xuất | Ngày | SL xuất | Tiền xuất | SL tồn CK | Tiền tồn CK |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 0 | Tồn đầu kỳ | 100 | 10,000,000 | | | | | | | | | 100 | 10,000,000 |
| 1 | Nhập kho NK-0101 | | | NK-0101 | 02/07 | 50 | 5,000,000 | | | | | 150 | 15,000,000 |
| 2 | Nhập kho NK-0102 | | | NK-0102 | 05/07 | 30 | 3,000,000 | | | | | 180 | 18,000,000 |
| 3 | Xuất kho XK-0201 | | | | | | | XK-0201 | 06/07 | 40 | 4,000,000 | 140 | 14,000,000 |
| 4 | Nhập kho NK-0103 | | | NK-0103 | 10/07 | 20 | 2,000,000 | | | | | 160 | 16,000,000 |
| 5 | Xuất kho XK-0202 | | | | | | | XK-0202 | 15/07 | 60 | 6,000,000 | 100 | 10,000,000 |
| 6 | Nhập kho NK-0104 | | | NK-0104 | 20/07 | 15 | 1,500,000 | | | | | 115 | 11,500,000 |
| 7 | Nhập kho NK-0105 | | | NK-0105 | 25/07 | 25 | 2,500,000 | | | | | 140 | 14,000,000 |
| Σ | **Cộng phát sinh + Tồn cuối kỳ** | | | | | **140** | **14,000,000** | | | **100** | **10,000,000** | **140** | **14,000,000** |

Sanity check: 100 + 140 − 100 = 140 ✓ — matches last-row running balance.

## Not in Scope
- Signature block / printed PDF (lookup report per `reporting-standards.md` rule 7).
- Hard business-rule date-range cap (documented as a recommendation only).

## P0 revision (2026-07-29)

User feedback sau khi dùng thử: cột "tồn cuối kỳ" trên MỌI dòng (kể cả dòng
giao dịch) gây hiểu nhầm — thực chất đó là "tồn sau chứng từ", chỉ dòng
"Cộng phát sinh + Tồn cuối kỳ" mới là tồn cuối kỳ thật. Đã sửa:
- Đổi header cột → "Tồn sau CT" (kèm tooltip); dòng closing luôn nhắc lại
  tồn đầu kỳ (không để trống) + tổng nhập/xuất + tồn cuối kỳ thực.
- Thêm phát hiện âm kho: `is_negative` per-row + `status` per-product
  (normal/was_negative/negative_ending), tô đỏ dòng âm, badge cảnh báo.
- Sắp xếp giao dịch: ngày chứng từ → số chứng từ → created_at → id.
- Đổi "Tiền" → "Giá trị" toàn bộ header (UI + Excel).
- Tách `InventoryTransactionGroupBuilder` khỏi Service để giữ ≤200 dòng/file.

**Phát hiện phụ (chưa sửa, ngoài phạm vi report):** 3 mã hàng âm kho thật
(Aruba 6100 24G/48G, J9150D) có `value_out` của dòng xuất khớp CHÍNH XÁC
với `value_in` của dòng nhập kho theo sau — dấu hiệu giá xuất kho tại thời
điểm xuất trước nhập không được tính bằng AVCO thời điểm đó mà có thể đã
bị backfill/khớp theo lô nhập sau. Đây là vấn đề của `AvcoService`/
`StockService`, không phải của report — cần điều tra riêng, không tự sửa
vì rủi ro cao với engine kế toán/kho (theo CLAUDE.md).

Người dùng đã xác nhận scope: **chỉ P0** — P1 (tách 2 loại báo cáo tổng
hợp/chi tiết, thêm cột ĐVT/kho/nhóm hàng, filter trạng thái âm kho) và P2
(drill-down, đối chiếu TK 152/153) để lại cho phiên sau.

## Đóng task P0 — Xác nhận hoàn tất (2026-07-29)

**Commit triển khai:** `e2cb243` (trên `9c7b0ef` — P0 revision ở trên).

### Kết quả kiểm thử trước deploy

- `php artisan test`: **843/843 pass** (bao gồm TC12 mới).
- `npm run build`: pass.
- Đọc lại file Excel thực tế (không chỉ mảng PHP): đủ 114 dòng "Cộng phát
  sinh + Tồn cuối kỳ" trên dữ liệu local, công thức Tồn đầu + Nhập − Xuất =
  Tồn cuối khớp 100% (0 lệch).

### Lỗi phát hiện thêm khi đọc lại file Excel thật + cách khắc phục

Khi đọc trực tiếp file `.xlsx` sinh ra (thay vì chỉ kiểm mảng PHP như test
cũ), phát hiện dòng tổng hợp có tồn cuối = 0 bị **ghi thành ô TRỐNG** trên
Excel thật, dù `InventoryTransactionGroupBuilder::build()` trả về đúng
`float(0)`.

**Nguyên nhân:** `Maatwebsite\Excel` (khi dùng `FromCollection`) ghi dữ liệu
qua `PhpSpreadsheet\Worksheet::fromArray()`, mặc định so sánh
`$cellValue != $nullValue` (loose, `$nullValue` mặc định = `null`). Trong
PHP, `0.0 == null` là `true` (loose comparison), nên bất kỳ giá trị `0`/`0.0`
nào cũng bị coi như ô trống và **không được ghi vào cell**.

**Khắc phục:** Thêm interface `Maatwebsite\Excel\Concerns\WithStrictNullComparison`
vào `InventoryTransactionReportExport` — chuyển so sánh sang strict (`===`),
chỉ bỏ qua cell thật sự `null` (ví dụ cột "Số chứng từ nhập" trên dòng xuất),
giữ lại số `0` hợp lệ.

**Chống tái diễn:** Thêm `test_tc12_excel_export_closing_row_zero_not_blank`
— test này gọi `Excel::raw()` sinh file `.xlsx` thật, đọc lại bằng
`PhpOffice\PhpSpreadsheet\Reader\Xlsx`, và assert cell không phải `null`.
Test cũ (TC9) chỉ kiểm mảng PHP nên không bắt được lớp lỗi này — bài học:
với export Excel, phải test ở tầng file nhị phân thật, không chỉ tầng data.

### Deploy VPS

- `scripts/sync-vps.ps1`: push (`b8cd516..e2cb243`) → build Docker → backup
  DB (`/var/backups/mini_erp/20260729_112550_e2cb243.sql`) → migrate (nothing
  to migrate) → deploy smoke test **12/12 pass**, không có lỗi mới trong log
  (199 lỗi trước/sau, toàn bộ là lỗi cũ không liên quan).
- Smoke test trực tiếp trên dữ liệu production (qua service/export layer
  trong container, route xác nhận sống qua nginx — HTTP 302 redirect login):
  - Mặc định: 128 sản phẩm có phát sinh/tồn đầu kỳ.
  - Lọc "Aruba": 8 sản phẩm, 6 mã đúng trạng thái "Đã từng âm kho".
  - Excel export thật trên dữ liệu production: dòng tổng hợp ghi đúng `0.0`,
    không còn trống.
- **Chưa kiểm tra UI qua trình duyệt trên VPS bằng tài khoản thật** — theo
  quyết định của người dùng, việc này để dành cho lần nghiệm thu tiếp theo
  bằng tài khoản quản trị thông thường (không tạo user debug tạm). Không
  phải điều kiện chặn đóng P0 vì UI đã kiểm tra kỹ trên local + service/export
  đã xác minh trực tiếp trên VPS.

### Vấn đề AVCO — tách task riêng

Phát hiện ở mục "P0 revision" phía trên (giá xuất kho khớp giá nhập sau khi
xuất trước nhập, các mã Aruba/J9150D) **không sửa trong task này**. Đã tách
thành task điều tra riêng: xem
`plans/260729-avco-negative-stock-cost-investigation/plan.md`.

**P0 task Sổ chi tiết Nhập-Xuất-Tồn: ĐÓNG.**
