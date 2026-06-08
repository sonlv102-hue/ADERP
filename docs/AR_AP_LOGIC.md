# AR/AP Ledger Logic — Mini ERP

## Tổng quan

Toàn bộ màn hình công nợ phải thu (AR/TK 131) và phải trả (AP/TK 331) đều đi qua **`ArApLedgerService`** (`app/Services/ArApLedgerService.php`). Mọi màn/export mới liên quan đến AR/AP phải inject service này thay vì tự viết query.

---

## ArApLedgerService

**Namespace:** `App\Services\ArApLedgerService`

### Methods

| Method | Trả về | Mô tả |
|---|---|---|
| `receivables(array $filters, bool $onlyOutstanding)` | `Collection` | Invoices (sent/overdue) + OB AR (remaining > 0 nếu outstanding) |
| `payables(array $filters, bool $onlyOutstanding)` | `Collection` | Purchase invoices (valid/partial_paid) + OB AP |
| `agingSummary(Collection $items)` | `array` | Summary + 6 aging buckets |
| `paginate(Collection $items, int $perPage = 30)` | `LengthAwarePaginator` | Tương thích Inertia `{data, links, meta}` |
| `getBucket(int $daysOverdue, float $remaining)` | `string` | Shared bucket label |

### Unified DTO

Mỗi item trong collection trả về có các trường:

```php
[
    'id'            => int,
    'source_type'   => 'invoice' | 'purchase_invoice' | 'opening_balance',
    'code'          => string,           // Mã chứng từ (HDXX, HDMXX, CNDKxxx...)
    'partner_id'    => int,
    'partner_name'  => string,
    'doc_date'      => string,           // Y-m-d
    'due_date'      => string|null,      // Y-m-d
    'due_date_sort' => string,           // Y-m-d, dùng để sort (OB dùng ngày mở đầu kỳ nếu null)
    'total'         => float,
    'paid'          => float,
    'remaining'     => float,
    'days_overdue'  => int,              // âm = chưa đến hạn
    'bucket'        => string,           // 'Chưa đến hạn' | '1–30 ngày' | '31–60 ngày' | '61–90 ngày' | '>90 ngày' | 'Đã thanh toán'
    'status'        => string,
    'status_label'  => string,
    'status_color'  => string,
]
```

### Aging Buckets Summary

`agingSummary()` trả về:

```php
[
    'total_invoiced'  => float,
    'total_paid'      => float,
    'total_remaining' => float,
    'bucket_0'        => float,   // Chưa đến hạn
    'bucket_1_30'     => float,
    'bucket_31_60'    => float,
    'bucket_61_90'    => float,
    'bucket_90_plus'  => float,
]
```

---

## Nguồn dữ liệu

### AR — Công nợ phải thu

| Nguồn | Điều kiện lấy | Model |
|---|---|---|
| `invoices` | status IN ('sent', 'overdue') | `Invoice` |
| `ar_ap_opening_balances` | type = 'ar', remaining_amount > 0 (nếu onlyOutstanding) | `ArApOpeningBalance` |

**Lưu ý:**
- Opening balance AR được đọc từ `ar_ap_opening_balances` (type='ar', customer_id).
- `doc_date` của OB = ngày đầu kỳ (cột `date`).
- `due_date` của OB = cột `due_date` (nullable).
- Khi không có `due_date`, OB được coi là "Chưa đến hạn" — **không** phải quá hạn.
- OB không phải doanh thu — không xuất hiện trong báo cáo doanh thu.

### AP — Công nợ phải trả

| Nguồn | Điều kiện lấy | Model |
|---|---|---|
| `purchase_invoices` | status IN ('valid', 'partial_paid') | `PurchaseInvoice` |
| `ar_ap_opening_balances` | type = 'ap', remaining_amount > 0 (nếu onlyOutstanding) | `ArApOpeningBalance` |

---

## Thanh toán công nợ đầu kỳ

Route: `POST accounting/ar-ap-opening-balance/{id}/pay` — tên: `accounting.ar-ap-opening-balance.pay`

Controller: `ArApOpeningBalanceController::pay()`

### Bút toán AR (Thu tiền khách hàng)

```
Dr 111/112  xxx     "Thu tiền - {code}"
    Cr 131  xxx     "Xóa CN ĐK KH - {code}"  partner_type=customer, partner_id=customer_id
```

### Bút toán AP (Trả tiền nhà cung cấp)

```
Dr 331  xxx     "Xóa CN ĐK NCC - {code}"  partner_type=supplier, partner_id=supplier_id
    Cr 111/112  xxx     "Trả tiền - {code}"
```

Sau khi post JE, `remaining_amount` trên `ar_ap_opening_balances` giảm tương ứng.

---

## Màn hình sử dụng ArApLedgerService

| Màn hình | Controller | Route | onlyOutstanding |
|---|---|---|---|
| Thu nợ khách hàng | `ArCollectionController` | `accounting.ar-collections.index` | true |
| Thanh toán NCC | `ApPaymentController` | `accounting.ap-payments.index` | true |
| Báo cáo AR Aging | `ARAgingController` | `reports.ar.aging` | false |
| Báo cáo AP Aging | `APAgingController` | `reports.ap.aging` | false |
| Export AR Excel | `ARAgingExport` | `reports.ar.aging.export` | false |
| Export AP Excel | `APAgingExport` | `reports.ap.aging.export` | false |

---

## Filters được hỗ trợ

| Filter | AR | AP |
|---|---|---|
| `customer_id` | ✓ | — |
| `supplier_id` | — | ✓ |
| `status` | 'sent'/'overdue' | 'valid'/'partial_paid' |
| `search` | code / partner_name | code / partner_name |
| `date_from` / `date_to` | doc_date | doc_date |
| `bucket` | bucket label | bucket label |

---

## Quy tắc KHÔNG vi phạm

1. **Không double count**: opening_balance và invoice là 2 nguồn riêng; không dùng JE để tính công nợ (JE dùng cho sổ chi tiết, không phải aging).
2. **Không tính OB là doanh thu/chi phí**: OB chỉ là khoản chuyển số dư đầu kỳ, không đi qua TK doanh thu (511) hay chi phí (632).
3. **Không xóa JE đã posted**: Khi hủy thanh toán OB, phải tạo bút toán đảo, không xóa JE gốc.
4. **Sổ chi tiết AR/AP** (`ArDetail`, `ApDetail`) vẫn dùng `journal_entry_lines` query — đúng thiết kế vì chúng hiển thị từng bút toán. Opening balance có JE với `partner_type`/`partner_id` nên vẫn hiển thị đúng trên sổ chi tiết.
5. **TK 131/331 lưỡng tính**: TK 131 debit-normal (bên Nợ là phát sinh tăng AR), TK 331 credit-normal (bên Có là phát sinh tăng AP).

---

## Vue — Quy ước prop và badge

- Props từ controller: `items` (ArCollections, ApPayments) hoặc `rows` (Aging reports) + `summary` + `filters`.
- `items` / `rows.data` là mảng unified DTO.
- Key Vue: `row.source_type + '-' + row.id` (tránh collision khi invoice và OB có cùng id số).
- Badge: OB hiển thị badge amber "Đầu kỳ"; invoice hiển thị link đến show page.
- Link routing: `invoice` → `accounting.invoices.show`; `purchase_invoice` → `purchasing.purchase-invoices.show`; `opening_balance` → không có link, hiển thị text + badge.
