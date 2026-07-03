# Plan: Số dư đầu kỳ CCDC/CPTT + Tạm dừng/Tiếp tục phân bổ

Status: APPROVED (2026-07-02) — user quyết định: (1) TK 4111 đúng ý, cho phép điều chỉnh bút toán nếu sai (void + tạo lại); (2) làm luôn trang Đối soát GL cho CPTT; (3) quyền `opening_balance.delete` cấp cho cả Admin và Accounting.

## 0. Research xác nhận thêm

- `accounting_periods` chỉ có cột `year`/`month` (KHÔNG có cột `period`) — xác nhận qua migration `2026_05_28_100002_create_accounting_periods_table.php` + kiểm tra trực tiếp bằng `php artisan tinker` trên Postgres thật.
- **BUG có sẵn (không do task này gây ra)**: `SmallToolAllocationService::checkPeriodNotClosed()` (dòng 223-229) viết `AccountingPeriod::where('period', $period)->first()` — cột `period` không tồn tại. Trên Postgres, query này **luôn throw** `SQLSTATE[42703]: Undefined column`. Test suite (`tests/Feature/SmallToolAllocationTest.php`) vẫn PASS vì chạy trên SQLite — SQLite coi identifier lạ trong `"..."` là chuỗi literal thay vì báo lỗi cột không tồn tại, nên lặng lẽ trả 0 dòng. Kết quả: **`runPeriod()`/`reverseAllocation()` (chạy phân bổ CCDC / đảo phân bổ) đang lỗi hoàn toàn trên môi trường Postgres thật**, bị che giấu bởi test SQLite.
  - Pattern đúng đã dùng nơi khác: `AccountingPeriod::where('year',$y)->where('month',$m)->first()` (xem `AccountingService::checkPeriodOpen()`, `PeriodCloseService`, `FixedAssetDepreciationService`).
- Codebase đã có 3 tiền lệ "opening balance" khác nhau (không dùng bảng polymorphic chung): `ArApOpeningBalance` (bảng riêng, `remaining_amount` lưỡng dấu, JE contra `4111`, `excludeFromPeriodMovement=true`, `destroy()` chặn nếu có `journal_entry_id`), `InventoryOpeningBalance` (AVCO), `OpeningBalanceController` (TK-level chung). Không có tiền lệ bảng polymorphic dùng chung nhiều domain.
- `prepaid_expenses.total_amount/monthly_amount/amortized_amount` = `decimal(15,0)`, **không `unsigned()`**; Postgres không có kiểu unsigned cho decimal (khác MySQL) → **DB đã lưu được số âm ngay bây giờ, không cần ALTER cột**. Rào cản duy nhất là code: `PrepaidExpense::remainingAmount()` có `max(0.0, ...)`.
- `small_tools.original_cost/total_allocated` cũng `decimal(15,2)` không unsigned — tương tự.
- TK `4111` đã dùng làm đối ứng cho bút toán "đầu kỳ" khác (`ArApOpeningBalanceController`) — tái dùng cho nhất quán.
- `void()` bút toán hiện chỉ tồn tại **inline trong `JournalEntryController::void()`**, không phải method dùng lại được của `AccountingService`.
- CPTT **không có** trang GL reconcile tương đương `SmallToolReportController::glReconcile()`.

## 1. Quyết định kiến trúc chính

### 1.1 Lưu trữ Opening Balance — 3 phương án

**A — Bảng `opening_balances` polymorphic**: LOẠI. `SmallTool.total_allocated`/`PrepaidExpense.amortized_amount` đã là nguồn sự thật duy nhất cho "đã phân bổ/còn lại". Thêm bảng riêng lưu `remaining_amount` sẽ tạo **2 nguồn sự thật** phải đồng bộ tay ở Ledger/AllocationSchedule/GlReconcile/`totalRemaining()` — vi phạm DRY, rủi ro lệch dữ liệu.

