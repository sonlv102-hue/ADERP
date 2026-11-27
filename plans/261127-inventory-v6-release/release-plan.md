# Inventory V1–V6 — Kế hoạch triển khai production, repair và rollback

Trạng thái: **CHỜ OWNER DUYỆT (PRODUCTION_RELEASE_GATE)**. Không có bước nào dưới đây đã chạy trên VPS.
Tài liệu này không chứa số liệu production; mọi con số phải lấy từ audit trên dữ liệu production mới nhất tại thời điểm deploy.

## 1. Phạm vi release

| Commit (web_erp) | Nội dung |
|---|---|
| 9acedcd (V1) | Vòng đời chứng từ kho đối xứng, AVCO-safe |
| 06a0e98 (V3) | Audit toàn bộ tồn kho, chặn thu hồi khi có phụ thuộc, trả hàng theo giá gốc, `inventory:repair-avco-balance` |
| 8c05e17 (V4) | Giữ ID dòng hàng khi sửa phiếu (truy vết movement), tìm sản phẩm form xuất |
| 4931685 (V6) | Báo cáo Kiểm soát sai lệch tồn kho và kế toán, phân trang ô tìm sản phẩm, sửa fixture PG |
| V6 release closure (commit sau 4931685) | Chặn xác nhận trả hàng khi chưa có quy tắc bút toán (A4), bảng đối chiếu giá trị AVCO/sổ kho/TK156/154, kiểm tra movement cho chuyển kho/trả hàng/kiểm kê, import phương án an toàn cho production |

Gitlink repo gốc hiện trỏ `a94baf8` (chậm 14 commit so với web_erp local). Khi release: push web_erp → cập nhật gitlink repo gốc đúng commit đã nghiệm thu → push repo gốc.

Migration mới (chạy theo thứ tự): `900251` (cột avco_qty/avco_amount, lot.source_stock_exit_id), `900252` (CHECK issue_purpose cho project_transfer, chỉ PG), `900253` (3 bảng báo cáo mới — không đụng bảng nghiệp vụ).

Seeder bắt buộc sau deploy (idempotent, KHÔNG truncate, chỉ thêm quyền + 1 menu):
`php artisan db:seed --class=InventoryIntegrityPermissionSeeder --force`
(deploy.sh không chạy seeder — xem project-state.md mục 14). KHÔNG chạy `RolePermissionSeeder` trên production.

## 2. Phân biệt repair kỹ thuật và xử lý kế toán

| Loại | Ai làm | Công cụ | Điều kiện |
|---|---|---|---|
| Repair kỹ thuật (AVCO lệch sổ kho do lỗi code cũ: PHANTOM/QUANTITY/MISSING_BALANCE có đủ bằng chứng) | DEV | `inventory:repair-avco-balance --manifest=…` (dry-run → apply, có log rollback) | Manifest lập từ audit production mới nhất; Owner duyệt phạm vi |
| Xử lý kế toán (NEGATIVE_STOCK do chứng từ, ZERO_QTY_WITH_VALUE, VALUE_MISMATCH legacy, JE thủ công, chính sách A3/A4) | Kế toán | Chứng từ/JE theo phương án được duyệt, theo dõi trên báo cáo | KHÔNG tự xác nhận NK-0052, KHÔNG tự tạo/sửa JE thuộc PENDING_DECISION |

Không áp số dư snapshot local lên production. Manifest local (storage/app/private/inventory-repairs) chỉ là tham chiếu, không dùng để apply.

## 3. Quy trình triển khai (sau khi Owner duyệt)

1. Xác minh phiên bản production (`git log -1` trên VPS, `php artisan migrate:status`).
2. Backup DB VPS mới nhất (`scripts/backup.sh`), ghi checksum SHA-256, chép bản sao ra ngoài VPS.
3. Thông báo dừng ghi kho/kế toán (bật maintenance hoặc thỏa thuận khung giờ); ghi lại mã chứng từ cuối cùng (NK/XK/CK/BT) làm mốc.
4. Deploy đúng commit đã nghiệm thu + cập nhật gitlink repo gốc (`deploy.sh` đầy đủ để build lại assets).
5. `php artisan migrate --force` → kiểm tra 900251–900253 = Ran.
6. Seeder quyền/menu (mục 1). Kiểm tra `MenuItem::where('key','warehouse.report.integrity')->exists()`.
7. `php artisan inventory:integrity-audit` (toàn hệ thống) + `php artisan inventory:audit --scope=full --export=…` trên dữ liệu production.
8. Trace từng dòng kỹ thuật (audit-verification.md) → lập manifest repair mới từ dữ liệu hiện tại.
9. `inventory:repair-avco-balance --manifest=… ` (dry-run) → Owner xác nhận phạm vi.
10. Apply repair đã duyệt (`--apply --reason=…`), lưu file log.
11. Audit lại (bước 7) — vấn đề kỹ thuật đã repair phải tự chuyển RESOLVED.
12. Smoke test: tạo/sửa/thu hồi/xác nhận lại phiếu nhập nháp thử, tìm sản phẩm form xuất, báo cáo tồn kho + KPI drilldown, báo cáo mới (mở chứng từ, giao việc, Excel).
13. Kiểm tra phân quyền: thủ kho chỉ xem, không thấy vấn đề kế toán; kế toán trưởng xác nhận chênh lệch; giám đốc không xác nhận chênh lệch.
14. Gắn phương án kế toán: `php artisan inventory:integrity-cases <file.json> --dry-run` → xem dòng "CẦN XÁC MINH LẠI" → chạy thật.
    File JSON chép lên VPS ngoài thư mục Git (vd. /root/aderp-cases/), xóa sau khi import. Khớp theo quy tắc + mã SP + kho / mã chứng từ / chủ đề —
    không theo ID VD-xx của Working. `expect` (count, value_diff_sum, qty_diff_sum) lệch số liệu production → vấn đề được đánh dấu needs_reverify
    + PENDING_VERIFICATION, không tự kết luận. Chạy lại không sinh trùng, không ghi đè trạng thái/kết quả đã có. KHÔNG import DB báo cáo từ local.
