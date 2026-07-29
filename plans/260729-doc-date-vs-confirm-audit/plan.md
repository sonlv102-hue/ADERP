# Task (backlog, ưu tiên thấp): Audit chứng từ bị nhập/xác nhận lùi ngày toàn hệ thống

**Trạng thái: CHƯA BẮT ĐẦU.** Chỉ là task backlog — tạo theo yêu cầu người
dùng sau khi điều tra AVCO (`plans/260729-avco-negative-stock-cost-investigation/plan.md`)
và fix terminology báo cáo (commit `caabc55`). Không code ngay.

## Bối cảnh

Điều tra AVCO phát hiện 1 sự kiện nhập liệu backdated (XK-0013 + 4 phiếu
nhập liên quan, xem plan AVCO) khiến báo cáo Sổ chi tiết Nhập-Xuất-Tồn hiện
cảnh báo "Âm theo ngày chứng từ" dù tồn kho thực tế chưa từng âm. Đã xử lý
hiển thị cho case cụ thể này. Câu hỏi còn mở: **còn case tương tự nào khác
trong toàn hệ thống không** — cả về hiển thị báo cáo lẫn khả năng ảnh hưởng
giá vốn thật.

## Mục tiêu

Command audit **chỉ đọc** (read-only), xuất danh sách + thống kê, **không
tự động sửa dữ liệu**. Đặt tên theo convention hiện có, ví dụ:
`php artisan inventory:audit-backdated-documents [--product=] [--from=] [--to=] [--threshold-days=3]`.

## Phạm vi kiểm tra (3 mục theo yêu cầu người dùng)

1. **Chứng từ có `doc_date` khác đáng kể so với thời điểm confirm** — quét
   `stock_entries`/`stock_exits`/`stock_transfers`/`sales_returns`/
   `purchase_returns`/`inventory_counts` (7 loại nguồn của `stock_movements`,
   theo `InventoryTransactionReportService::addSourceJoins()`), so
   `entry_date`/`exit_date`/... với `updated_at` (xấp xỉ thời điểm confirm —
   xem hạn chế bên dưới). Ngưỡng mặc định giống báo cáo: > 3 ngày.

2. **Mã hàng bị âm theo ngày chứng từ nhưng KHÔNG âm theo thứ tự confirm** —
   với mỗi sản phẩm+kho, dựng 2 running balance song song: (a) sắp theo
   `doc_date` (như báo cáo hiện tại — cách `InventoryTransactionGroupBuilder`
   đang làm), (b) sắp theo thứ tự confirm thực tế (`stock_movements.created_at`/
   `id`, vì movement được tạo đúng lúc confirm — không có độ trễ như chứng
   từ header). Flag các sản phẩm có (a) âm nhưng (b) không âm — đây là
   "cảnh báo giả" giống case Aruba.

3. **Mã hàng có khả năng sai giá vốn thực tế** — khác với mục 2 (chỉ là hiển
   thị), mục này cần tìm case **AVCO thực sự dùng giá sai**: ví dụ exit
   confirm khi `inventory_balances` chưa có (avg_cost=0, phải bật
   `allow_zero_cost` mới qua được) — đây là dấu hiệu duy nhất theo code hiện
   tại (`AvcoService::recordExit()`) có thể gây giá vốn = 0 sai thực sự.
   Quét `stock_movements` có `unit_cost = 0` nhưng `product.allow_zero_cost
   = false` (nếu tồn tại — cần double-check có lọt qua guard không, có thể
   là dấu hiệu dữ liệu cũ trước khi guard được thêm).

## Hạn chế đã biết (ghi rõ khi báo cáo kết quả)

- `stock_entries`/`stock_exits`... **không có cột `confirmed_at` riêng** —
  dùng `updated_at` làm proxy (như báo cáo Sổ chi tiết đang làm). Nếu chứng
  từ bị sửa (edit) sau khi confirm, `updated_at` sẽ lệch thêm — cần loại trừ
  hoặc ghi chú rõ trong output.
- Mục 3 hiện chỉ có 1 tín hiệu code-based rõ ràng (allow_zero_cost bypass).
  Các sai lệch giá vốn tinh vi hơn (VD: nhiều exit/entry xen kẽ phức tạp)
  cần trace thủ công per-case như đã làm với 6 mã Aruba — không thể tự động
  hoá hoàn toàn trong 1 lần audit đầu tiên.

## Output đề xuất

- Bảng tổng hợp: số chứng từ bị backdated theo ngưỡng, số sản phẩm bị
  "cảnh báo giả" (mục 2), số dòng nghi ngờ sai giá vốn (mục 3).
- Danh sách chi tiết từng dòng (mã chứng từ, ngày CT, ngày confirm xấp xỉ,
  số ngày lệch, sản phẩm liên quan) — theo pattern các audit command hiện
  có (`inventory:audit`, `journal-entries:audit-project-dimensions`).
- **Không** có flag `--apply`/`--fix` trong lần đầu — đây là audit thuần,
  quyết định sửa gì (nếu có) để task riêng sau khi có kết quả.

## Not in Scope

- Không tự động sửa `stock_movements`/`inventory_balances`/JE.
- Không đổi thuật toán `AvcoService`.
- Không giới hạn thời gian hoàn thành — pick up khi có capacity (ưu tiên thấp).