**B — Flag `is_opening_balance` + tự sinh toàn bộ row `*_allocations` quá khứ ở trạng thái posted không JE**: LOẠI. Phá bất biến ngầm "`status=posted` ⇒ luôn có `journal_entry_id`" mà `reverseAllocation()`, `AllocationSchedule.vue` (cột BT), `glReconcile()` giả định. Sinh row rác không giá trị kế toán thật.

**C (CHỌN) — Mở rộng `SmallTool`/`PrepaidExpense` bằng `is_opening_balance` + đúng 1 JE "đầu kỳ" (theo mẫu `ArApOpeningBalance`) + build lịch chỉ cho kỳ còn lại (không backfill quá khứ)**:
- Không bảng mới, không nguồn sự thật thứ hai — tái dùng `total_allocated`/`periods_allocated` (CCDC), `amortized_amount` (CPTT) làm điểm khởi đầu.
- Chỉ 1 JE ghi nhận **giá trị còn lại** (không phải nguyên giá) vào TK 2422/242 hoặc 142, đối ứng `4111`, `excludeFromPeriodMovement=true`, `referenceType` riêng (`small_tool_opening_balance`/`prepaid_expense_opening_balance`) — giống `ArApOpeningBalanceController`.
- `SmallToolAllocationService::buildSchedule()`: khởi tạo `$accumulated = (float) $tool->total_allocated` (thay vì 0), vòng lặp bắt đầu từ `$i = $tool->periods_allocated` (thay vì 0). Tool bình thường (`total_allocated=0,periods_allocated=0`) → hành vi giữ nguyên 100%, không regression.
- CPTT không có `buildSchedule()` — thêm cột `opening_periods_elapsed` (int, default 0), sửa `amortize()`: `$remainingMonths = $expense->months - $expense->opening_periods_elapsed - $expense->allocatedMonths()`. Record bình thường (`opening_periods_elapsed=0`) → hành vi giữ nguyên.
- Bỏ qua luồng tạo JE "ghi nhận ban đầu" bình thường cho record `is_opening_balance=true`; vì `glReconcile()` cộng dồn mọi JE `posted` trên TK 1531/2422 bất kể `reference_type`, JE đầu kỳ tự động tính đúng — **không cần sửa `glReconcile()`**.

→ Chọn **Phương án C**: diff nhỏ nhất, không module song song, tái dùng tối đa cấu trúc/bất biến hiện có, khớp tiền lệ `ArApOpeningBalance`.

### 1.2 CPTT cho phép remaining âm/dương/0
- Không cần ALTER cột (Postgres decimal không unsigned).
- `PrepaidExpense::remainingAmount()`: bỏ `max(0.0, ...)`.
- `amortize()`: khi `amount < 0`, đảo chiều dòng JE (Dr `account_code`/Cr `expense_account`, dùng `abs($amount)`) — JE trong hệ thống không bao giờ có debit/credit âm.
- CCDC **không** yêu cầu hỗ trợ âm — giữ `max(0,...)` trên `totalRemaining()`, validate `>=0` ở form/controller opening balance CCDC.

### 1.3 Pause/Resume
- Field mới `allocation_status` (string, theo pattern field trạng thái phụ hiện có như `SmallToolAllocation.status`, không PHP enum) trên `small_tools` và `prepaid_expenses`.
- **Không** thêm cột trên bảng allocation con — skip logic dựa hoàn toàn vào `allocation_status`+`pause_effective_period` của bảng cha, không đụng row `pending` đã tồn tại.
- Lịch sử: dùng **`spatie/laravel-activitylog` có sẵn** (đã dùng ở `JournalEntry`, `Customer`, `Invoice`), thêm `LogsActivity` vào `SmallTool`/`PrepaidExpense`, `logOnly(['allocation_status','paused_at','pause_reason','resumed_at'])`. Không tạo bảng lịch sử riêng.

## 2. Migration cụ thể

Sequence tiếp theo sau `2026_07_01_900215` — dùng `2026_07_02_900216` trở đi (xác nhận lại ngày thực tế lúc code).

