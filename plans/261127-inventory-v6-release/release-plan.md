# Inventory V1–V6 — Kế hoạch triển khai production, repair và rollback

Trạng thái: **CHỜ OWNER DUYỆT (PRODUCTION_RELEASE_GATE)**. Không có bước nào dưới đây đã chạy trên VPS.
Tài liệu này không chứa số liệu production; mọi con số phải lấy từ audit trên dữ liệu production mới nhất tại thời điểm deploy.

## 1. Phạm vi release

| Commit (web_erp) | Nội dung |
|---|---|
| 9acedcd (V1) | Vòng đời chứng từ kho đối xứng, AVCO-safe |
| 06a0e98 (V3) | Audit toàn bộ tồn kho, chặn thu hồi khi có phụ thuộc, trả hàng theo giá gốc, `inventory:repair-avco-balance` |
| 8c05e17 (V4) | Giữ ID dòng hàng khi sửa phiếu (truy vết movement), tìm sản phẩm form xuất |
| V6 (commit này) | Báo cáo Kiểm soát sai lệch tồn kho và kế toán, phân trang ô tìm sản phẩm, cảnh báo trả hàng chưa có JE, sửa fixture PG |

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
14. Mở lại hoạt động; gắn phương án kế toán (`inventory:integrity-cases <file ngoài Git>` sau khi lập lại theo số liệu production) và bàn giao danh sách cho kế toán.

## 4. Rollback (bảo toàn chứng từ phát sinh sau deploy)

- **Không** restore DB backup đè lên production sau khi đã mở lại hoạt động (mất chứng từ mới). Backup chỉ dùng nếu lỗi phát hiện TRƯỚC bước 13 và chưa có giao dịch mới.
- Code: deploy lại commit trước release. Các migration đều tương thích ngược (cột/bảng mới, code cũ bỏ qua) → **giữ nguyên schema**, không chạy `migrate:rollback`:
  - 900253: chỉ bảng báo cáo — có thể rollback an toàn nếu muốn.
  - 900252: `down()` tự chặn nếu đã có phiếu project_transfer.
  - 900251: rollback sẽ xóa avco_qty/avco_amount → mất dữ liệu đảo AVCO chính xác; không rollback.
- Repair AVCO: hoàn tác bằng `inventory:repair-avco-balance --rollback=<log apply>` (chỉ các dòng của lần apply đó).
- Báo cáo mới: không ghi dữ liệu nghiệp vụ → tắt bằng cách gỡ menu/quyền nếu cần.

## 5. Nghiệp vụ còn hạn chế khi release

- Trả hàng bán/NCC: cập nhật tồn kho + AVCO đúng, **chưa sinh JE** (chờ chính sách A4) — hệ thống cảnh báo khi xác nhận và lập vấn đề RETURN_WITHOUT_JE.
- Thu hồi phiếu nhập có phiếu xuất phụ thuộc: bị chặn (A3), chưa có phiếu điều chỉnh giá.
- Kiểm kê thừa khi chưa có giá bình quân: bị chặn.
- Phân quyền theo kho cho thủ kho: hệ thống chưa có dữ liệu gán kho cho người dùng → thủ kho xem mọi kho (chỉ các vấn đề không thuộc kế toán).
- Ô tìm sản phẩm hiển thị 200 mã/trang, nút "Tải thêm" cho trang tiếp.
