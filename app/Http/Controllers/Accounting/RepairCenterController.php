<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\CashVoucherStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\CashVoucher;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Services\AccountingService;
use App\Services\AccountingSettings;
use App\Services\CashVoucherService;
use App\Services\SupplierAdvanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class RepairCenterController extends Controller
{
    public function __construct(
        private AccountingService $accounting,
        private CashVoucherService $cashVoucherService,
        private SupplierAdvanceService $advanceService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/RepairCenter/Index', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'checks'    => [
                'A' => $this->checkA(),
                'B' => $this->checkB(),
                'C' => $this->checkC(),
                'D' => $this->checkD(),
                'E' => $this->checkE(),
            ],
        ]);
    }

    /** Check A: Trả trước NCC đã hủy nhưng CashVoucher chưa hủy */
    public function checkA(): array
    {
        $advances = SupplierOpeningAdvance::with('supplier')
            ->where('advance_type', 'prepayment')
            ->where('status', 'cancelled')
            ->get();

        $issues = [];
        foreach ($advances as $advance) {
            $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
                ->where('reference_id', $advance->id)
                ->whereNotIn('status', [CashVoucherStatus::Cancelled->value])
                ->first();

            if ($voucher) {
                $je = JournalEntry::where('reference_type', 'cash_voucher')
                    ->where('reference_id', $voucher->id)
                    ->where('status', 'posted')
                    ->first();

                $issues[] = [
                    'advance_id'      => $advance->id,
                    'advance_ref'     => $advance->reference_no,
                    'supplier'        => $advance->supplier->name,
                    'amount'          => (float) $advance->amount,
                    'opening_date'    => $advance->opening_date->format('d/m/Y'),
                    'voucher_id'      => $voucher->id,
                    'voucher_code'    => $voucher->code,
                    'voucher_status'  => $voucher->status->value,
                    'has_posted_je'   => $je !== null,
                    'je_id'           => $je?->id,
                ];
            }
        }

        return ['label' => 'Trả trước hủy nhưng CashVoucher chưa hủy', 'count' => count($issues), 'items' => $issues];
    }

    /** Check B: Trả trước NCC active nhưng JE/CashVoucher thiếu hoặc lệch */
    public function checkB(): array
    {
        $advances = SupplierOpeningAdvance::with('supplier')
            ->where('advance_type', 'prepayment')
            ->whereIn('status', ['open', 'partially_applied', 'fully_applied'])
            ->get();

        $issues = [];
        foreach ($advances as $advance) {
            $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
                ->where('reference_id', $advance->id)
                ->first();

            $problem = null;
            if (!$voucher) {
                $problem = 'Không tìm thấy CashVoucher';
            } elseif ($voucher->status !== CashVoucherStatus::Confirmed) {
                $problem = "CashVoucher ở trạng thái: {$voucher->status->value} (cần confirmed)";
            } else {
                $je = JournalEntry::where('reference_type', 'cash_voucher')
                    ->where('reference_id', $voucher->id)
                    ->where('status', 'posted')
                    ->first();
                if (!$je) {
                    $problem = 'CashVoucher confirmed nhưng không tìm thấy JE đã posted';
                } else {
                    $advanceAccount = AccountingSettings::get('supplier_advance_account', '331UT');
                    $hasDebitLine = JournalEntryLine::where('journal_entry_id', $je->id)
                        ->where('account_code', $advanceAccount)
                        ->where('debit', '>', 0)
                        ->exists();
                    if (!$hasDebitLine) {
                        $problem = "JE không có dòng Nợ TK {$advanceAccount}";
                    }
                }
            }

            if ($problem) {
                $issues[] = [
                    'advance_id'    => $advance->id,
                    'advance_ref'   => $advance->reference_no,
                    'supplier'      => $advance->supplier->name,
                    'amount'        => (float) $advance->amount,
                    'opening_date'  => $advance->opening_date->format('d/m/Y'),
                    'status'        => $advance->status,
                    'problem'       => $problem,
                    'voucher_id'    => $voucher?->id,
                    'voucher_code'  => $voucher?->code,
                ];
            }
        }

        return ['label' => 'Trả trước active nhưng JE/CashVoucher có vấn đề', 'count' => count($issues), 'items' => $issues];
    }

    /** Check C: Hóa đơn mua có status không khớp với paid_amount + advance_allocated_amount */
    public function checkC(): array
    {
        $invoices = PurchaseInvoice::with('supplier')
            ->whereNotIn('status', ['cancelled', 'pending', 'received', 'reviewing'])
            ->get();

        $issues = [];
        foreach ($invoices as $invoice) {
            $total     = (float) $invoice->total;
            $paid      = (float) $invoice->paid_amount;
            $allocated = (float) $invoice->advance_allocated_amount;
            $totalPaid = $paid + $allocated;

            $expectedStatus = match(true) {
                $totalPaid <= 0       => 'valid',
                $totalPaid >= $total  => 'paid',
                default               => 'partial_paid',
            };

            if ($invoice->status->value !== $expectedStatus) {
                $issues[] = [
                    'invoice_id'        => $invoice->id,
                    'invoice_code'      => $invoice->code,
                    'supplier'          => $invoice->supplier->name,
                    'total'             => $total,
                    'paid_amount'       => $paid,
                    'advance_allocated' => $allocated,
                    'current_status'    => $invoice->status->value,
                    'expected_status'   => $expectedStatus,
                ];
            }
        }

        return ['label' => 'Hóa đơn có status không khớp với số đã thanh toán', 'count' => count($issues), 'items' => $issues];
    }

    /** Check D: Allocation đã reversed nhưng thiếu JE đảo */
    public function checkD(): array
    {
        $allocations = SupplierAdvanceAllocation::with(['advance.supplier', 'invoice'])
            ->where('status', 'reversed')
            ->whereNotNull('journal_entry_id')
            ->whereNull('reversal_entry_id')
            ->get();

        $issues = [];
        foreach ($allocations as $alloc) {
            $originalJe = JournalEntry::find($alloc->journal_entry_id);
            // Kiểm tra xem có JE đảo tham chiếu JE gốc không
            $reversalExists = $originalJe
                && JournalEntry::where('reference_type', 'journal_entry_reversal')
                    ->where('reference_id', $originalJe->id)
                    ->exists();

            if (!$reversalExists) {
                $issues[] = [
                    'allocation_id'    => $alloc->id,
                    'supplier'         => $alloc->advance?->supplier?->name ?? '?',
                    'advance_id'       => $alloc->opening_advance_id,
                    'invoice_code'     => $alloc->invoice?->code,
                    'allocated_amount' => (float) $alloc->allocated_amount,
                    'reversed_at'      => $alloc->reversed_at?->format('d/m/Y H:i'),
                    'je_id'            => $alloc->journal_entry_id,
                ];
            }
        }

        return ['label' => 'Đối trừ đã hủy nhưng thiếu JE đảo', 'count' => count($issues), 'items' => $issues];
    }

    /** Check E: Chi NCC không gắn hóa đơn, đang ghi Nợ 3311 (có thể là trả trước bị nhầm) */
    public function checkE(): array
    {
        $payableAccount = '3311';
        $advanceAccount = AccountingSettings::get('supplier_advance_account', '331UT');

        // Tìm JE có source cash_voucher, có dòng Nợ 3311, không có PurchaseInvoicePayment link
        $rows = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', function ($j) use ($payableAccount) {
                $j->on('jel.journal_entry_id', '=', 'je.id')
                  ->where('jel.account_code', $payableAccount)
                  ->where('jel.debit', '>', 0);
            })
            ->join('cash_vouchers as cv', function ($j) {
                $j->on('je.reference_type', '=', DB::raw("'cash_voucher'"))
                  ->on('je.reference_id', '=', 'cv.id');
            })
            ->leftJoin('purchase_invoice_payments as pip', 'pip.cash_voucher_id', '=', 'cv.id')
            ->leftJoin('suppliers as s', 'cv.supplier_id', '=', 's.id')
            ->whereNull('pip.id')
            ->where('cv.status', CashVoucherStatus::Confirmed->value)
            ->where('je.status', 'posted')
            ->select([
                'je.id as je_id',
                'je.code as je_code',
                'je.entry_date',
                'cv.id as voucher_id',
                'cv.code as voucher_code',
                'cv.amount',
                'cv.supplier_id',
                's.name as supplier_name',
                'jel.debit',
            ])
            ->limit(100)
            ->get();

        $issues = $rows->map(fn ($r) => [
            'je_id'          => $r->je_id,
            'je_code'        => $r->je_code,
            'entry_date'     => $r->entry_date,
            'voucher_id'     => $r->voucher_id,
            'voucher_code'   => $r->voucher_code,
            'supplier_id'    => $r->supplier_id,
            'supplier_name'  => $r->supplier_name ?? '(không rõ NCC)',
            'amount'         => (float) $r->amount,
            'proposed_reclass' => "Nợ {$advanceAccount} / Có {$payableAccount}",
        ])->values()->all();

        return ['label' => "Chi NCC ghi Nợ {$payableAccount} không gắn hóa đơn (có thể là trả trước nhầm TK)", 'count' => count($issues), 'items' => $issues];
    }

    /** Sửa Check A: Cancel CashVoucher cho advance đã hủy */
    public function repairCancelledAdvance(Request $request)
    {
        $this->authorize('accounting.manage');
        $data = $request->validate([
            'advance_id' => ['required', 'exists:supplier_opening_advances,id'],
            'reason'     => ['required', 'string', 'max:500'],
        ]);

        $advance = SupplierOpeningAdvance::findOrFail($data['advance_id']);

        if ($advance->status !== 'cancelled') {
            return back()->withErrors(['general' => 'Advance này chưa bị hủy.']);
        }

        $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
            ->where('reference_id', $advance->id)
            ->whereNotIn('status', [CashVoucherStatus::Cancelled->value])
            ->first();

        if (!$voucher) {
            return back()->withErrors(['general' => 'Không tìm thấy CashVoucher cần cancel.']);
        }

        try {
            DB::transaction(function () use ($voucher, $data) {
                $this->cashVoucherService->cancel($voucher);
                Log::info("RepairCenter: CashVoucher #{$voucher->id} cancelled for cancelled advance #{$data['advance_id']}. Reason: {$data['reason']} by user " . auth()->id());
            });
        } catch (\Exception $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('success', "Đã cancel CashVoucher {$voucher->code} và đảo JE liên quan.");
    }

    /** Sửa Check C: Tính lại status hóa đơn */
    public function repairInvoiceStatus(Request $request)
    {
        $this->authorize('accounting.manage');
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:purchase_invoices,id'],
        ]);

        $invoice   = PurchaseInvoice::findOrFail($data['invoice_id']);
        $total     = (float) $invoice->total;
        $paid      = (float) $invoice->paid_amount;
        $allocated = (float) $invoice->advance_allocated_amount;
        $totalPaid = $paid + $allocated;

        $status = match(true) {
            $invoice->status->value === 'cancelled' => $invoice->status->value,
            $totalPaid <= 0                          => 'valid',
            $totalPaid >= $total                     => 'paid',
            default                                  => 'partial_paid',
        };

        $invoice->update(['status' => $status]);
        Log::info("RepairCenter: Invoice #{$invoice->id} status recalculated to {$status} by user " . auth()->id());

        return back()->with('success', "Đã cập nhật trạng thái hóa đơn {$invoice->code} → {$status}.");
    }

    /** Reclass: Tạo JE điều chỉnh Nợ 331UT / Có 3311 */
    public function reclass(Request $request)
    {
        $this->authorize('accounting.manage');
        $data = $request->validate([
            'je_id'  => ['required', 'exists:journal_entries,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'date'   => ['required', 'date'],
        ]);

        $sourceJe       = JournalEntry::findOrFail($data['je_id']);
        $advanceAccount = AccountingSettings::get('supplier_advance_account', '331UT');
        $payableAccount = '3311';

        // Kiểm tra TK tồn tại
        if (!AccountCode::where('code', $advanceAccount)->where('is_detail', true)->exists()) {
            return back()->withErrors(['general' => "Tài khoản {$advanceAccount} không tồn tại hoặc không phải TK chi tiết."]);
        }

        try {
            $je = DB::transaction(function () use ($data, $sourceJe, $advanceAccount, $payableAccount) {
                $lines = [
                    [
                        'account'     => $advanceAccount,
                        'debit'       => (int) $data['amount'],
                        'credit'      => 0,
                        'description' => "Phân loại lại trả trước NCC: {$data['reason']}",
                    ],
                    [
                        'account'     => $payableAccount,
                        'debit'       => 0,
                        'credit'      => (int) $data['amount'],
                        'description' => "Phân loại lại trả trước NCC: {$data['reason']}",
                    ],
                ];

                return $this->accounting->tryPost(
                    "Reclass trả trước NCC từ {$sourceJe->code}: {$data['reason']}",
                    Carbon::parse($data['date']),
                    $lines,
                    'supplier_advance_reclass',
                    $sourceJe->id,
                    'ap'
                );
            });

            if (!$je) {
                return back()->withErrors(['general' => 'Không thể tạo bút toán điều chỉnh. Kiểm tra TK kế toán.']);
            }

            Log::info("RepairCenter: Reclass JE #{$je->id} created from source JE #{$sourceJe->id}. Reason: {$data['reason']} by user " . auth()->id());
        } catch (\Exception $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('success', "Đã tạo bút toán điều chỉnh {$advanceAccount}/{$payableAccount}.");
    }

    /** Sửa hàng loạt status hóa đơn sai (Check C — bulk) */
    public function repairAllInvoiceStatuses(Request $request)
    {
        $this->authorize('accounting.manage');

        $fixed = 0;
        PurchaseInvoice::whereNotIn('status', ['cancelled', 'pending', 'received', 'reviewing'])
            ->chunk(100, function ($invoices) use (&$fixed) {
                foreach ($invoices as $invoice) {
                    $total     = (float) $invoice->total;
                    $paid      = (float) $invoice->paid_amount;
                    $allocated = (float) $invoice->advance_allocated_amount;
                    $totalPaid = $paid + $allocated;

                    $expectedStatus = match(true) {
                        $totalPaid <= 0      => 'valid',
                        $totalPaid >= $total => 'paid',
                        default              => 'partial_paid',
                    };

                    if ($invoice->status->value !== $expectedStatus) {
                        $invoice->update(['status' => $expectedStatus]);
                        $fixed++;
                    }
                }
            });

        Log::info("RepairCenter: bulk invoice status repair fixed {$fixed} invoices by user " . auth()->id());

        return back()->with('success', "Đã cập nhật lại trạng thái cho {$fixed} hóa đơn.");
    }
}