15. Mở lại hoạt động; bàn giao danh sách vấn đề cho kế toán (giữ nguyên PENDING_DECISION).

## 3b. Release checklist

- [ ] web_erp commit cuối đã nghiệm thu UI; gitlink repo gốc cập nhật đúng commit
- [ ] Backup VPS + checksum, chép ra ngoài VPS
- [ ] `migrate --force`: 900251, 900252, 900253 = Ran
- [ ] `db:seed --class=InventoryIntegrityPermissionSeeder --force` (không chạy RolePermissionSeeder); menu `warehouse.report.integrity` tồn tại
- [ ] `.env` production KHÔNG có `INVENTORY_RETURN_CONFIRM_WITHOUT_JE=true`
- [ ] `inventory:integrity-audit` + `inventory:audit --scope=full` trên dữ liệu production mới nhất; xem bảng đối chiếu giá trị (mục 1–4)
- [ ] Manifest repair lập lại từ production → dry-run → Owner duyệt → apply → audit lại
- [ ] `inventory:integrity-cases` dry-run → chạy thật
- [ ] Smoke test (bước 12) + kiểm tra phân quyền (bước 13)

## 4. Rollback (bảo toàn chứng từ phát sinh sau deploy)

- **Không** restore DB backup đè lên production sau khi đã mở lại hoạt động (mất chứng từ mới). Backup chỉ dùng nếu lỗi phát hiện TRƯỚC bước 13 và chưa có giao dịch mới.
- Code: deploy lại commit trước release. Các migration đều tương thích ngược (cột/bảng mới, code cũ bỏ qua) → **giữ nguyên schema**, không chạy `migrate:rollback`:
  - 900253: chỉ bảng báo cáo — có thể rollback an toàn nếu muốn.
  - 900252: `down()` tự chặn nếu đã có phiếu project_transfer.
  - 900251: rollback sẽ xóa avco_qty/avco_amount → mất dữ liệu đảo AVCO chính xác; không rollback.
- Repair AVCO: hoàn tác bằng `inventory:repair-avco-balance --rollback=<log apply>` (chỉ các dòng của lần apply đó).
- Báo cáo mới: không ghi dữ liệu nghiệp vụ → tắt bằng cách gỡ menu/quyền nếu cần.
- **Rủi ro khi quay về code cũ** (trước V1): các lỗi đã sửa sẽ tái xuất hiện — hủy/thu hồi phiếu không đảo AVCO đúng, sửa phiếu làm mất truy vết
  movement, thu hồi phiếu nhập khi đã có phiếu xuất phụ thuộc, re-confirm không sinh JE, xác nhận trả hàng không có JE.
  Vì vậy nếu phải rollback code: **giữ dừng các thao tác ghi kho** (nhập/xuất/chuyển/trả hàng/kiểm kê, thu hồi/hủy/sửa phiếu) cho tới khi có bản sửa
  tiến (forward-fix); chỉ mở các nghiệp vụ không đụng kho. Không mặc định mở lại giao dịch kho trên code cũ.
- Ưu tiên forward-fix (sửa tiếp trên code mới) thay vì rollback; rollback code chỉ khi lỗi chặn vận hành mà chưa sửa được ngay.

## 5. Nghiệp vụ còn hạn chế khi release

- Trả hàng bán/NCC: được lập/lưu nháp nhưng **bị chặn xác nhận** cho tới khi chính sách bút toán A4 được duyệt và JE tự động được bổ sung.
  Phiếu lịch sử đã xác nhận thiếu JE (nếu có trên production) hiển thị là RETURN_WITHOUT_JE để kế toán xử lý.
- Kiểm kê: JE theo AVCO, TK đối ứng theo cấu hình (mặc định 811 thiếu / 711 thừa) — kế toán cần xác nhận TK (chủ đề A4).
- Thu hồi phiếu nhập có phiếu xuất phụ thuộc: bị chặn (A3), chưa có phiếu điều chỉnh giá.
- Kiểm kê thừa khi chưa có giá bình quân: bị chặn.
- Phân quyền theo kho cho thủ kho: hệ thống chưa có dữ liệu gán kho cho người dùng → thủ kho xem mọi kho (chỉ các vấn đề không thuộc kế toán).
- Ô tìm sản phẩm hiển thị 200 mã/trang, nút "Tải thêm" cho trang tiếp.
