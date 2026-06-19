<?php

namespace App\Services;

use App\Models\ArApOpeningBalance;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ArApLedgerService — lõi công nợ AR/AP thống nhất.
 *
 * Tất cả màn thu nợ, thanh toán NCC, báo cáo aging, export đều dùng service này
 * để tránh viết query riêng lẻ sai lệch nhau.
 *
 * Nguồn dữ liệu:
 *   - AR: invoices (sent/overdue) + ar_ap_opening_balances (type=ar, remaining>0)
 *   - AP: purchase_invoices (valid/partial_paid) + ar_ap_opening_balances (type=ap, remaining>0)
 *
 * DTO thống nhất cho mỗi dòng công nợ:
 *   id, source_type, code, partner_id, partner_name, doc_date, due_date,
 *   due_date_sort, total, paid, remaining, days_overdue, bucket,
 *   status, status_label, status_color
 */
class ArApLedgerService
{
    // ─── Public API ──────────────────────────────────────────────────────

    /**
     * Lấy danh sách phải thu AR.
     *
     * Filters: search, date_from, date_to, customer_id, status ('sent'|'overdue')
     * $onlyOutstanding = true  → chỉ remaining > 0  (màn Thu nợ)
     * $onlyOutstanding = false → tất cả kể cả đã thu (màn Báo cáo aging)
     */
    public function receivables(array $filters = [], bool $onlyOutstanding = true): Collection
    {
        $invoices = $this->loadSalesInvoices($filters, $onlyOutstanding);
        $opening  = $this->loadOpeningBalances('ar', $filters, $onlyOutstanding);
        return $opening->concat($invoices)->sortBy('due_date_sort')->values();
    }

    /**
     * Lấy danh sách phải trả AP.
     *
     * Filters: search, date_from, date_to, supplier_id, status ('valid'|'partial_paid')
     * $onlyOutstanding = true  → chỉ remaining > 0  (màn Thanh toán NCC)
     * $onlyOutstanding = false → tất cả kể cả đã trả (màn Báo cáo aging)
     */
    public function payables(array $filters = [], bool $onlyOutstanding = true): Collection
    {
        $invoices = $this->loadPurchaseInvoices($filters, $onlyOutstanding);
        $opening  = $this->loadOpeningBalances('ap', $filters, $onlyOutstanding);
        return $opening->concat($invoices)->sortBy('due_date_sort')->values();
    }

    /**
     * Tính summary / aging buckets từ toàn bộ collection (trước khi phân trang).
     */
    public function agingSummary(Collection $items): array
    {
        $s = [
            'total_invoiced'       => 0.0,
            'total_paid'           => 0.0,
            'total_advance_offset' => 0.0,
            'total_remaining'      => 0.0,
            'bucket_0'             => 0.0,
            'bucket_1_30'          => 0.0,
            'bucket_31_60'         => 0.0,
            'bucket_61_90'         => 0.0,
            'bucket_90_plus'       => 0.0,
        ];

        foreach ($items as $item) {
            $s['total_invoiced']       += $item['total'];
            $s['total_paid']           += $item['paid'];
            $s['total_advance_offset'] += $item['advance_offset'] ?? 0;
            $s['total_remaining']      += $item['remaining'];
            match ($item['bucket']) {
                'Chưa đến hạn' => $s['bucket_0']       += $item['remaining'],
                '1–30 ngày'    => $s['bucket_1_30']    += $item['remaining'],
                '31–60 ngày'   => $s['bucket_31_60']   += $item['remaining'],
                '61–90 ngày'   => $s['bucket_61_90']   += $item['remaining'],
                '>90 ngày'     => $s['bucket_90_plus'] += $item['remaining'],
                default        => null,
            };
        }

        return $s;
    }

