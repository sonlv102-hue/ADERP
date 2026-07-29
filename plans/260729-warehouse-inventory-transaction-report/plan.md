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
