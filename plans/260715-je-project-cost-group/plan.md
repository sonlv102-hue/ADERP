# Dự án + Nhóm chi phí trên Phiếu kế toán thủ công

## Bối cảnh
G2 audit phát hiện 6 JE (999-1004, 182.565.000đ) là "kết chuyển lương kỹ thuật" Dr154/Cr627 nhưng
không có `project_id`/`cost_group`/`project_wip_entries` — vì màn Phiếu kế toán thủ công không có
chỗ chọn dự án/nhóm chi phí. Việc này thêm cơ chế bắt buộc + tự động tạo WIP để tránh lặp lại.

## Phạm vi (theo đúng 11 yêu cầu của user, xem message gốc)
1. Migration: `journal_entry_lines` thêm `cost_group`, `project_cost_note`; `project_wip_entries`
   thêm `journal_entry_line_id` (nullable FK) + unique index `(source_type, source_id, journal_entry_line_id)`
   qua partial unique (chỉ khi journal_entry_line_id not null) để chống trùng WIP theo dòng.
2. `AccountingService`: `createDraft()`/`updateLines()` bổ sung set `project_id` cho line (đang thiếu —
   phát hiện qua khảo sát); `validateLines()` thêm rule bắt buộc project_id+cost_group cho dòng Nợ TK154;
   `markPosted()` sau khi set status=posted → gọi tạo WIP cho các dòng đủ điều kiện; `reverse()`/`void`
   flow → soft-cancel WIP liên quan (status=cancelled/reversed, không hard-delete).
3. `JournalEntryController`: validate ở tầng request (permission `accounting.journals.post` đã đúng,
   không cần đổi); truyền `lines[].project_id`/`cost_group`/`project_cost_note` qua store/update.
2. `Form.vue`: thêm "Dự án mặc định" ở header (RemoteSearchSelect, optional) tự-fill dòng mới; bảng dòng
   thêm cột Dự án (RemoteSearchSelect) + Nhóm chi phí (select 7 giá trị) + gợi ý tự động cost_group theo
   text-match diễn giải (chỉ gợi ý, không tự set).
3. Command mới `journal-entries:audit-project-dimensions` (theo mẫu JournalAuditService/Command khác).
4. Command mới `journal-entries:repair-legacy-project-wip --dry-run/--apply` — CHỈ áp dụng cho JE do
   user chỉ định qua `--je=` sau khi xác nhận mapping dự án; không tự gán mặc định.
5. Permission: đã đủ (`accounting.journals.post` gate cả post lẫn tạo WIP) — không cần thêm.
6. 8 test case theo đúng yêu cầu.

## Quyết định thiết kế cần chốt trước khi code (đã tự quyết theo dữ liệu khảo sát, sẽ nêu rõ khi báo cáo)
- `cost_group` dùng field MỚI trên `journal_entry_lines`, KHÔNG đụng `project_wip_entries.cost_type`
  (cột cũ, tên khác) — khi tạo WIP entry, map `cost_group` → `cost_type` (copy giá trị, tên cột khác
  nhau do lịch sử; `subcontractor` (yêu cầu) → map thành `subcontract` (giá trị enum cũ đã có ở
  `ProjectWipEntry`) để tương thích ngược, các giá trị còn lại copy y nguyên nếu trùng, khác thì giữ
  nguyên string mới (`transport`, `equipment` là giá trị mới thêm cho `cost_type`, không có trong enum
  cũ nhưng cột là string tự do nên không lỗi).
- Hủy/đảo WIP theo pattern **soft-cancel** (set `status`), không hard-delete — nhất quán với
  `ProjectController`/`ProjectWipCorrectionService`, KHÁC với `StockService` (hard-delete) vì JE thủ
  công không có luồng "sửa lại toàn bộ items" như StockExit, nên giữ audit trail an toàn hơn.
- Chống trùng WIP: check `exists()` trước khi tạo (giống `ProjectWipService::createFromPurchaseInvoiceItem`)
  + unique index DB-level làm lưới an toàn thứ 2.
