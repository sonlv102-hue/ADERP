# Investigation: Giá xuất kho khi "xuất trước nhập" (AVCO) — mã Aruba/J9150D

**Trạng thái: HOÀN THÀNH.** Điều tra xác nhận AVCO tính đúng (không sửa
`AvcoService`/`StockService`); phần hiển thị báo cáo đã fix theo hướng đề
xuất #2+#3 (xem "Đóng task" bên dưới), đã deploy VPS tại commit `97e9a53`.
Task này tách ra từ P0 báo cáo Sổ chi tiết Nhập-Xuất-Tồn
(`plans/260729-warehouse-inventory-transaction-report/plan.md`).

## Nguồn gốc

Khi xây báo cáo Sổ chi tiết Nhập-Xuất-Tồn, phát hiện 6 mã hàng có badge
"Đã từng âm kho" (`was_negative`), với đặc điểm: `value_out` của dòng xuất
khớp CHÍNH XÁC với `value_in`/avg cost của (các) dòng nhập "sau đó" theo
ngày chứng từ. Nghi vấn ban đầu: giá xuất kho bị tính sai/backfill khi xuất
trước khi có tồn.

## Phương pháp điều tra

Đọc source code `AvcoService.php` + `StockService.php` (toàn bộ luồng
`confirmEntry()`/`confirmExit()`/`recordEntry()`/`recordExit()`), sau đó
truy vết dữ liệu thật: so sánh **ngày chứng từ** (`entry_date`/`exit_date`)
với **thời điểm ghi nhận thực tế trong hệ thống** (`created_at`/`updated_at`
của `stock_entries`/`stock_exits`) cho toàn bộ 6 mã hàng bị flag.

## Kết quả — trả lời 5 câu hỏi

### 1. Phương pháp tính giá xuất kho hiện tại và thời điểm hệ thống chạy tính giá

- AVCO (bình quân gia quyền), lưu trong `inventory_balances`
  (per `product_id`+`warehouse_id`), cập nhật bởi `AvcoService`.
- Giá được tính **tại thời điểm CONFIRM** (`StockService::confirmEntry()`
  gọi `AvcoService::recordEntry()`; `confirmExit()` gọi `recordExit()`),
  theo **thứ tự CONFIRM thực tế trong hệ thống** — KHÔNG theo `entry_date`/
  `exit_date` (ngày chứng từ do người dùng tự chọn khi tạo phiếu).
- `recordEntry`: `new_avg = (old_value + qty×unit_cost) / (old_qty+qty)`.
- `recordExit`: dùng `avg_cost` hiện có trong `inventory_balances` tại đúng
  thời điểm confirm, trừ qty khỏi `qty_on_hand`/`value_on_hand` (floor tại
  0 — không bao giờ âm), **không** đổi `avg_cost`.
- Nếu chưa có balance khi exit confirm: `seedBalanceFromEntries()` tính
  avg_cost từ **tất cả** `stock_entry_items` đã `status='confirmed'` tại
  thời điểm đó (không lọc theo `entry_date`) — nếu chưa có entry nào từng
  confirmed → `throw RuntimeException`, chặn hoàn toàn việc xuất khi
  thực sự chưa từng có hàng.

### 2. Cách xử lý khi chứng từ xuất có ngày trước chứng từ nhập

Hệ thống **không kiểm tra/so sánh** `exit_date` với `entry_date`. Guard duy
nhất là số lượng tồn tại **thời điểm CONFIRM** (không phải ngày chứng từ):
non-project exit check `SUM(stock_movements.quantity)`; project-scoped exit
check `inventory_balances.qty_on_hand` / `project_inventory_lots`.

**Dữ liệu thật (trace `stock_movements` + `stock_entries`/`stock_exits`):**