**`2026_07_02_900216_add_opening_balance_fields_to_small_tools_table.php`**
```php
Schema::table('small_tools', function (Blueprint $table) {
    $table->boolean('is_opening_balance')->default(false)->after('status');
    $table->string('opening_balance_period', 7)->nullable()->after('is_opening_balance');
    $table->string('opening_balance_note', 500)->nullable()->after('opening_balance_period');
});
```
`down()`: `dropColumn(['is_opening_balance','opening_balance_period','opening_balance_note'])`.
JE đầu kỳ tái dùng cột `acquisition_journal_entry_id` có sẵn — không thêm FK mới.

**`2026_07_02_900217_add_opening_balance_fields_to_prepaid_expenses_table.php`**
```php
Schema::table('prepaid_expenses', function (Blueprint $table) {
    $table->boolean('is_opening_balance')->default(false)->after('status');
    $table->string('opening_balance_period', 7)->nullable()->after('is_opening_balance');
    $table->string('opening_balance_note', 500)->nullable()->after('opening_balance_period');
    $table->integer('opening_periods_elapsed')->default(0)->after('opening_balance_note');
    $table->unsignedBigInteger('opening_journal_entry_id')->nullable()->after('opening_periods_elapsed');
    $table->foreign('opening_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
});
```
`down()`: drop FK trước rồi drop cột.

**`2026_07_02_900218_add_allocation_pause_fields_to_small_tools_table.php`**
```php
Schema::table('small_tools', function (Blueprint $table) {
    $table->string('allocation_status', 20)->default('active')->after('status'); // active|paused|completed|not_started
    $table->timestamp('paused_at')->nullable();
    $table->unsignedBigInteger('paused_by')->nullable();
    $table->foreign('paused_by')->references('id')->on('users')->nullOnDelete();
    $table->string('pause_effective_period', 7)->nullable();
    $table->string('pause_reason', 500)->nullable();
    $table->timestamp('resumed_at')->nullable();
    $table->unsignedBigInteger('resumed_by')->nullable();
    $table->foreign('resumed_by')->references('id')->on('users')->nullOnDelete();
});
```
Backfill (idempotent):
```php
DB::table('small_tools')->where('status', 'fully_allocated')->update(['allocation_status' => 'completed']);
DB::table('small_tools')->whereIn('status', ['draft','in_stock'])->where('recognition_method','allocation')->update(['allocation_status' => 'not_started']);
```
`down()`: drop 2 FK trước rồi drop cột.

**`2026_07_02_900219_add_allocation_pause_fields_to_prepaid_expenses_table.php`** — cùng cấu trúc, áp cho `prepaid_expenses`. Backfill: `where('status','fully_amortized')->update(['allocation_status'=>'completed'])`.

Cả 4 migration: additive, nullable/default, có `down()` rollback được — không đụng dữ liệu hiện có ngoài UPDATE backfill an toàn.

## 3. Thay đổi Model

**`SmallTool`**: thêm `LogsActivity` + `getActivitylogOptions()` (`logOnly(['allocation_status','paused_at','pause_reason','resumed_at','resumed_by'])`); thêm fillable cho các field mới; cast `paused_at`/`resumed_at` → `datetime`; method mới `isPaused()`, `isAllocationCompleted()`, `canPauseAllocation()` (`status===Allocating && allocation_status==='active'`), `canResumeAllocation()` (`allocation_status==='paused'`), quan hệ `pausedByUser()`, `resumedByUser()`. `totalRemaining()` giữ nguyên `max(0,...)`.

**`PrepaidExpense`**: thêm `LogsActivity` tương tự; fillable + `opening_journal_entry_id`, `opening_periods_elapsed`; **sửa `remainingAmount()`**: bỏ `max(0.0,...)` → `return (float) $this->total_amount - (float) $this->amortized_amount;`; method mới `isPaused()`, `isAllocationCompleted()`, `canPauseAllocation()`, `canResumeAllocation()`, `pausedByUser()`, `resumedByUser()`, `openingJournalEntry(): BelongsTo`.

`SmallToolAllocation`/`PrepaidExpenseAllocation`: không đổi.

## 4. Thay đổi Service

