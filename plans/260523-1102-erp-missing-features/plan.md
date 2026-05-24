---
title: "ERP Missing Features — 7 phases (Leads, Stock Transfer, Returns, Notifications, Price Lists, Bulk Import, Audit Log)"
description: "Implement 7 missing business modules to round out Mini ERP capabilities."
status: completed
priority: P2
effort: 7d
branch: feat/erp-missing-features
tags: [crm, warehouse, sales, purchasing, notifications, catalog, admin]
created: 2026-05-23
completed: 2026-05-23
---

# Mini ERP — Missing Features Plan

## Goal
Add 7 missing business features to Mini ERP. Each phase is self-contained and follows established Laravel 12 + Inertia + Vue 3 conventions (controller→service→model, casts() method, generateCode, `<StatusBadge :color>`, `can()` guards).

## Migration sequence
Next number is `2026_05_23_900009`. Increment per migration. Pairs (table + items): use two numbers.

## Phases

| # | Phase | File | Migration nums | Code prefix | Priority | Status |
|---|---|---|---|---|---|---|
| 1 | Leads / CRM Pipeline | [phase-01](./phase-01-leads-crm-pipeline.md) | 900009 | KH-* (lead) | P2 | Completed |
| 2 | Stock Transfer | [phase-02](./phase-02-stock-transfer.md) | 900010, 900011 | CK- | P2 | Completed |
| 3 | Sales Return | [phase-03](./phase-03-sales-return.md) | 900012, 900013 | TH- | P2 | Completed |
| 4 | Purchase Return | [phase-04](./phase-04-purchase-return.md) | 900014, 900015 | THM- | P2 | Completed |
| 5 | Notifications | [phase-05](./phase-05-notifications.md) | 900016 (notifications table) | — | P2 | Completed |
| 6 | Price Lists | [phase-06](./phase-06-price-lists.md) | 900017, 900018 | BG- | P2 | Completed |
| 7 | Bulk Import + Audit Log UI | [phase-07](./phase-07-bulk-import-audit-log.md) | — | — | P2 | Completed |

Note on F1: `leads` is a NEW table; the existing `LeadStatus` enum is reused for `leads.status`. Lead code prefix is `KH-` (khách hàng tiềm năng) — same prefix as customers but lives in `leads` table (separate sequence).

## Parallel execution groups

- **Group A** (parallel — no file conflicts): Phase 1 + Phase 2 + Phase 7b (Audit Log UI portion).
- **Group B** (parallel — no file conflicts): Phase 3 + Phase 4. Each touches only its own controller, model, table, Vue dir.
- **Group C** (sequential — touches `TopBar.vue` + `StockService.php`): Phase 5. Must run after Group A/B if A/B modify StockService.
- **Group D** (parallel — no file conflicts): Phase 6 + Phase 7a (Bulk Import portion). Phase 6 modifies Quotation/Order forms; Phase 7a modifies Index pages only.

Cross-cutting touchpoints:
- `Sidebar.vue` — modified by Phase 1, 2, 3, 4, 6, 7b. Order edits sequentially or batch in one commit per group.
- `RolePermissionSeeder.php` — all phases add permissions; combine into single commit at end of each group.
- `routes/web.php` — additive per phase.

## Cross-cutting deliverables (all phases)
- Add new permissions to `RolePermissionSeeder.php` (admin gets all; assign to other roles per business rule).
- Add NavGroup/NavItem in `Sidebar.vue` with `v-if="can(...)"`.
- Update `docs/development-roadmap.md` + `docs/project-changelog.md` after each phase done.

## Key dependencies
- Phase 5 reads `StockMovement` to trigger LowStockNotification → no schema dep, but must be wired into `StockService::confirmExit()`.
- Phase 6 integrates into existing `Sales/Quotations/Form.vue` + `Sales/Orders/Form.vue` (price-list selector).
- Phase 7a touches `ProductController`, `CustomerController`, `SupplierController` — additive `import()` + `importTemplate()` methods.

## Risks
- **Stock Transfer atomicity:** must wrap two `StockMovement::create` calls in single `DB::transaction` + validate source stock before deduct.
- **Sales/Purchase Return:** must validate qty ≤ originally delivered/received qty per item.
- **Notifications polling:** 30s `setInterval` — must cancel on logout (component unmount).
