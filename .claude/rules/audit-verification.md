# Audit Verification Rule

Áp dụng cho mọi lệnh `php artisan *:audit*`, `*:reconcile*`, `*:diagnose*` trong project (inventory, purchase-invoices, accounting, projects, stock-exits, stock-transfers, bank-statements...).

## Nguyên tắc bắt buộc

**Không báo cáo output của audit command cho người dùng khi chưa tự kiểm chứng root cause.** Một dòng "warning"/"lệch" trong output KHÔNG đồng nghĩa với một lỗi dữ liệu thật — bản thân script audit cũng có thể có giới hạn/bug trong logic so sánh.

Trước khi trình bày bất kỳ finding nào là "vấn đề cần xử lý", phải:

1. **Đọc source code** của Command class (`app/Console/Commands/*.php`) để hiểu chính xác SQL/filter đang so sánh cái gì với cái gì (period, status, `whereNull(...)`, join điều kiện gì).
2. **Truy vết dữ liệu gốc** liên quan đến từng dòng bị flag — query trực tiếp `stock_movements`, `journal_entries`/`journal_entry_lines`, `inventory_balances`, v.v. — để xác nhận số liệu thực tế, không suy diễn từ số tổng hiển thị.
3. **Phân loại rõ ràng** trước khi báo cáo:
   - **Vấn đề thật** — dữ liệu sai lệch thực sự, cần sửa.
   - **False positive của script** — script không lọc/tính đúng một trường hợp hợp lệ (ví dụ: loại trừ nhầm movement có `project_id`, so sánh gross thay vì net, không tách theo `issue_purpose`...).
4. Khi phát hiện false positive, nêu rõ **dòng code cụ thể gây ra** (file:line) và hỏi người dùng có muốn sửa script audit hay không — không tự sửa nếu chưa được yêu cầu.

## Vì sao

Từng báo cáo "11 dòng lệch inventory_balances vs stock_movements" cho người dùng mà không kiểm tra kỹ — thực tế 6/11 dòng là false positive vì `ReconcileInventoryBalancesCommand.php` có `whereNull('m.project_id')` loại trừ nhầm các movement xuất kho dự án, khiến số dư đầu kỳ đã xuất hết bị hiển thị nhầm thành "còn treo". Người dùng phải tự phát hiện và chỉ ra lỗi này thay vì được báo đúng ngay từ đầu.

## Cách áp dụng

- Áp dụng cho **mọi** task dạng "check", "audit", "cảnh báo", "rà soát" liên quan tồn kho/kế toán — không chỉ khi người dùng nghi ngờ.
- Nếu audit trả về nhiều dòng (>3), ưu tiên trace kỹ ít nhất các dòng có giá trị tiền lớn hoặc số lượng dòng nhiều trước khi tổng hợp báo cáo.
- Nếu không đủ thời gian/token để trace hết, phải nói rõ trong báo cáo dòng nào đã kiểm chứng kỹ, dòng nào chỉ lấy từ output thô (`Chưa kiểm chứng`).