### 4.1 Fix tiên quyết (bắt buộc trước Part II)
```php
private function checkPeriodNotClosed(string $period): void
{
    [$year, $month] = explode('-', $period);
    $ap = AccountingPeriod::where('year', (int) $year)->where('month', (int) $month)->first();
    if ($ap && in_array($ap->status, ['closed', 'locked'])) {
        throw new \RuntimeException("Kỳ kế toán {$period} đã khóa, không thể tạo/hủy phân bổ.");
    }
}
```
Bug độc lập, cần fix vì pause/resume và opening balance đều phụ thuộc method này.

### 4.2 `SmallToolAllocationService`
- `buildSchedule()`: `$accumulated = (float)($tool->total_allocated ?? 0)`, vòng lặp `for ($i = $tool->periods_allocated ?? 0; $i < $periods; $i++)`. Test hiện có không bị ảnh hưởng (tool bình thường có cả 2 = 0).
- `runPeriod()`: thêm filter `->where('allocation_status','active')`; trong vòng lặp, safety check:
```php
if ($tool->isPaused() && (!$tool->pause_effective_period || $period >= $tool->pause_effective_period)) { $skipped++; continue; }
```
- `previewPeriod()`: cùng filter `allocation_status='active'` (để "Phân bổ hàng tháng" không hiện dòng tạm dừng).
- `pause(SmallTool $tool, ?string $reason)`: guard `canPauseAllocation()`; tính `pause_effective_period` = kỳ hiện tại nếu CHƯA có allocation `posted` cho kỳ hiện tại, else kỳ kế tiếp; `update([...])`, KHÔNG đụng `total_allocated`/`periods_allocated`/status/allocation rows.
- `resume(SmallTool $tool): array`: guard `canResumeAllocation()`; `update(['allocation_status'=>'active',...])`; trả `next_period` = kỳ `pending` sớm nhất chưa `posted`.

### 4.3 `SmallToolJournalService`
Method mới `createOpeningBalanceJournal(SmallTool $tool)`:
```php
public function createOpeningBalanceJournal(SmallTool $tool): \App\Models\JournalEntry
{
    $remaining = $tool->totalRemaining;
    $account   = $tool->pending_account_code ?: '2422';
    $date      = Carbon::createFromFormat('Y-m', $tool->opening_balance_period)->startOfMonth()->subDay();
    $lines = [
        ['account' => $account, 'debit' => $remaining, 'credit' => 0, 'description' => "Số dư đầu kỳ CCDC: {$tool->name}"],
        ['account' => '4111',    'debit' => 0, 'credit' => $remaining, 'description' => "Số dư đầu kỳ CCDC {$tool->opening_balance_period}"],
    ];
    return $this->accounting->post(
        description: "Số dư đầu kỳ CCDC: {$tool->code} - {$tool->name}",
        date: $date, lines: $lines,
        referenceType: 'small_tool_opening_balance', referenceId: $tool->id,
        isAuto: false, journalSourceType: 'small_tool_opening_balance',
        excludeFromPeriodMovement: true, fiscalPeriod: $tool->opening_balance_period,
    );
}
```

