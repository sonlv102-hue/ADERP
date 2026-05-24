# Mini ERP — Index

Hệ thống quản lý nội bộ cho công ty kinh doanh và thi công giải pháp IT.  
Tài liệu: `C:\Mini_erp\Plan.docx` | `C:\Mini_erp\P2.docx`

## Rules (`.claude/rules/`)
| File | Nội dung | Load khi |
|---|---|---|
| `project-overview.md` | Tech stack, cấu trúc thư mục, lệnh chạy, tài khoản demo | Luôn |
| `conventions.md` | Quy ước kiến trúc, naming, DB rules, mã tự động | Luôn |
| `phase-history.md` | Module map, services, FSM, migration prefix | Khi edit PHP/migration |
| `rbac.md` | 7 roles, 36 permissions, cách thêm permission mới | Khi edit seeder/routes/admin |
| `database-schema.md` | 41 bảng — schema đầy đủ nhóm theo module | Khi edit migration/model |
| `php-patterns.md` | Controller, Model, Service, Enum, Migration patterns | Khi edit PHP |
| `vue-patterns.md` | Index/Form/Show page, composables, StatusBadge patterns | Khi edit Vue/JS |

## Skills (`.claude/skills/`)
| File | Nội dung |
|---|---|
| `formulas.md` | Công thức sẵn dùng: tạo module mới, generateCode, FSM, PDF, permission, filter |

## Agents (`.claude/agents/`)
| Agent | Mục đích |
|---|---|
| `researcher` | So sánh lựa chọn kỹ thuật — luôn kết thúc bằng Recommendation |

## Trạng thái hiện tại
- **G1–G7 + Phase 8A–8D + Phase 9:** HOÀN THÀNH (50+ bảng, Docker/Deploy VPS ✅)
- **Purchasing:** Đơn mua hàng (MH-), Hóa đơn đầu vào, Hợp đồng mua ✅
- **Serial tracking:** Nhập kho + Xuất kho đều theo dõi serial number ✅; hủy phiếu đã confirmed → reversal movement + serial → `cancelled`; xóa phiếu nhập → PO liên kết tự sync trạng thái ✅
- **Stock entry bắt buộc qua PO:** tạo phiếu nhập chỉ được từ PO (không tự do); số lượng capped theo PO; hỗ trợ giao từng phần → PO status `partial_received` ✅
- **Products/Show:** bỏ serial list, chỉ hiển thị thông tin sản phẩm + tồn kho tổng ✅
- **Delivery tracking:** `stock_exits.order_id` liên kết XK với đơn hàng; `order_items.delivered_quantity` track tiến độ giao; OrderStatus thêm `partial_delivered`; OrderService.syncDelivery() gọi sau confirm XK; Dashboard widget cảnh báo đơn thiếu hàng ✅
- **Phase 9 — Leads/CRM Pipeline:** Lead model (LD-XXXX), LeadService FSM, 3 Vue pages, convert-to-customer ✅
- **Phase 9 — Stock Transfer (Chuyển kho):** StockTransfer/Item (CK-XXXX), serial tracking across warehouses ✅
- **Phase 9 — Sales Return (Trả hàng bán):** SalesReturn/Item (TH-XXXX), serial reversal (Sold→InStock) ✅
- **Phase 9 — Purchase Return (Trả hàng mua):** PurchaseReturn/Item (THM-XXXX), SerialStatus::ReturnedToSupplier ✅
- **Phase 9 — Notifications:** Database notifications, LowStock/TicketCreated/InvoiceOverdue, NotificationDropdown, 30s polling ✅
- **Phase 9 — Price Lists (Bảng giá):** PriceList/Item (BG-XXXX), auto-fill pricing in Order/Quotation forms ✅
- **Phase 9 — Bulk Import Excel:** ProductImport, CustomerImport, SupplierImport via maatwebsite/excel, template download ✅
- **Phase 9 — Audit Log UI:** ActivityLogController, filters by user/type/date ✅
- **In-app tab bar:** `useTabs.js` composable (localStorage, max 8 tabs) + `TabBar.vue` — tự động track navigation, click × đóng tab ✅
- **AR fix:** `InvoiceController.allowedActions()` bỏ `mark_paid` — invoice chỉ auto-Paid khi payment >= total qua `InvoiceService.addPayment()` ✅
- **Invoice form auto-fill:** Chọn Order → tự tìm Contract liên kết → fill subtotal/total từ `contract.value`; `step="1"` trên inputs ✅
- **Migration tiếp theo:** `2026_05_23_900022`

## Quy tắc quan trọng
- `cost_price` trên sản phẩm = giá **đã gồm VAT** (tổng trả NCC)
- `total_cost` = `cost_price + business_cost` (không cộng thêm VAT)
- `unit_price` trong đơn mua = giá đã gồm VAT → khi tạo hóa đơn dùng back-calculate để tách subtotal/tax
- Tồn kho = `SUM(stock_movements.quantity)` — không lưu trực tiếp
- Soft delete chỉ cho master data (products, customers, suppliers, users, services)