| Chứng từ | Ngày chứng từ | Tạo lúc | Confirm lúc |
|---|---|---|---|
| NK-0003 | 2026-01-05 | 2026-06-17 06:09 | 2026-06-17 06:09 |
| NK-0004 | 2026-04-08 | 2026-06-17 06:09 | 2026-06-17 06:09 |
| NK-0009 | 2026-03-12 | 2026-06-17 06:11 | 2026-06-17 06:11 |
| NK-0012 | 2026-03-11 | 2026-06-17 06:12 | 2026-06-17 06:12 |
| **XK-0013** | **2026-01-10** | **2026-06-27 02:43** | **2026-06-29 13:23** |

XK-0013 có **ngày chứng từ** (10/01/2026) sớm hơn NK-0009/NK-0012 (tháng 3),
nhưng **thực tế được confirm** ngày 29/06/2026 — SAU khi toàn bộ 4 phiếu
nhập trên đã confirm (17/06/2026). Tại thời điểm confirm thực sự, hệ thống
ĐÃ có avg_cost hợp lệ. Đây **không phải** trường hợp "xuất trước nhập" theo
thời gian hệ thống — chỉ là ngày chứng từ được nhập lùi lại (backdated) so
với thời điểm nhập liệu thật.

### 3. Có tự động tính lại giá xuất sau khi phát sinh nhập bổ sung không?

**Không.** `recordExit()` chỉ đọc avg_cost đúng 1 lần tại thời điểm gọi, ghi
cố định vào `stock_movements.unit_cost`/`amount` và `stock_exit_items
.source_cost`/`total_cost`. Đã rà toàn bộ `AvcoService.php` +
`StockService.php` — không có hàm nào recompute/backfill giá cho các exit
đã confirm trước đó khi có entry mới. Nếu 1 exit thực sự confirm trước khi
có entry nào (avg_cost=0, sản phẩm không bật `allow_zero_cost`) thì bị chặn
hoàn toàn ngay lúc confirm — không có đường nào để ghi sai rồi "tự sửa sau".

### 4. Ảnh hưởng tới giá trị tồn kho, giá vốn và bút toán kế toán

Với 6 mã Aruba: vì XK-0013 confirm SAU các entry liên quan (dù ngày chứng
từ ngược lại), avg_cost dùng để tính giá vốn là avg_cost **hợp lệ thật sự**
tại đúng thời điểm confirm. Ví dụ kiểm chứng — HPE Aruba-250W: avg_cost xuất
= (2×4.725.000 + 2×5.092.592,5) / 4 = 4.908.796,25 — khớp chính xác công
thức AVCO bình quân 2 lô nhập đã confirm trước đó. **Không phát hiện sai
lệch giá vốn/bút toán kế toán thực tế** cho 6 mã này qua dữ liệu đã trace.

Ảnh hưởng thật sự nằm ở **báo cáo Sổ chi tiết Nhập-Xuất-Tồn (mới)**: báo cáo
sắp xếp dòng theo ngày chứng từ (`entry_date`/`exit_date`) đúng chuẩn S10-DN.
Khi ngày chứng từ bị nhập ngược thứ tự so với thời điểm confirm thực tế,
"tồn chạy" (running balance) tính trên thứ tự hiển thị đó cho ra số âm tạm
thời → badge "Đã từng âm kho" bị gắn — dù thực tế vận hành/kế toán KHÔNG
có thời điểm nào tồn kho thực sự âm. Đây là **rủi ro hiểu nhầm số liệu báo
cáo**, không phải sai lệch kế toán.

### 5. Danh sách chứng từ/mã hàng thực tế bị ảnh hưởng

Toàn bộ 6 mã bị flag "was_negative" đều bắt nguồn từ **CÙNG 1 sự kiện nhập
liệu backdated duy nhất** (1 phiếu xuất, 4 phiếu nhập liên quan) — không
phải nhiều sự cố rời rạc:

- **Phiếu xuất:** XK-0013 (ngày CT 10/01/2026, tạo 27/06/2026, confirm 29/06/2026)
- **Phiếu nhập liên quan:** NK-0003, NK-0004, NK-0009, NK-0012 (ngày CT
  rải từ 05/01 đến 08/04/2026, tất cả confirm 17/06/2026)
- **Mã hàng:** Aruba 6100 24G (id=2), Aruba 6100 48G (id=4),
  Aruba J9150D (id=65), Aruba J9151E (id=66), Aruba SFP56 DAC (id=1),
  HPE Aruba-250W (id=67)

## Kết luận

- **AVCO/StockService tính đúng** — không có lỗi thuật toán giá vốn cho 6
  mã hàng này. Không cần sửa `AvcoService`/`StockService`.
- **Rủi ro thật nằm ở báo cáo mới**: cột "Đã từng âm kho"/`is_negative`
  trong `InventoryTransactionGroupBuilder` tính running-balance theo thứ tự
  **ngày chứng từ** (đúng chuẩn sổ sách S10-DN) — với dữ liệu có backdating
  (nhập liệu lịch sử hàng loạt), thứ tự này có thể không khớp thứ tự confirm
  thực tế, khiến báo cáo cảnh báo "âm kho" giả (false positive) cho các
  trường hợp vốn dĩ chưa từng âm kho thật.
- Đây là quyết định **thiết kế báo cáo**, không phải bug kế toán — để người
  dùng quyết định hướng xử lý (xem Đề xuất bên dưới) trước khi code bất kỳ
  thay đổi nào.

## Đề xuất hướng xử lý báo cáo (chưa làm, chờ quyết định)

1. **Giữ nguyên** (chấp nhận rủi ro hiểu nhầm với dữ liệu backdated cũ) —
   phù hợp nếu backdating chỉ là sự kiện nhập liệu lịch sử 1 lần (2026-06),
   không tái diễn với giao dịch mới.
2. Thêm ghi chú/tooltip trên badge "Đã từng âm kho": làm rõ đây là tính theo
   **ngày chứng từ**, không phải thời điểm hệ thống ghi nhận thực tế.
3. Đổi tiêu chí sort/tính `is_negative` sang thứ tự **xác nhận thực tế**
   (`stock_movements.created_at`/`sm.id`) thay vì ngày chứng từ — nhưng sẽ
   phá vỡ chuẩn trình bày S10-DN (sổ kế toán phải theo ngày chứng từ), cần
   cân nhắc kỹ, có thể phải tách 2 cột ngày riêng.

## Not in Scope (task này)

- Không sửa `AvcoService.php` / `StockService.php` — không tìm thấy lỗi cần sửa.
- Không chạy `inventory:reconcile-balances` hay bất kỳ lệnh ghi dữ liệu nào.

## Đóng task — Hướng xử lý báo cáo đã chọn và triển khai (2026-07-29)

Người dùng chọn kết hợp **đề xuất #2 (tooltip)** + một phần **#3** (không đổi
tiêu chí sort hiển thị theo ngày chứng từ — vẫn giữ chuẩn S10-DN — nhưng bổ
sung thêm mốc thời gian đáng tin cậy để cảnh báo/giải thích thay vì đổi hẳn
thứ tự sort).

**Commit:** `caabc55` (đổi tên badge + tooltip chung, dùng tạm `updated_at`
chứng từ) → `97e9a53` (fix nguồn dữ liệu, đổi tên field, hoàn thiện test).

### Thay đổi cuối cùng

- Badge `was_negative`: **"Đã từng âm kho"** → **"Âm theo ngày chứng từ"**
  (label; `code` nội bộ giữ nguyên `was_negative` để không phá vỡ test/API
  cũ). `negative_ending` giữ nguyên **"Âm kho cuối kỳ"** vì phản ánh đúng
  tồn âm thật tại cuối kỳ.
- Tooltip chung trên badge: giải thích số tồn tính theo ngày chứng từ, cảnh
  báo có thể là false positive nếu chứng từ bị nhập/xác nhận lùi ngày.