### 4.4 `PrepaidExpenseService`
- `amortize()`: sửa công thức kỳ còn lại (trừ cả `opening_periods_elapsed`) + đảo chiều JE khi âm:
```php
$allocatedCount  = $expense->allocatedMonths() + $expense->opening_periods_elapsed;
$remainingMonths = $expense->months - $allocatedCount;
$amount = $remainingMonths === 1 ? (int) $expense->remainingAmount() : (int) $expense->monthly_amount;

if ($expense->isPaused() && (!$expense->pause_effective_period || $period >= $expense->pause_effective_period)) {
    throw new \RuntimeException("Chi phí đang tạm dừng phân bổ từ kỳ {$expense->pause_effective_period}.");
}

$lines = $amount >= 0
    ? [['account'=>$expense->expense_account,'debit'=>$amount,'credit'=>0], ['account'=>$expense->account_code,'debit'=>0,'credit'=>$amount]]
    : [['account'=>$expense->account_code,'debit'=>abs($amount),'credit'=>0], ['account'=>$expense->expense_account,'debit'=>0,'credit'=>abs($amount)]];
```
- `runMonthlyAmortization()`: pre-check paused → phân loại `skipped` riêng (không lẫn vào `errors`).
- Method mới `pause(PrepaidExpense $expense, ?string $reason)` / `resume(PrepaidExpense $expense): array` — effective period logic tương tự CCDC (dựa `allocations()->where('period', nowYm)->exists()`).
- Method mới `createOpeningBalanceJournal(PrepaidExpense $expense): ?JournalEntry` — dùng `tryPost()` (đúng convention CPTT), `sourceType:'prepaid_expense'`, `postingType:'opening_balance'` (khác `recognition`/`amortization`, tránh đụng idempotency key), sign-aware Dr/Cr giống `ArApOpeningBalanceController` (remaining≥0: Dr account_code/Cr 4111; remaining<0: Dr 4111/Cr account_code), `excludeFromPeriodMovement:true`.
- Method mới `createFromOpeningBalance(array $data): PrepaidExpense` — tạo record với `amortized_amount = total_amount - remaining`, `monthly_amount` tính lại từ `remaining/(months-opening_periods_elapsed)`, KHÔNG gọi `create()` gốc (bỏ qua JE ghi nhận ban đầu full amount), gọi `createOpeningBalanceJournal()` thay thế, lưu `opening_journal_entry_id`.

Nếu `PrepaidExpenseService.php` vượt ~200 dòng sau khi sửa, tách phần build JE lines/`createOpeningBalanceJournal()` sang service riêng theo đúng pattern CCDC (`SmallToolJournalService`).

## 5. Controller/Route mới

- `SmallToolOpeningBalanceController`: `create()` GET `small-tools/opening-balance/create` (`opening_balance.create`); `store()` POST `small-tools/opening-balance` — validate `original_cost, allocation_periods, periods_elapsed, remaining_amount(>=0), opening_balance_period, name, category_id...`, tạo `SmallTool::create([...,'is_opening_balance'=>true,'status'=>Allocating,'periods_allocated'=>$periods_elapsed,'total_allocated'=>$original_cost-$remaining])`, gọi `buildSchedule()` rồi `createOpeningBalanceJournal()`, lưu JE id vào `acquisition_journal_entry_id`; `index()` GET danh sách `is_opening_balance=true` (`opening_balance.view`); `destroy()` DELETE — chặn nếu `allocations()->where('status','posted')->exists()`, nếu JE đang posted thì void trước, log activity before/after (`opening_balance.delete`).
- `PrepaidExpenseOpeningBalanceController` — tương tự, route `prepaid-expenses/opening-balance/*`.
- `SmallToolAllocationController` (sửa): thêm `pause(SmallTool $tool, Request $request)`, `resume(SmallTool $tool)` (`allocation.pause`/`allocation.resume`). Route: `POST small-tools/{tool}/allocation/pause`, `POST small-tools/{tool}/allocation/resume`.
- `PrepaidExpenseController` (sửa): thêm `pause()`, `resume()` tương tự. Route: `POST prepaid-expenses/{prepaidExpense}/allocation/pause`, `/resume`.
- `SmallToolReportController::allocationSchedule()`: thêm filter `allocation_status`, thêm `allocation_status`/label vào DTO mỗi tool, thêm `summary` (tổng phân bổ kỳ hiện tại chỉ tính `allocation_status=active` có pending row đúng kỳ).
- Permissions mới trong `RolePermissionSeeder`: `opening_balance.view/create/update/delete`, `allocation.pause/resume` — gán `admin` (toàn bộ), `accounting` (view/create/update/pause/resume; **delete cân nhắc chỉ admin** — xem rủi ro §8.7).

## 6. Vue UI

