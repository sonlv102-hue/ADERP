# Quy chuẩn báo cáo, PDF và bản in — Mini ERP

Áp dụng cho mọi báo cáo, chứng từ, PDF và bản in có phần ký trong toàn hệ thống.

**Quy định cốt lõi:** Tất cả báo cáo và chứng từ có phần ký trong MiniERP phải sử dụng khu vực chữ ký dùng chung. Các vị trí ký được bố trí theo chiều ngang, chia đều chiều rộng trang, cấu hình linh hoạt theo từng loại báo cáo và không được xếp dọc tại cùng một phía. Dòng địa danh, ngày ký phải căn phải, không lặp lại địa chỉ đầy đủ của doanh nghiệp. Phần ngày ký và chữ ký phải được giữ nguyên khối khi ngắt trang. Không được viết riêng HTML/CSS chữ ký cho từng báo cáo nếu component dùng chung có thể đáp ứng.

---

## 1. Kết quả khảo sát hiện trạng (2026-07-03)

Trước khi xây dựng chuẩn, đã rà soát toàn bộ hệ thống. Ghi lại đây để không lặp lại sai lầm và làm căn cứ cho danh sách migrate (mục 12).

| Chỉ số | Thực tế |
|---|---|
| Tổng điểm có chữ ký | 20 view (15 blade + 5 Vue) + 5 Excel export |
| Bố cục dọc (vertical) | 0/25 — toàn bộ đã ngang (flex/table/grid) |
| Component chữ ký dùng chung | Không có — mỗi file tự viết |
| `page-break-inside`/`break-inside: avoid` | 0/25 |
| Tiêu đề chức danh hard-code | 25/25 (100%) |
| Địa danh trong dòng ký | 0/25 — chưa file nào có địa danh, chỉ "Lập, ngày…" |
| Có địa chỉ đầy đủ trong dòng ký (anti-pattern) | 1 file: `payroll.blade.php` |
| Setting `report_signing_place` | Chưa tồn tại |

**Kết luận:** vấn đề thật sự không phải "chữ ký xếp dọc" mà là thiếu component dùng chung, tiêu đề hard-code, thiếu địa danh chuẩn, và chưa bảo vệ ngắt trang.

---

## 2. Nguyên tắc bố cục chữ ký

- Các vị trí ký nằm trên **cùng một hàng**, chia đều chiều rộng trang theo số lượng người ký.
- Số lượng vị trí ký linh hoạt: 1 đến 5 (không mặc định 3).
- Tên chức danh cấu hình theo từng loại báo cáo, **không viết cứng trong component** (component chỉ nhận `signers[]` làm input).
- Không được: xếp dọc, khoảng trắng lớn bất thường, chồng lấn, tràn trang, tách cột sang trang khác.

## 3. Địa danh và ngày ký

- Dòng ký đặt **phía trên** khu vực chữ ký, **căn phải**: `{Địa danh}, ngày {dd} tháng {mm} năm {yyyy}`.
- Không hiển thị địa chỉ đầy đủ ở dòng này — địa chỉ đầy đủ chỉ ở phần thông tin doanh nghiệp đầu báo cáo.
- Nguồn địa danh: setting `report_signing_place` (nhóm `company`, quản lý tại **Hệ thống → Cài đặt công ty**).
  - **Quyết định thiết kế:** nếu `report_signing_place` chưa cấu hình, dòng ký **bỏ trống địa danh** (không tự động parse tỉnh/thành từ `company_address` bằng heuristic) — vì tách địa danh từ chuỗi địa chỉ tự do dễ sai và đây là văn bản có giá trị pháp lý/kế toán. Admin cần khai báo `report_signing_place` một lần.
- Nguồn ngày ký, theo thứ tự ưu tiên:
  1. Ngày người dùng chọn khi xuất báo cáo (nếu form export có tham số ngày).
  2. Ngày chứng từ, nếu biểu mẫu yêu cầu (vd: phiếu thu/chi dùng ngày chứng từ).
  3. Ngày xuất báo cáo (`now()`), nếu không có lựa chọn khác.
- Không viết cứng ngày tháng trong template.

## 4. Dữ liệu từng vị trí ký

Mỗi phần tử trong mảng `signers[]`:

| Field | Bắt buộc | Ghi chú |
|---|---|---|
| `title` | có | Chức danh, in hoa, in đậm |
| `instruction` | không | Vd: `(Ký, ghi rõ họ tên)`, `(Ký, ghi rõ họ tên, đóng dấu)` |
| `name` | không | Họ tên người ký — nếu trống thì **để trống ô**, không hiển thị `null`/`undefined`, không tự điền tên giả, không dùng gạch ngang thay tên |
| `position` | không | Chức vụ hiển thị dưới tên (nếu có) |
| `signature_image` | không | URL ảnh chữ ký điện tử |
| `stamp_image` | không | URL ảnh con dấu (nếu được phép) |

## 5. Khoảng trống để ký

