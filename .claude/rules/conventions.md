# Mini ERP — Code Conventions

## Architecture Rules
- **Inertia monolith** — no separate API, use Laravel session auth
- **Permission check 2 layers:** route middleware `can:x.action` (coarse) + `$this->authorize()` in controllers (fine-grained)
- **Base Controller** at `app/Http/Controllers/Controller.php` uses `AuthorizesRequests` trait
- **Soft delete** only for master data: users, customers, suppliers, products, services — NOT for transactions
- **Status fields** use PHP 8.1 Backed Enum in `app/Enums/`, cast in model via `casts()` method (not `$casts` array)

## DB Rules
- Stock quantity = `SUM(stock_movements.quantity)` — never stored directly on products
- Serial lifecycle uses FSM in `ProductSerial::transition()`
- Snapshot product/service name into `order_items.name`, `quotation_items.name`
- `cost_price` = price **including VAT** (total paid to supplier)
- `total_cost` = `cost_price + business_cost` (no extra VAT)

## Auto-Generated Codes (generateCode pattern)
| Prefix | Model | Example |
|---|---|---|
| KH- | Customer | KH-0001 |
| NCC- | Supplier | NCC-0001 |
| SP- | Product | SP-0001 |
| DV- | Service | DV-0001 |
| NK- | StockEntry | NK-0001 |
| XK- | StockExit | XK-0001 |
| CK- | StockTransfer | CK-0001 |
| BG- | Quotation | BG-0001 |
| DH- | Order | DH-0001 |
| HD- | Contract | HD-0001 |
| MH- | PurchaseOrder | MH-0001 |
| DA- | Project | DA-0001 |
| TK- | Ticket | TK-0001 |
| HĐ- | Invoice | HĐ-0001 |
| LD- | Lead | LD-0001 |
| TH- | SalesReturn | TH-0001 |
| THM- | PurchaseReturn | THM-0001 |

## Naming Conventions
- Controllers: `app/Http/Controllers/{Module}/XxxController.php`
- Route prefix: `{module}.` (e.g. `crm.customers.index`)
- Migration timestamp: `2026_05_21_{phase_num}{seq}` (phase 9: 900001–900021, next: 900022)
- PDF blade: `resources/views/pdf/{doc_type}.blade.php`
- Vue Pages mirror routes: `Pages/Sales/Quotations/Index.vue` → `sales.quotations.index`
