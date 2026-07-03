<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\VoucherListingExport;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Báo cáo Bảng kê chứng từ — liệt kê từng dòng journal_entry_lines đã posted.
 * Nguồn duy nhất: journal_entries + journal_entry_lines. Không lấy từ cash_vouchers,
 * invoices, stock_movements để tránh double-count.
 */
class DocumentChecklistController extends Controller
{
    private const SOURCE_LABELS = [
        'invoice'              => 'Hóa đơn bán hàng',
        'purchase_invoice'     => 'Hóa đơn đầu vào',
        'cash_voucher'         => 'Phiếu thu/chi',
        'stock_entry'          => 'Nhập kho',
        'stock_exit'           => 'Xuất kho',
        'payroll'              => 'Lương',
        'project_expense'      => 'Chi phí dự án',
        'ar_ap_opening_balance'=> 'Số dư đầu kỳ',
        'opening_balance'      => 'Số dư đầu kỳ',
    ];

    private const SOURCE_TYPES = [
        'invoice', 'purchase_invoice', 'cash_voucher',
        'stock_entry', 'stock_exit', 'payroll',
        'project_expense', 'manual',
    ];

    private const SOURCE_ROUTES = [
        'invoice'          => 'sales.invoices.show',
        'purchase_invoice' => 'purchasing.purchase-invoices.show',
        'cash_voucher'     => 'accounting.cash-vouchers.show',
        'stock_entry'      => 'warehouse.stock-entries.show',
        'stock_exit'       => 'warehouse.stock-exits.show',
        'payroll'          => 'hr.payrolls.show',
    ];