- Mới: `Pages/Accounting/SmallTools/OpeningBalance/Form.vue` — nguyên giá gốc, tổng số kỳ, số kỳ đã phân bổ (elapsed), số tiền còn lại (auto-tính nhưng cho override do làm tròn hệ cũ), kỳ chuyển đổi, ghi chú.
- Mới: `Pages/Accounting/PrepaidExpenses/OpeningBalance/Form.vue` — tương tự, input số tiền còn lại cho phép **âm** (cần kiểm tra `CurrencyInput.vue` có ràng buộc `min=0` ngầm không trước khi code — xem §8.5).
- Sửa `Reports/AllocationSchedule.vue`: cột "Trạng thái phân bổ" (badge: active=Đang phân bổ/paused=Tạm dừng/completed=Hoàn thành/not_started=Chưa bắt đầu), nút Tạm dừng/Tiếp tục mỗi card (Modal xác nhận, bắt buộc lý do khi pause, hiển thị kỳ kế tiếp khi resume), filter `allocation_status`, summary card tổng phân bổ kỳ hiện tại.
- Sửa `Sidebar.vue`: thêm NavItem "Số dư đầu kỳ CCDC" trong NavGroup "Công cụ dụng cụ" (`opening_balance.create`); NavItem "Số dư đầu kỳ CPTT" trong NavGroup "Chi phí & Giá vốn".
- Sửa `SmallTools/Show.vue`, `PrepaidExpenses/Show.vue`: banner "Số dư đầu kỳ" khi `is_opening_balance=true` (kỳ chuyển đổi, note, link JE); block "Trạng thái phân bổ" + nút Pause/Resume + lịch sử (từ `activitylog`).
- CPTT không có `Reports/AllocationSchedule.vue` tương đương — pause/resume UI đặt tại `PrepaidExpenses/Index.vue` (action column) + `Show.vue`, KHÔNG tạo trang report mới cho CPTT.

## 7. Test case bắt buộc

Files mới: `tests/Feature/SmallToolOpeningBalanceTest.php`, `tests/Feature/PrepaidExpenseOpeningBalanceTest.php`, `tests/Feature/SmallToolAllocationPauseTest.php`, `tests/Feature/PrepaidExpenseAllocationPauseTest.php`.

| # | Test | Input | Expected |
|---|---|---|---|
| 1 | `opening_balance_positive_ccdc_creates_correct_schedule` | original_cost=1,200,000; periods=6; elapsed=2; remaining=800,000 | `total_allocated=400,000`, `periods_allocated=2`, 4 row pending (kỳ 3-6), tổng = 800,000 |
| 2 | `opening_balance_positive_cptt_creates_correct_schedule` | total=1,000,000; months=5; elapsed=2; remaining=600,000 | `amortized_amount=400,000`, `monthly_amount` tính lại từ 600,000/3, `remainingAmount()=600,000` |
| 3 | `opening_balance_negative_cptt_creates_reversed_je` | total=1,000,000; elapsed=4; remaining=-50,000 | `remainingAmount()=-50,000` (không clamp về 0); JE mở đầu Dr 4111/Cr 242 đúng dấu |
| 4 | `amortize_negative_amount_posts_reversed_je` | expense mà kỳ tới có `amount<0` | JE có dòng Dr account_code/Cr expense_account (đảo chiều so với dương) |
| 5 | `build_schedule_last_period_rounding_after_opening_balance` | original_cost=1,000,001; periods=7; elapsed=3 | Tổng amount các row còn lại + total_allocated ban đầu = đúng original_cost tuyệt đối |
| 6 | `pause_prevents_allocation_no_change_to_remaining` | pause trước khi run kỳ hiện tại | `runPeriod()` không tạo JE cho tool; total_allocated/periods_allocated không đổi; row pending không bị xóa/sửa |
| 7 | `resume_allocates_correctly_from_next_pending_period` | pause 2 kỳ rồi resume | `runPeriod()` sau resume tạo đúng JE cho kỳ pending sớm nhất chưa posted; remaining/periods_allocated tiếp tục đúng |
| 8 | `pause_after_current_period_posted_only_effective_next_period` | đã có row posted kỳ hiện tại, sau đó pause | `pause_effective_period`=kỳ kế tiếp; JE/row posted kỳ hiện tại không bị đụng |
| 9 | `gl_reconcile_signed_not_abs` | JE âm từ test #3/#4 | Số dư GL phản ánh đúng dấu âm, không bị abs() che giấu |
| 10 | `check_period_not_closed_uses_year_month_not_period_column` | Regression cho fix §4.1 — `AccountingPeriod` closed đúng year/month | Ném đúng `RuntimeException` "đã khóa" (FAIL trước khi fix) |
| 11 | `destroy_opening_balance_blocked_when_posted_allocation_exists` | opening balance đã có 1 kỳ posted | `destroy()` trả lỗi, không xóa |
| 12 | `destroy_opening_balance_allowed_and_voids_je_when_no_posted_allocation` | opening balance mới, chưa runPeriod lần nào | JE đầu kỳ → voided, record xóa cứng, activity log before/after |