- Chiều cao vùng ký: tối thiểu 55px, khuyến nghị 70–90px.
- Ảnh chữ ký/con dấu (nếu có) nằm trong vùng này, không làm đổi bố cục toàn báo cáo.

## 6. Component dùng chung bắt buộc

Ba kênh xuất báo cáo dùng ba component tương ứng — **không sao chép HTML/CSS chữ ký vào từng file**:

| Kênh | Component | Đường dẫn |
|---|---|---|
| PDF (dompdf) | Blade partial | `resources/views/pdf/partials/signature-section.blade.php` |
| Bản in trình duyệt (Vue, `window.print()`) | Vue component | `resources/js/Components/Shared/ReportSignatureSection.vue` |
| Excel (maatwebsite/excel) | PHP trait | `app/Exports/Concerns/HasSignatureBlock.php` |

**Quyết định thiết kế (khác nhẹ so với đề xuất ban đầu):** CSS phần ký PDF được nhúng **trong chính Blade partial** (một `<style>` scoped theo class `.report-signature-*`) thay vì một file `resources/css/report-print.css` riêng, vì dompdf trong hệ thống này không load CSS ngoài qua `<link>` — mọi PDF blade hiện có đều nhúng `<style>` trực tiếp (xem `pdf/_font.blade.php`, `pdf/voucher-listing.blade.php`). Cách này giữ nguyên convention sẵn có và chỉ cần thêm một dòng `@include(...)` vào mỗi file thay vì sửa cả `<head>`.

### 6.1. Dùng trong PDF (Blade)

```blade
@include('pdf.partials.signature-section', [
    'signingPlace' => \App\Models\Setting::get('report_signing_place'),
    'signingDate'  => now(), // hoặc ngày chứng từ / ngày người dùng chọn
    'signers' => [
        ['title' => 'NGƯỜI LẬP BIỂU',  'instruction' => '(Ký, ghi rõ họ tên)'],
        ['title' => 'KẾ TOÁN TRƯỞNG',  'instruction' => '(Ký, ghi rõ họ tên)'],
        ['title' => 'GIÁM ĐỐC',        'instruction' => '(Ký, ghi rõ họ tên, đóng dấu)'],
    ],
])
```

### 6.2. Dùng trong Vue (bản in)

```vue
<ReportSignatureSection
  :signing-place="signingPlace"
  :signing-date="signingDate"
  :signers="[
    { title: 'NGƯỜI LẬP BẢNG', instruction: '(Ký, ghi rõ họ tên)', name: sheet.creator },
    { title: 'PHÒNG KẾ TOÁN',  instruction: '(Ký, ghi rõ họ tên)' },
    { title: 'GIÁM ĐỐC',       instruction: '(Ký, ghi rõ họ tên, đóng dấu)' },
  ]"
/>
```

### 6.3. Dùng trong Excel

```php
use App\Exports\Concerns\HasSignatureBlock;

class MyExport implements WithEvents
{
    use HasSignatureBlock;

    // trong registerEvents() / AfterSheet:
    $this->writeSignatureBlock($sheet, $signRow, [
        ['title' => 'Người lập biểu', 'instruction' => '(Ký, họ tên)'],
        ['title' => 'Kế toán trưởng', 'instruction' => '(Ký, họ tên)'],
        ['title' => 'Giám đốc',        'instruction' => '(Ký, họ tên, đóng dấu)'],
    ], signingPlace: $place, signingDateLabel: 'ngày ' . now()->format('d') . ' tháng ' . now()->format('m') . ' năm ' . now()->format('Y'), firstCol: 'A', lastCol: 'H');
```

## 7. Ngắt trang

- `.report-signature-section` (PDF/print) dùng `page-break-inside: avoid; break-inside: avoid;`.
- Vue component áp dụng thêm Tailwind `break-inside-avoid print:break-inside-avoid` làm lớp bảo hiểm.
- Nếu thư viện PDF không tôn trọng thuộc tính này với dữ liệu quá dài, phải chủ động kiểm tra và tạo ngắt trang trước khu vực ký (xem mục kiểm thử).

## 8. Không phải báo cáo nào cũng cần chữ ký

Chỉ hiển thị `ReportSignatureSection`/partial khi báo cáo có yêu cầu xác nhận (chứng từ kế toán, phiếu thu/chi, bảng lương, bảng chấm công, biên bản...). Báo cáo phân tích nội bộ, dashboard, bảng tra cứu (vd: `small-tools-list.blade.php`, `stock_entry_list.blade.php`) không bắt buộc có chữ ký — **không tự động thêm chữ ký vào các báo cáo này**.

## 9. Ma trận vị trí ký theo loại chứng từ (mặc định, có thể cấu hình lại)