    /**
     * Phân trang thủ công cho một Collection.
     * Trả về LengthAwarePaginator tương thích Inertia (data/links/meta).
     */
    public function paginate(Collection $items, int $perPage = 30): LengthAwarePaginator
    {
        $page    = (int) request()->input('page', 1);
        $total   = $items->count();
        $sliced  = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Xác định aging bucket từ số ngày quá hạn và số tiền còn lại.
     */
    public function getBucket(int $daysOverdue, float $remaining): string
    {
        if ($remaining <= 0) return 'Đã thanh toán';
        if ($daysOverdue <= 0) return 'Chưa đến hạn';
        if ($daysOverdue <= 30) return '1–30 ngày';
        if ($daysOverdue <= 60) return '31–60 ngày';
        if ($daysOverdue <= 90) return '61–90 ngày';
        return '>90 ngày';
    }

    // ─── Private loaders ─────────────────────────────────────────────────

    private function loadSalesInvoices(array $filters, bool $onlyOutstanding): Collection
    {
        $search     = $filters['search']      ?? null;
        $dateFrom   = $filters['date_from']   ?? null;
        $dateTo     = $filters['date_to']     ?? null;
        $customerId = $filters['customer_id'] ?? null;
        $status     = $filters['status']      ?? null; // 'sent' | 'overdue'

        $rows = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when($onlyOutstanding, fn ($q) => $q->whereIn('invoices.status', ['sent', 'overdue']))
            ->when(!$onlyOutstanding && $status, fn ($q) => $q->where('invoices.status', $status))
            ->when($onlyOutstanding && $status, fn ($q) => $q->where('invoices.status', $status))
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('invoices.code', 'ilike', "%{$search}%")
                       ->orWhere('customers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('invoices.issue_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('invoices.issue_date', '<=', $dateTo))
            ->when($customerId, fn ($q) => $q->where('invoices.customer_id', $customerId))
            ->select([
                'invoices.id',
                'invoices.code',
                'invoices.customer_id',
                'customers.name as partner_name',
                'invoices.issue_date as doc_date',
                'invoices.due_date',
                'invoices.total',
                'invoices.status',
                DB::raw("COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = invoices.id), 0) as paid"),
                'invoices.advance_allocated_amount',
            ])
            ->orderByDesc('invoices.id')
            ->get();

        return $rows->map(function ($r) {
            $total         = (float) $r->total;
            $paid          = (float) $r->paid;
            $advanceOffset = (float) ($r->advance_allocated_amount ?? 0);
            $remaining     = max(0.0, $total - $paid - $advanceOffset);
            $daysOverdue   = $this->computeDaysOverdue($r->due_date, $remaining);
            $bucket        = $this->getBucket($daysOverdue, $remaining);

            return [
                'id'             => $r->id,
                'source_type'    => 'invoice',
                'code'           => $r->code,
                'partner_id'     => $r->customer_id,
                'partner_name'   => $r->partner_name,
                'doc_date'       => $r->doc_date,
                'due_date'       => $r->due_date,
                'due_date_sort'  => $r->due_date ?? '9999-12-31',
                'total'          => $total,
                'paid'           => $paid,
                'advance_offset' => $advanceOffset,
                'remaining'      => $remaining,
                'days_overdue'   => $daysOverdue,
                'bucket'         => $bucket,
                'status'         => $r->status,
                'status_label'   => $this->invoiceLabel($r->status),
                'status_color'   => $this->invoiceColor($r->status),
            ];
        });
    }

    private function loadPurchaseInvoices(array $filters, bool $onlyOutstanding): Collection
    {
        $search     = $filters['search']      ?? null;
        $dateFrom   = $filters['date_from']   ?? null;
        $dateTo     = $filters['date_to']     ?? null;
        $supplierId = $filters['supplier_id'] ?? null;
        $status     = $filters['status']      ?? null; // 'valid' | 'partial_paid'

        $rows = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNotIn('purchase_invoices.status', ['draft', 'cancelled'])
            ->when($onlyOutstanding, fn ($q) => $q->whereIn('purchase_invoices.status', ['valid', 'partial_paid']))
            ->when($status, fn ($q) => $q->where('purchase_invoices.status', $status))
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('purchase_invoices.code', 'ilike', "%{$search}%")
                       ->orWhere('suppliers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('purchase_invoices.invoice_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('purchase_invoices.invoice_date', '<=', $dateTo))
            ->when($supplierId, fn ($q) => $q->where('purchase_invoices.supplier_id', $supplierId))
            ->select([
                'purchase_invoices.id',
                'purchase_invoices.code',
                'purchase_invoices.supplier_id',
                'suppliers.name as partner_name',
                'purchase_invoices.invoice_date as doc_date',
                'purchase_invoices.due_date',
                'purchase_invoices.total',
                'purchase_invoices.paid_amount as paid',
                'purchase_invoices.advance_allocated_amount',
                'purchase_invoices.status',
            ])
            ->orderByDesc('purchase_invoices.id')
            ->get();

        return $rows->map(function ($r) {
            $total         = (float) $r->total;
            $paid          = (float) $r->paid;
            $advanceOffset = (float) ($r->advance_allocated_amount ?? 0);
            $remaining     = max(0.0, $total - $paid - $advanceOffset);
            $daysOverdue   = $this->computeDaysOverdue($r->due_date, $remaining);
            $bucket        = $this->getBucket($daysOverdue, $remaining);

            return [
                'id'             => $r->id,
                'source_type'    => 'purchase_invoice',
                'code'           => $r->code,
                'partner_id'     => $r->supplier_id,
                'partner_name'   => $r->partner_name,
                'doc_date'       => $r->doc_date,
                'due_date'       => $r->due_date,
                'due_date_sort'  => $r->due_date ?? '9999-12-31',
                'total'          => $total,
                'paid'           => $paid,
                'advance_offset' => $advanceOffset,
                'remaining'      => $remaining,
                'days_overdue'   => $daysOverdue,
                'bucket'         => $bucket,
                'status'         => $r->status,
                'status_label'   => $this->piLabel($r->status),
                'status_color'   => $this->piColor($r->status),
            ];
        });
    }

    private function loadOpeningBalances(string $type, array $filters, bool $onlyOutstanding): Collection
    {
        $isAr      = $type === 'ar';
        $search    = $filters['search']                                      ?? null;
        $dateFrom  = $filters['date_from']                                   ?? null;
        $dateTo    = $filters['date_to']                                     ?? null;
        $partnerId = $isAr ? ($filters['customer_id'] ?? null) : ($filters['supplier_id'] ?? null);
        $status    = $filters['status']                                      ?? null;

        $query = ArApOpeningBalance::query()
            ->with($isAr ? ['customer'] : ['supplier'])
            ->where('type', $type)
            ->when($onlyOutstanding, fn ($q) => $q->where('remaining_amount', '>', 0))
            ->when($partnerId,       fn ($q) => $q->where($isAr ? 'customer_id' : 'supplier_id', $partnerId))
            ->when($search, fn ($q) =>
                $q->where(function ($q2) use ($isAr, $search) {
                    $q2->where('invoice_ref', 'ilike', "%{$search}%")
                       ->orWhereHas($isAr ? 'customer' : 'supplier', fn ($q3) =>
                           $q3->where('name', 'ilike', "%{$search}%")
                       );
                })
            )
            ->when($dateFrom, fn ($q) => $q->where(function ($q2) use ($dateFrom) {
                $q2->whereNull('invoice_date')->orWhere('invoice_date', '>=', $dateFrom);
            }))
            ->when($dateTo, fn ($q) => $q->where(function ($q2) use ($dateTo) {
                $q2->whereNull('invoice_date')->orWhere('invoice_date', '<=', $dateTo);
            }))
            ->orderBy('due_date')
            ->orderBy('id');

        $today = now()->startOfDay();

        return $query->get()->map(function ($ob) use ($isAr, $status, $today) {
            $total     = (float) $ob->amount;
            $remaining = (float) $ob->remaining_amount;
            $paid      = max(0.0, round($total - $remaining, 2));

            // Tính trạng thái cho opening balance
            if ($isAr) {
                // AR: sent = chưa quá hạn, overdue = quá hạn
                $isOverdue    = $ob->due_date && $ob->due_date->lt($today) && $remaining > 0;
                $derivedStatus = $isOverdue ? 'overdue' : 'sent';
                $statusLabel  = $isOverdue ? 'Quá hạn (ĐK)' : 'Đầu kỳ';
                $statusColor  = $isOverdue ? 'red' : 'yellow';
            } else {
                // AP: valid = chưa trả gì, partial_paid = trả một phần
                $derivedStatus = $paid > 0 ? 'partial_paid' : 'valid';
                $statusLabel  = $paid > 0 ? 'TT một phần (ĐK)' : 'Đầu kỳ';
                $statusColor  = $paid > 0 ? 'yellow' : 'indigo';
            }

            // Áp dụng status filter
            if ($status && $derivedStatus !== $status) {
                return null;
            }

            $daysOverdue = $this->computeDaysOverdue($ob->due_date?->toDateString(), $remaining);
            $bucket      = $this->getBucket($daysOverdue, $remaining);
            $partner     = $isAr ? $ob->customer : $ob->supplier;
            $partnerId   = $isAr ? $ob->customer_id : $ob->supplier_id;
            $code        = $ob->invoice_ref ?? ('OPENING-' . strtoupper($isAr ? 'AR' : 'AP') . '-' . $ob->id);

            return [
                'id'             => $ob->id,
                'source_type'    => 'opening_balance',
                'code'           => $code,
                'partner_id'     => $partnerId,
                'partner_name'   => $partner?->name ?? '—',
                'doc_date'       => $ob->invoice_date?->toDateString(),
                'due_date'       => $ob->due_date?->toDateString(),
                'due_date_sort'  => $ob->due_date?->toDateString() ?? '9999-12-31',
                'total'          => $total,
                'paid'           => $paid,
                'advance_offset' => 0.0,
                'remaining'      => $remaining,
                'days_overdue'   => $daysOverdue,
                'bucket'         => $bucket,
                'status'         => $derivedStatus,
                'status_label'   => $statusLabel,
                'status_color'   => $statusColor,
            ];
        })->filter()->values();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function computeDaysOverdue(?string $dueDate, float $remaining): int
    {
        if ($remaining <= 0 || !$dueDate) return 0;
        return max(0, (int) now()->diffInDays($dueDate, false) * -1);
    }

    private function invoiceLabel(string $status): string
    {
        return match($status) {
            'sent'    => 'Đã gửi',
            'overdue' => 'Quá hạn',
            'paid'    => 'Đã thanh toán',
            default   => $status,
        };
    }

    private function invoiceColor(string $status): string
    {
        return match($status) {
            'sent'    => 'blue',
            'overdue' => 'red',
            'paid'    => 'green',
            default   => 'gray',
        };
    }

    private function piLabel(string $status): string
    {
        return match($status) {
            'valid'        => 'Hợp lệ',
            'partial_paid' => 'TT một phần',
            'paid'         => 'Đã thanh toán',
            default        => $status,
        };
    }

    private function piColor(string $status): string
    {
        return match($status) {
            'valid'        => 'indigo',
            'partial_paid' => 'yellow',
            'paid'         => 'green',
            default        => 'gray',
        };
    }
}
