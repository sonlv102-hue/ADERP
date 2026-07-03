# Reporting Standards — Chữ ký & Bố cục Báo cáo/PDF/Bản in

**Bắt buộc đọc file này trước khi tạo hoặc sửa bất kỳ báo cáo, PDF, Excel export, hoặc bản in nào có phần ký.**

Tài liệu chuẩn đầy đủ: `docs/REPORTING_STANDARDS.md`. File này chỉ tóm tắt phần dev/AI cần nhớ khi code.

## Quy tắc bắt buộc

1. **Không tự viết HTML/CSS phần chữ ký riêng cho báo cáo mới hoặc khi sửa báo cáo cũ.** Dùng component dùng chung:
   - PDF (blade/dompdf): `@include('pdf.partials.signature-section', [...])`
   - Vue print (`window.print()`): `<ReportSignatureSection>` từ `resources/js/Components/Shared/ReportSignatureSection.vue`
   - Excel (maatwebsite/excel): trait `App\Exports\Concerns\HasSignatureBlock` → gọi `$this->writeSignatureBlock(...)`
2. Chữ ký luôn bố trí **ngang, chia đều theo số người ký** (1–5 vị trí). Không xếp dọc.
3. Tiêu đề chức danh (`title`) truyền vào component qua data, **không hard-code trong component**. Từng báo cáo tự quyết định danh sách signers theo ma trận ở `docs/REPORTING_STANDARDS.md` mục 9.
4. Dòng ngày ký: `{Địa danh}, ngày dd tháng mm năm yyyy`, căn phải, **không kèm địa chỉ đầy đủ**. Địa danh lấy từ `Setting::get('report_signing_place')`; nếu rỗng thì để trống (không tự parse địa chỉ).
5. Nếu chưa có tên người ký: để trống ô — không render `null`/`undefined`, không tự điền tên giả, không dùng gạch ngang thay tên.
6. Phần ngày ký + toàn bộ chữ ký là **một khối** — áp `page-break-inside: avoid` / `break-inside: avoid` (đã có sẵn trong 3 component dùng chung ở trên).
7. Báo cáo tra cứu/dashboard nội bộ **không cần** phần ký — không tự động thêm.
8. Trước khi coi một báo cáo là "đã đạt chuẩn", kiểm tra nó có nằm trong danh sách "đã chuyển đổi" tại `plans/260703-reporting-standards/plan.md` không — nếu chưa, đó vẫn là code cũ, không phải chuẩn mới.
9. Khi migrate một báo cáo sang chuẩn mới: chạy đủ checklist kiểm thử ở `docs/REPORTING_STANDARDS.md` mục 14, và **không được đổi số liệu nghiệp vụ** của báo cáo đó trong cùng lúc.

## Khi nào KHÔNG áp dụng (ngoại lệ)

- Báo cáo không có phần ký (dashboard, bảng tra cứu).
- Biểu mẫu đặc thù có yêu cầu pháp lý cố định (vd: hóa đơn điện tử `e-invoice.blade.php` — cần xác nhận với kế toán trước khi sửa). Phải ghi rõ lý do ngoại lệ trong `docs/REPORTING_STANDARDS.md` mục 13, không được lấy lý do này để tiếp tục copy HTML chữ ký ở nơi khác.
