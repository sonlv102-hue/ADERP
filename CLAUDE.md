# Mini ERP — Claude Code Instructions

Mini ERP là hệ thống quản lý nội bộ cho công ty kinh doanh và thi công giải pháp IT.

Tài liệu gốc/tham khảo:
- `C:\Mini_erp\Plan.docx`
- `C:\Mini_erp\P2.docx`

Mục tiêu của file này: giúp Claude Code làm việc **đúng scope, ít đoán, ít tốn token**.

---

## 1. Core behavior

- Không đoán. Nếu chưa kiểm chứng từ code, migration, test, command output hoặc tài liệu liên quan, phải ghi rõ: `Chưa kiểm chứng`.
- Trước khi sửa code, phải đọc file liên quan, tóm tắt phát hiện, rồi đưa kế hoạch ngắn.
- Ưu tiên diff nhỏ nhất. Không refactor ngoài phạm vi task.
- Không rewrite toàn bộ file nếu chỉ cần sửa một phần.
- Không tạo dependency mới nếu chưa được yêu cầu.
- Không sửa business logic kế toán/kho/serial nếu chưa xác định đủ luồng ảnh hưởng.
- Khi có rủi ro phá dữ liệu, double-posting, sai tồn kho, sai công nợ hoặc sai serial, phải dừng lại và nêu rõ rủi ro trước khi sửa.
- **UI/Frontend**: Đọc `.claude/rules/ui-style-guide.md` trước khi sửa Vue file. Không tạo style/component mới nếu đã có trong guide. Nếu cần style mới, cập nhật `ui-style-guide.md` trước.

---

## 2. Token & context discipline

- Chỉ đọc file liên quan trực tiếp đến task.
- Khi cần thêm context, nêu rõ cần file nào và vì sao.
- Tránh đọc toàn bộ repo, log dài, `vendor`, `node_modules`, build output, lock file, minified file hoặc generated file nếu không cần.
- Với file lớn, đọc theo class/function/section thay vì đọc toàn bộ.
- Không paste lại output dài; chỉ tóm tắt phần lỗi, diff hoặc kết quả quan trọng.
- Khi đổi sang task không liên quan, nhắc người dùng dùng `/clear`.
- Khi session dài, dùng `/compact` và chỉ giữ: mục tiêu hiện tại, files đã đọc/sửa, diff chính, test đã chạy, lỗi còn lại.

---

## 3. Project rules

Các rule chi tiết nằm trong `.claude/rules/`. Không import toàn bộ rule vào file này nếu không thật sự cần, vì sẽ làm tăng context ngay từ đầu phiên.

| Khi làm việc với | Rule cần đọc |
|---|---|
| Tổng quan project, tech stack, lệnh chạy | `.claude/rules/project-overview.md` |
| Quy ước kiến trúc, naming, DB rules, mã tự động | `.claude/rules/conventions.md` |
| PHP/Laravel, Controller, Model, Service, Enum | `.claude/rules/php-patterns.md` |
| Vue/JS, page, composable, component | `.claude/rules/vue-patterns.md` |
| **UI/UX — button, badge, form, table, modal, icon, layout** | **`.claude/rules/ui-style-guide.md` — bắt buộc đọc trước khi sửa bất kỳ Vue file nào** |
| **Báo cáo, PDF, Excel export, bản in — đặc biệt phần chữ ký** | **`.claude/rules/reporting-standards.md` — bắt buộc đọc trước khi tạo/sửa báo cáo có phần ký** |
| Migration, model, schema, relation | `.claude/rules/database-schema.md` |
| Seeder, routes, admin, permission | `.claude/rules/rbac.md` |
| Module map, service map, FSM, migration prefix | `.claude/rules/phase-history.md` |
| Trạng thái module đã làm/xong/gần đây | `.claude/rules/project-state.md` |
| **Chạy audit/reconcile/diagnose command (inventory, purchase-invoices, accounting...)** | **`.claude/rules/audit-verification.md` — bắt buộc đọc trước khi báo cáo bất kỳ finding nào** |

**Skills** (activate qua Skill tool):

- **`cook`**: Bắt buộc activate trước mọi feature implementation (`/cook <task> --fast` hoặc `--interactive`).
- **`fix`**: Bắt buộc activate trước mọi bug fix / test failure / lỗi runtime.
- **`code-review`** (scoped web_erp/): Chạy sau mỗi lần implement để review quality.
- `planning`: Dùng khi cần design plan chi tiết trước khi code.
- `research`: Nghiên cứu kỹ thuật, so sánh lựa chọn trước khi quyết định.
- `debug`: Debug có hệ thống, phân tích root cause.
- `.claude/skills/formulas.md`: công thức tạo module mới, generateCode, FSM, PDF, permission, filter.

**Agents**:

- **`planner`**: Tạo implementation plan trong `plans/` trước khi bắt đầu feature phức tạp.
- **`tester`**: Delegate chạy test và phân tích kết quả — không ignore failing tests.
- **`code-reviewer`**: Review code sau implementation — bắt buộc trước khi đánh dấu task hoàn thành.
- `docs-manager`: Cập nhật `docs/` sau khi thay đổi architecture, API, schema.
- `researcher`: Chỉ dùng khi cần so sánh lựa chọn kỹ thuật; kết thúc bằng `Recommendation`.

---

## 4. Important business rules

- `cost_price` trên sản phẩm là giá **đã gồm VAT**.
- `total_cost = cost_price + business_cost`; không cộng VAT lần nữa.
- `unit_price` trong đơn mua là giá đã gồm VAT; khi tạo hóa đơn phải back-calculate để tách subtotal/tax.
- Tồn kho tính từ `SUM(stock_movements.quantity)`; không lưu tồn kho trực tiếp trên sản phẩm.
- Soft delete chỉ áp dụng cho master data: products, customers, suppliers, users, services.
- Stock entry bắt buộc đi qua PO; không tạo phiếu nhập tự do.
- Serial tracking phải nhất quán qua: nhập kho, xuất kho, chuyển kho, trả hàng, hủy phiếu.
- Khi hủy phiếu đã confirmed, phải tạo reversal movement thay vì âm thầm sửa/xóa dữ liệu lịch sử.
- Invoice chỉ tự động Paid khi payment >= total qua service xử lý payment; không thêm action mark-paid thủ công nếu không có yêu cầu rõ.
- Accounting auto-posting phải tránh double-posting bằng cách kiểm tra journal entry liên quan trước khi post.
- Dòng kế toán có thể cần `project_id`; không bỏ mất chiều dự án khi sửa nghiệp vụ dự án/TK 154.
- **AR/AP**: Mọi màn công nợ phải thu/trả phải dùng `ArApLedgerService` — không tự query invoice/purchase_invoice riêng. Opening balance không phải doanh thu/chi phí. Xem `docs/AR_AP_LOGIC.md`.
- **Tài khoản kế toán**: Không hardcode TK trong services. Dùng `AccountingSettings::get('key', 'fallback')` cho TK hệ thống; `product->revenue_account_code`, `customer->getReceivableAccount()`, `supplier->getPayableAccount()` cho TK per-entity. Xem `.claude/rules/project-state.md` mục "TK hệ thống".
- **JournalEntry FSM**: draft → posted → reversed → voided (terminal); draft → (hard delete). Bút toán auto đã posted không sửa trực tiếp — dùng `AccountingService::unpost()` trước, hoặc reverse. Bút toán đã voided không thể restore.
- **JournalEntry void rule**: JE nháp → hard delete; JE posted → void; JE reversed → void cả cặp trong 1 transaction; kỳ khóa → block.

---

## 5. Coding workflow

Nguyên tắc: **YAGNI · KISS · DRY** — file ≤ 200 lines, diff nhỏ nhất có thể.

**Feature mới:**

1. Activate `cook` skill trước khi bắt đầu (`/cook <task> --fast` hoặc `--interactive`).
2. Dùng `/plan` hoặc `planner` agent tạo plan trong `plans/` nếu task phức tạp.
3. Đọc rule phù hợp trong `.claude/rules/` và tìm pattern hiện có.
4. Implement — sửa ít nhất có thể, không refactor ngoài phạm vi.
5. Chạy test (`php artisan test`) hoặc delegate `tester` agent.
6. Activate `code-review` skill sau khi implement xong.
7. Nếu thay đổi architecture/schema/API, delegate `docs-manager` cập nhật `docs/`.
8. Báo cáo theo format cuối file.

**Bug fix:**

1. Activate `fix` skill trước khi sửa bất kỳ lỗi nào.
2. Đọc file liên quan, xác định root cause (không đoán).
3. Sửa minimal — chạy test liên quan để xác nhận.
4. Báo cáo theo format cuối file.

---

## 6. Commands

Nếu chưa chắc command chính xác, kiểm tra `composer.json`, `package.json` trước. `package.json` chỉ có `build` và `dev` — không có `lint` hay `typecheck`.

```bash
php artisan test
php artisan migrate:status
php artisan route:list
npm run build
npm run dev
```

## 7. Dangerous commands — không chạy nếu chưa được xác nhận rõ

```bash
php artisan migrate:fresh
php artisan db:wipe
php artisan db:seed
composer update
npm update
rm -rf
git reset --hard
git clean -fd
```

---

## 8. Reporting format

Khi hoàn thành task, trả lời ngắn theo mẫu:

```txt
Đã làm:
- ...

Files changed:
- ...

Đã kiểm tra:
- ...

Chưa kiểm chứng / rủi ro:
- ...
```

Nếu chưa chạy được test hoặc không có môi trường để chạy, phải ghi rõ `Chưa kiểm chứng`.