| Loại chứng từ | Vị trí ký |
|---|---|
| Bảng lương | Người lập bảng · Kế toán trưởng · Giám đốc |
| Bảng chấm công | Người chấm công · Phụ trách bộ phận · Người duyệt |
| Phiếu thu | Người lập phiếu · Người nộp tiền · Thủ quỹ · Kế toán trưởng · Giám đốc |
| Phiếu chi | Người lập phiếu · Người nhận tiền · Thủ quỹ · Kế toán trưởng · Giám đốc |
| Phiếu nhập kho | Người lập phiếu · Người giao hàng · Thủ kho · Kế toán trưởng/phụ trách |
| Phiếu xuất kho | Người lập phiếu · Người nhận hàng · Thủ kho · Kế toán trưởng/phê duyệt |
| Nhật ký chung, sổ cái, BCTC | Người lập biểu · Kế toán trưởng · Người đại diện theo pháp luật |
| Khấu hao TSCĐ | Người lập biểu · Kế toán trưởng · Giám đốc |
| Phân bổ CCDC/CPTT | Người lập biểu · Kế toán trưởng · Giám đốc |
| Giá thành sản xuất | Người lập biểu · Kế toán giá thành · Kế toán trưởng · Giám đốc/phê duyệt |
| Kiểm kê | Người lập biên bản · Thành viên kiểm kê · Kế toán trưởng · Đại diện lãnh đạo |

## 10. Cấu hình người ký tập trung (định hướng — chưa triển khai UI đầy đủ trong đợt này)

Đợt hạ tầng hiện tại mới bổ sung setting `report_signing_place`. Việc quản lý người ký theo từng chức danh (họ tên/ảnh chữ ký/hiệu lực theo ngày/theo chi nhánh) là hạng mục lớn hơn, chưa triển khai — xem mục 13 "Việc chưa làm" bên dưới.

## 11. Quy tắc chung khác (tiêu đề, ngày xuất, bảng số liệu, tổng cộng, footer)

- Tên doanh nghiệp căn giữa, tên báo cáo in đậm, kỳ báo cáo hiển thị rõ, không chồng với bảng dữ liệu.
- Ngày xuất: `Ngày xuất: dd/mm/yyyy HH:mm` (hoặc chỉ `dd/mm/yyyy` nếu không cần giờ), theo giờ `Asia/Ho_Chi_Minh`.
- Bảng dữ liệu: cột tiền/số lượng căn phải, STT căn giữa, mô tả căn trái, dùng dấu phân cách hàng nghìn thống nhất, không hiển thị `null`/`undefined`/`NaN`, không cắt chữ.
- Tổng cộng: in đậm, có nền/đường kẻ phân biệt, không tách khỏi dòng dữ liệu cuối, không bị phần ký chồng lên.
- Footer có thể có: trang x/y, mã báo cáo, thời điểm xuất, người xuất — không lặp lại địa chỉ dài.

## 12. Rà soát & danh sách migrate

Toàn bộ danh sách 25 điểm đã rà soát, phân loại, và trạng thái migrate được theo dõi tại **`plans/260703-reporting-standards/plan.md`** (không lặp lại danh sách dài trong tài liệu chuẩn này để tránh trôi dữ liệu khi cập nhật).

Tóm tắt trạng thái tại thời điểm viết tài liệu này:
- **Đã chuyển sang component chung (3):** `voucher-listing.blade.php` (PDF), `Admin/Attendance/Show.vue` (bản in), `VoucherListingExport.php` (Excel).
- **Còn lại (22):** giữ nguyên HTML/CSS cũ, đã phân loại trong plan, migrate dần ở các đợt sau — **không được coi là "đã đạt chuẩn"** cho tới khi có trong danh sách "đã chuyển đổi".

## 13. Việc chưa làm (backlog có chủ đích, không phải thiếu sót bị bỏ quên)

- Migrate 22 báo cáo/export còn lại (danh sách chi tiết trong plan).
- UI cấu hình người ký theo chức danh + hiệu lực theo ngày/chi nhánh (mục 10).
- Chụp ảnh trước/sau cho từng nhóm mẫu chính (đợt này chỉ có ảnh cho 3 báo cáo mẫu, xem plan).
- Đánh giá riêng `e-invoice.blade.php` — khả năng là mẫu hóa đơn điện tử theo quy định pháp lý (Nghị định/Thông tư hóa đơn điện tử) nên có thể là **ngoại lệ đặc thù cần giữ nguyên**; cần người phụ trách kế toán xác nhận trước khi đụng vào.

## 14. Kiểm thử bắt buộc khi migrate một báo cáo

- Số dòng dữ liệu: 1 dòng · 10 dòng · gần đầy 1 trang · vừa tràn trang 2 · 50–100 dòng.
- Người ký: có tên · không có tên (không hiện null/undefined) · 1/2/3/4/5 người ký.
- Khổ trang: A4 dọc · A4 ngang (nếu áp dụng).
- Kênh: PDF · Print Preview · Excel (nếu có).
- Trường hợp biên: chức danh dài, tên người ký dài, dấu tiếng Việt, không có địa danh, không có ngày ký, kỳ đã khóa.
- Không được làm thay đổi số liệu nghiệp vụ của báo cáo khi migrate phần ký.