    public function index(Request $request): Response
    {
        $filters  = $this->normalizeFilters($request);
        $perPage  = 100;
        $page     = max(1, (int) $request->input('page', 1));

        [$rows, $totals, $isBalanced] = $this->buildReport($filters);

        $total = count($rows);
        $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return Inertia::render('Reports/DocumentChecklist', [
            'rows'        => array_values($paged),
            'totals'      => $totals,
            'isBalanced'  => $isBalanced,
            'total'       => $total,
            'currentPage' => $page,
            'lastPage'    => (int) ceil(max(1, $total) / $perPage),
            'filters'     => $filters,
            'sourceTypes' => self::SOURCE_TYPES,
            'company'     => Setting::getGroup('company'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters  = $this->normalizeFilters($request);
        $from     = str_replace('-', '', $filters['date_from']);
        $to       = str_replace('-', '', $filters['date_to']);
        return Excel::download(new VoucherListingExport($filters), "bang-ke-chung-tu-{$from}-{$to}.xlsx");
    }

    public function exportPdf(Request $request)
    {
        $filters  = $this->normalizeFilters($request);
        [$rows, $totals, $isBalanced] = $this->buildReport($filters);
        $company      = Setting::getGroup('company');
        $signingPlace = Setting::get('report_signing_place');
        $signingDate  = now();
        $from     = str_replace('-', '', $filters['date_from']);
        return Pdf::loadView('pdf.voucher-listing', compact('rows', 'totals', 'isBalanced', 'filters', 'company', 'signingPlace', 'signingDate'))
            ->setPaper('a4', 'landscape')
            ->download("bang-ke-chung-tu-{$from}.pdf");
    }

    // ── Core builder ─────────────────────────────────────────────────────

    public function buildReport(array $filters): array
    {
        $rows        = $this->getRows($filters);
        $totalDebit  = array_sum(array_column($rows, 'debit'));
        $totalCredit = array_sum(array_column($rows, 'credit'));
        $totals      = ['debit' => $totalDebit, 'credit' => $totalCredit];
        $isBalanced  = abs($totalDebit - $totalCredit) < 1;
        return [$rows, $totals, $isBalanced];
    }

    public function getRows(array $filters): array
    {
        // ── 1. Fetch matching journal_entries ────────────────────────────
        $q = DB::table('journal_entries as je')
            ->whereIn('je.status', $this->allowedStatuses($filters))
            ->whereBetween('je.entry_date', [$filters['date_from'], $filters['date_to']]);

        if ($refNo = $filters['ref_no'] ?? null) {
            $q->where('je.code', 'ilike', '%' . $refNo . '%');
        }

        if ($src = $filters['source_type'] ?? null) {
            if ($src === 'manual') {
                $q->whereNull('je.reference_type');
            } else {
                $q->where('je.reference_type', $src);
            }
        }

        $entries = $q->orderBy('je.entry_date')->orderBy('je.id')
            ->select('je.id', 'je.code', 'je.entry_date', 'je.description',
                     'je.reference_type', 'je.reference_id', 'je.is_auto')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $entryIds = $entries->pluck('id');

        // ── 2. Fetch all lines (one query, no N+1) ───────────────────────
        $lineQ = DB::table('journal_entry_lines as jel')
            ->whereIn('jel.journal_entry_id', $entryIds);

        if ($acct = $filters['account_code'] ?? null) {
            $lineQ->where('jel.account_code', $acct);
        }
        if ($pid = $filters['project_id'] ?? null) {
            $lineQ->where('jel.project_id', $pid);
        }

        $allLines = $lineQ
            ->orderBy('jel.journal_entry_id')
            ->orderBy('jel.sort_order')
            ->select('jel.journal_entry_id', 'jel.account_code', 'jel.description as line_desc',
                     'jel.debit', 'jel.credit', 'jel.partner_type', 'jel.partner_id', 'jel.project_id')
            ->get()
            ->groupBy('journal_entry_id');

        // ── 3. Batch resolve partner names ───────────────────────────────
        $partnerNames = $this->resolvePartnerNames($allLines->flatten());

        // ── 4. Build per-line rows ────────────────────────────────────────
        $rows           = [];
        $entryMap       = $entries->keyBy('id');
        $counterFilter  = $filters['counter_account'] ?? null;

        foreach ($entryIds as $jeId) {
            $e     = $entryMap[$jeId] ?? null;
            $lines = $allLines->get($jeId, collect());
            if (!$e || $lines->isEmpty()) {
                continue;
            }

            $debitCodes  = $lines->where('debit',  '>', 0)->pluck('account_code')->unique()->values();
            $creditCodes = $lines->where('credit', '>', 0)->pluck('account_code')->unique()->values();

            $sourceLabel = self::SOURCE_LABELS[$e->reference_type ?? ''] ?? 'Bút toán thủ công';
            $sourceUrl   = $this->sourceUrl($e->reference_type, $e->reference_id ? (int) $e->reference_id : null);
            $objectName  = $this->resolveObjectName($e, $lines, $partnerNames);

            foreach ($lines as $line) {
                $isDebit = (float) $line->debit > 0;
                $counter = $isDebit
                    ? ($creditCodes->count() === 1 ? $creditCodes->first() : 'Nhiều TK')
                    : ($debitCodes->count()  === 1 ? $debitCodes->first()  : 'Nhiều TK');

                if ($counterFilter && $counter !== $counterFilter) {
                    continue;
                }

                $rows[] = [
                    'journal_entry_id' => (int) $e->id,
                    'je_code'          => $e->code,
                    'date'             => $e->entry_date,
                    'object_name'      => $objectName,
                    'description'      => $line->line_desc ?: $e->description,
                    'account_code'     => $line->account_code,
                    'counter_account'  => $counter,
                    'debit'            => (float) $line->debit,
                    'credit'           => (float) $line->credit,
                    'source_label'     => $sourceLabel,
                    'source_url'       => $sourceUrl,
                    'partner_type'     => $line->partner_type,
                    'partner_id'       => $line->partner_id ? (int) $line->partner_id : null,
                ];
            }
        }

        // ── 5. Post-query partner_type / partner_id filter ────────────────
        $partnerType = $filters['partner_type'] ?? null;
        $partnerId   = $filters['partner_id']   ?? null;
        if ($partnerType) {
            $rows = array_values(array_filter($rows, function ($r) use ($partnerType, $partnerId) {
                if ($r['partner_type'] !== $partnerType) {
                    return false;
                }
                if ($partnerId && $r['partner_id'] != $partnerId) {
                    return false;
                }
                return true;
            }));
        }

        return $rows;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function normalizeFilters(Request $request): array
    {
        $year = now()->year;
        return [
            'date_from'       => $request->input('date_from', "{$year}-01-01"),
            'date_to'         => $request->input('date_to',   "{$year}-12-31"),
            'ref_no'          => $request->input('ref_no'),
            'source_type'     => $request->input('source_type'),
            'account_code'    => $request->input('account_code'),
            'counter_account' => $request->input('counter_account'),
            'partner_type'    => $request->input('partner_type'),
            'partner_id'      => $request->input('partner_id') ? (int) $request->input('partner_id') : null,
            'project_id'      => $request->input('project_id') ? (int) $request->input('project_id') : null,
            'include_reversed'=> $request->boolean('include_reversed'),
        ];
    }

    private function allowedStatuses(array $filters): array
    {
        $statuses = ['posted'];
        if ($filters['include_reversed'] ?? false) {
            $statuses[] = 'reversed';
        }
        return $statuses;
    }

    private function sourceUrl(?string $refType, ?int $refId): ?string
    {
        if (!$refType || !$refId || !isset(self::SOURCE_ROUTES[$refType])) {
            return null;
        }
        try {
            return route(self::SOURCE_ROUTES[$refType], $refId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePartnerNames(\Illuminate\Support\Collection $lines): array
    {
        $supplierIds = $lines->where('partner_type', 'supplier')->pluck('partner_id')->filter()->unique();
        $customerIds = $lines->where('partner_type', 'customer')->pluck('partner_id')->filter()->unique();
        $employeeIds = $lines->where('partner_type', 'employee')->pluck('partner_id')->filter()->unique();

        return [
            'supplier' => $supplierIds->isNotEmpty()
                ? DB::table('suppliers')->whereIn('id', $supplierIds)->pluck('name', 'id')->toArray() : [],
            'customer' => $customerIds->isNotEmpty()
                ? DB::table('customers')->whereIn('id', $customerIds)->pluck('name', 'id')->toArray() : [],
            'employee' => $employeeIds->isNotEmpty()
                ? DB::table('employees')->whereIn('id', $employeeIds)->pluck('name', 'id')->toArray() : [],
        ];
    }

    private function resolveObjectName(object $entry, \Illuminate\Support\Collection $lines, array $partnerNames): string
    {
        foreach ($lines as $line) {
            if ($line->partner_type && $line->partner_id) {
                $name = $partnerNames[$line->partner_type][(int) $line->partner_id] ?? null;
                if ($name) {
                    return $name;
                }
            }
        }

        if ($entry->reference_type && $entry->reference_id) {
            return $this->lookupRefEntityName($entry->reference_type, (int) $entry->reference_id);
        }

        return '';
    }

    private function lookupRefEntityName(string $refType, int $refId): string
    {
        return match ($refType) {
            'invoice'          => (string) (DB::table('invoices as i')
                ->join('customers as c', 'c.id', '=', 'i.customer_id')
                ->where('i.id', $refId)->value('c.name') ?? ''),
            'purchase_invoice' => (string) (DB::table('purchase_invoices as pi')
                ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
                ->where('pi.id', $refId)->value('s.name') ?? ''),
            'cash_voucher'     => (string) (DB::table('cash_vouchers')
                ->where('id', $refId)->value('counterparty') ?? ''),
            default            => '',
        };
    }
}