## 8. Rủi ro còn lại / điểm cần user quyết định trước khi code

1. **[NGHIÊM TRỌNG]** `checkPeriodNotClosed()` query sai cột → `runPeriod()`/`reverseAllocation()` luôn lỗi trên Postgres thật (tự kiểm chứng bằng tinker, không suy đoán). Bug độc lập, đề xuất fix cùng lúc (patch 4 dòng, §4.1). **Cần user xác nhận**: tính năng "Chạy phân bổ CCDC hàng tháng" có đang thực sự không dùng được trong production hay không (có thể chưa từng chạy thật).
2. JE đầu kỳ dùng TK `4111` đối ứng (giống `ArApOpeningBalance`) — **cần kế toán xác nhận** đây có đúng TK muốn dùng, hay không cần JE nào cả (nếu giai đoạn chuyển đổi coi là ngoài sổ sách chính thức, có thể bỏ toàn bộ §4.3/4.4 JE, đơn giản hóa đáng kể).
3. `void()` hiện chỉ inline trong `JournalEntryController::void()` — cần chọn: (a) duplicate ~15 dòng logic void trong 2 controller mới, hay (b) refactor ra `AccountingService::void()` dùng chung (rủi ro động code đang chạy tốt, ngoài phạm vi diff nhỏ nhất).
4. CPTT không có trang GL reconcile — yêu cầu "đối soát GL đúng dấu" cho CPTT chỉ kiểm chứng qua test (#9), không UI. Có cần xây trang GL reconcile tối giản cho CPTT trong task này, hay để lại sau?
5. Cần kiểm tra `Components/Shared/CurrencyInput.vue` có ràng buộc `min=0` ngầm không trước khi code form CPTT opening balance (chưa đọc trong research này).
6. `pause_effective_period` dùng `now()` — cần xác nhận logic effective dựa trên **kỳ kế toán mở gần nhất** (`AccountingPeriod`), không phải lịch dương thuần túy.
7. Quyền `opening_balance.delete` (có thể void JE đã posted) — giới hạn `admin` hay cho cả `accounting`?

## Tóm tắt quyết định (để user duyệt)

1. Opening Balance: không bảng polymorphic mới, không backfill row lịch sử giả — mở rộng `SmallTool`/`PrepaidExpense` bằng cờ `is_opening_balance`, chỉ 1 JE ghi nhận giá trị còn lại đối ứng 4111, `buildSchedule()`/`amortize()` sửa tối thiểu để "tiếp tục từ trạng thái hiện tại".
2. CPTT âm: không cần ALTER cột, chỉ bỏ `max(0,...)` + đảo chiều JE khi âm.
3. Pause/Resume: field `allocation_status` mới, lịch sử dùng `laravel-activitylog` có sẵn, không bảng riêng.
4. **Phát hiện quan trọng**: bug có sẵn ở `checkPeriodNotClosed()` khiến chạy phân bổ CCDC lỗi trên Postgres — đề xuất fix kèm task này.
5. 3 điểm cần quyết định trước khi code: TK 4111 đối ứng có đúng không hay bỏ JE hoàn toàn; có xây GL reconcile cho CPTT không; quyền delete opening balance giới hạn thế nào.