- Mỗi dòng giao dịch: thêm field `estimated_confirmed_at` + `backdated_note`
  (tooltip trên ô số chứng từ) khi **mốc ghi nhận trong hệ thống muộn hơn
  ngày chứng từ quá `BACKDATE_THRESHOLD_DAYS = 3` ngày** (const, dễ chỉnh).
- **Nguồn timestamp:** dùng `stock_movements.created_at` (KHÔNG dùng
  `stock_entries`/`stock_exits.updated_at` như bản nháp đầu) — vì đã kiểm
  chứng `updated_at` của chứng từ có thể bị thao tác không liên quan làm
  trôi thêm sau khi confirm (VD: `StockExitDateService::updateExitDate()`
  sửa `exit_date` chỉ đổi `stock_exits`/`journal_entries`/
  `project_wip_entries`, KHÔNG đổi `stock_movements`, nhưng vẫn bump
  `stock_exits.updated_at`). Ngược lại, `stock_movements.created_at` được
  ghi đúng 1 lần tại thời điểm tạo movement (bên trong `confirmEntry()`/
  `confirmExit()`) và grep toàn bộ `app/Services/*.php` xác nhận không có
  nơi nào update lại bản ghi `StockMovement` đã tồn tại — mốc đáng tin cậy
  hơn nhiều so với `updated_at` của chứng từ.
- Tên field: `doc_confirmed_at` (bản nháp, khẳng định quá mức) →
  `estimated_confirmed_at` (không khẳng định chắc chắn đây là "thời điểm
  xác nhận" thật, vì hệ thống không có cột `confirmed_at` riêng).

### Test coverage (TC13–TC15, trong `InventoryTransactionReportTest.php`)

- **TC13** — mô phỏng đúng case thật XK-0013 (nhập ghi nhận cùng ngày CT,
  xuất ghi nhận 5 tháng sau ngày CT dù ngày CT xuất trước ngày CT nhập):
  đúng badge "Âm theo ngày chứng từ" + `backdated_note` chỉ gắn vào dòng
  xuất, không lan sang dòng nhập bình thường.
- **TC14** — ngưỡng: đúng 3 ngày (72h tròn) → KHÔNG cảnh báo; hơn 3 ngày
  (72h + 1h) → CÓ cảnh báo.
- **TC15** — sửa chứng từ sau khi confirm (mô phỏng
  `StockExitDateService` bump `updated_at` của `stock_exits` mà không đổi
  `stock_movements`) → kết luận `backdated_note` KHÔNG bị thay đổi, vì dựa
  vào `stock_movements.created_at`, không phải `updated_at` chứng từ.

### Kết quả kiểm thử + deploy

- `php artisan test`: **846/846 pass**.
- `npm run build`: pass.
- Deploy VPS (`97e9a53`): push → build Docker → backup DB
  (`/var/backups/mini_erp/20260729_130411_97e9a53.sql`) → migrate (nothing
  to migrate) → deploy smoke test **12/12 pass**, log 200/200 lỗi trước/sau
  (không lỗi mới).
- Đối chiếu trực tiếp trên dữ liệu production (qua tinker trong container,
  không tạo user debug, không đăng nhập browser): cả 6 mã Aruba/J9150D hiện
  đúng badge "Âm theo ngày chứng từ" + `backdated_note` đúng nội dung.

### Còn lại (không chặn đóng task)

- Kiểm tra UI qua trình duyệt bằng tài khoản quản trị thật — ghi nhận là
  bước nghiệm thu bổ sung, thực hiện khi người dùng tiện đăng nhập.
- Task audit toàn hệ thống (`plans/260729-doc-date-vs-confirm-audit/plan.md`)
  giữ ở backlog, ưu tiên thấp, chưa triển khai.

**Task điều chỉnh cảnh báo âm theo ngày chứng từ: ĐÓNG.**
