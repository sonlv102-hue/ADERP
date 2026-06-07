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
| Migration, model, schema, relation | `.claude/rules/database-schema.md` |
| Seeder, routes, admin, permission | `.claude/rules/rbac.md` |
| Module map, service map, FSM, migration prefix | `.claude/rules/phase-history.md` |
| Trạng thái module đã làm/xong/gần đây | `.claude/rules/project-state.md` |

Skills:
- `.claude/skills/formulas.md`: công thức tạo module mới, generateCode, FSM, PDF, permission, filter.

Agents:
- `.claude/agents/researcher`: chỉ dùng khi cần so sánh lựa chọn kỹ thuật; kết thúc bằng `Recommendation`.

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

---

## 5. Coding workflow

1. Xác định đúng module và files liên quan.
2. Đọc rule phù hợp trong `.claude/rules/`.
3. Tìm pattern hiện có trước khi viết code mới.
4. Tóm tắt phát hiện và đề xuất kế hoạch ngắn.
5. Sửa ít nhất có thể.
6. Chạy test/lint/typecheck hoặc command liên quan.
7. Báo cáo kết quả theo format ở cuối file.

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
