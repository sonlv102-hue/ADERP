# Quy chuẩn báo cáo/PDF/bản in — Tracking

Tài liệu chuẩn: `docs/REPORTING_STANDARDS.md`. Rule cho AI/dev: `.claude/rules/reporting-standards.md`.

## Trạng thái tổng quan

- **Hạ tầng:** Hoàn thành (đợt 2026-07-03).
- **Migrate:** 3/25 điểm (đợt 1). 22 điểm còn lại là backlog có chủ đích.

## Hạ tầng đã tạo (đợt 1 — 2026-07-03)

| Hạng mục | Đường dẫn |
|---|---|
| Tài liệu chuẩn | `docs/REPORTING_STANDARDS.md` |
| Rule AI/dev | `.claude/rules/reporting-standards.md` |
| Blade partial (PDF) | `resources/views/pdf/partials/signature-section.blade.php` |
| Vue component (bản in) | `resources/js/Components/Shared/ReportSignatureSection.vue` |
| Excel trait | `app/Exports/Concerns/HasSignatureBlock.php` |
| Setting mới | `report_signing_place` (group `company`) — `SettingsSeeder`, `SettingsController`, `Admin/Settings/Index.vue` |

Test hạ tầng: `tests/Feature/ReportSignatureSectionTest.php` (7 TC), `tests/Feature/HasSignatureBlockTest.php` (5 TC), `tests/Feature/SettingsReportSigningPlaceTest.php` (2 TC).

## Đã chuyển sang component chung (3/25)

| File | Kênh | Test | Ghi chú |
|---|---|---|---|
| `resources/views/pdf/voucher-listing.blade.php` | PDF | `tests/Feature/VoucherListingReportTest.php` (test mới: export pdf) | Xóa CSS `.sign-*` cũ, thêm `signingPlace`/`signingDate` từ controller |
| `app/Exports/Reports/VoucherListingExport.php` | Excel | `tests/Feature/HasSignatureBlockTest.php` (gián tiếp qua trait) | Dùng `HasSignatureBlock::writeSignatureBlock()` thay vòng lặp merge cell cũ |
| `resources/js/Pages/Admin/Attendance/Show.vue` | Vue print | `tests/Feature/AttendanceExportTest.php` (không đổi), kiểm tra build | **Cải thiện thật:** trước đây không có dòng ngày ký; nay có `signingPlace`/`signingDate` từ `company` shared prop |

## Backlog — 22 điểm còn lại (chưa migrate, giữ nguyên HTML/CSS cũ)

Phân loại theo mục 13 tài liệu chuẩn: **(3) Đang viết riêng, cần chuyển sang component chung** trừ khi ghi chú khác.

### Kế toán — PDF/Excel/Vue báo cáo tài chính (nhóm ưu tiên cao — có "Người đại diện theo pháp luật")
- `resources/views/pdf/b01a-dnn.blade.php` (Balance Sheet)
- `resources/views/pdf/b02-dnn.blade.php` (Income Statement)
- `resources/views/pdf/b03-dnn.blade.php` (Cash Flow)
- `resources/js/Pages/Reports/BalanceSheet/Index.vue`
- `resources/js/Pages/Reports/IncomeStatement/Index.vue`
- `resources/js/Pages/Reports/CashFlowStatement/Index.vue`
- `app/Exports/Reports/IncomeStatementExport.php`
- `app/Exports/Reports/CashFlowStatementExport.php`

### Kế toán — chứng từ khác
- `resources/views/pdf/voucher-listing-detail.blade.php` + `app/Exports/Reports/VoucherListingDetailExport.php` (cùng cặp với báo cáo đã migrate — làm tiếp theo dễ nhất)
- `resources/views/pdf/invoice.blade.php` — (2) có bố cục ngang đúng, chỉ thiếu dòng ngày ký + chưa dùng component chung
- `resources/views/pdf/e-invoice.blade.php` — **(6) Biểu mẫu đặc thù, cần giữ nguyên** — nghi là mẫu hóa đơn điện tử theo quy định pháp lý (Nghị định/Thông tư hóa đơn điện tử). **Cần xác nhận với người phụ trách kế toán trước khi đụng vào**, chưa tự ý sửa.

### Bán hàng — Mua hàng
- `resources/views/pdf/quotation.blade.php` — (2) bố cục ngang đúng, thiếu dòng ngày ký
- `resources/views/pdf/contract.blade.php` — (2) có ngày ký nhưng **sai bố cục** (mỗi box tự có ngày riêng, không căn phải) → ưu tiên sửa

### Kho
- `resources/views/pdf/stock_entry.blade.php` — (2) bố cục ngang đúng, thiếu dòng ngày ký
- `resources/views/pdf/stock_exit.blade.php` — (2) bố cục ngang đúng, thiếu dòng ngày ký
- `resources/views/pdf/stock_entry_list.blade.php` — **(5) Không cần chữ ký** (bảng tra cứu)
- `resources/views/pdf/small-tools-list.blade.php` — **(5) Không cần chữ ký** (bảng tra cứu, đã tạo mới đợt trước)

### Nhân sự — Lương (nhóm có anti-pattern địa chỉ đầy đủ — ưu tiên cao)
- `resources/views/pdf/payroll.blade.php` — **(2) có chữ ký nhưng sai bố cục**: dòng ngày ký hiện đang chèn `company_address` đầy đủ vào trước "ngày..." — đúng anti-pattern tài liệu chuẩn mô tả, cần sửa đầu tiên trong đợt sau
- `app/Exports/Sheets/PayrollSummarySheet.php` (Excel) — cùng vấn đề địa chỉ đầy đủ
- `resources/js/Pages/Accounting/Payrolls/Show.vue` — (3) chưa có dòng ngày ký
- `resources/views/pdf/partials/employee-profile-body.blade.php` + 2 file include nó (`pdf/employee-profile.blade.php`, `print/employee-profile.blade.php`) — (3)

## Không nằm trong phạm vi rà soát chữ ký (không sửa)

- `resources/views/pdf/_font.blade.php` — partial font, không có chữ ký.

## Việc chưa làm (ngoài migrate 22 file trên)

- UI cấu hình người ký theo chức danh + hiệu lực theo ngày/chi nhánh (mục 10 tài liệu chuẩn) — chưa triển khai, cần thiết kế riêng (bảng `report_signers` mới, migration, CRUD UI).
- Chụp ảnh trước/sau cho từng nhóm mẫu chính — chưa làm cho đợt 1 (giới hạn thời gian phiên làm việc); nếu cần, dùng skill `run-web-erp` để chụp qua trình duyệt thật.
- Migrate 22 file backlog ở trên — làm dần theo đợt, nhóm theo độ ưu tiên đã đánh dấu.

## Rollback

Đợt 1: commit `60d5b2c` ("feat: add shared report signature-section standard and infra"). Rollback: `git revert 60d5b2c` — không có migration schema (chỉ thêm 1 setting key qua seeder, an toàn revert vì seeder dùng `updateOrInsert`, revert code không tự xóa key khỏi DB nhưng key thừa vô hại nếu không dùng).
