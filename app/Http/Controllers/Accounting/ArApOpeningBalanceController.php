<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ArApOpeningBalance;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ArApOpeningBalanceController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function index(Request $request): Response
    {
        $type   = $request->input('type', 'ar');
        $period = $request->input('period', now()->format('Y-m'));

        $query = ArApOpeningBalance::with(['customer', 'supplier', 'creator'])
            ->where('type', $type)
            ->where('period', $period)
            ->orderBy('id');

        return Inertia::render('Accounting/ArApOpeningBalance/Index', [
            'balances' => $query->get()->map(fn ($b) => [
                'id'               => $b->id,
                'type'             => $b->type,
                'party_name'       => $b->customer?->name ?? $b->supplier?->name,
                'party_code'       => $b->customer?->code ?? $b->supplier?->code,
                'invoice_ref'      => $b->invoice_ref,
                'invoice_date'     => $b->invoice_date?->format('d/m/Y'),
                'due_date'         => $b->due_date?->format('d/m/Y'),
                'amount'           => (float) $b->amount,
                'remaining_amount' => (float) $b->remaining_amount,
                'note'             => $b->note,
                'has_je'           => (bool) $b->journal_entry_id,
            ]),
            'filters' => ['type' => $type, 'period' => $period],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/ArApOpeningBalance/Form', [
            'customers' => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'defaultType' => $request->query('type', 'ar'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type'   => 'required|in:ar,ap',
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'items'  => 'required|array|min:1',
            'items.*.customer_id'      => 'nullable|exists:customers,id',
            'items.*.supplier_id'      => 'nullable|exists:suppliers,id',
            'items.*.invoice_ref'      => 'nullable|string|max:100',
            'items.*.invoice_date'     => 'nullable|date',
            'items.*.due_date'         => 'nullable|date',
            'items.*.amount'           => 'required|numeric',
            'items.*.remaining_amount' => 'required|numeric',
            'items.*.note'             => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            $isAr  = $data['type'] === 'ar';
            // Ngày JE = ngày cuối tháng trước kỳ, để báo cáo tháng kỳ tính đúng opening (query < dateFrom)
            $date  = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->subDay();
            $lines = [];
            // Track running Dr/Cr totals on 131/331 to compute 411 contra
            $totalDr = 0.0;
            $totalCr = 0.0;

            foreach ($data['items'] as $item) {
                $remaining = (float) $item['remaining_amount'];
                $abs       = round(abs($remaining), 2);

                // AR lưỡng tính:
                //   remaining >= 0 → Dư Nợ TK 131 → Dr 131
                //   remaining <  0 → Dư Có TK 131 → Cr 131
                // AP lưỡng tính:
                //   remaining >= 0 → Dư Có TK 331 → Cr 331
                //   remaining <  0 → Dư Nợ TK 331 → Dr 331
                if ($isAr) {
                    $lineDr = $remaining >= 0 ? $abs : 0;
                    $lineCr = $remaining <  0 ? $abs : 0;
                } else {
                    $lineDr = $remaining <  0 ? $abs : 0;
                    $lineCr = $remaining >= 0 ? $abs : 0;
                }

                $totalDr += $lineDr;
                $totalCr += $lineCr;

                ArApOpeningBalance::create([
                    'type'             => $data['type'],
                    'period'           => $data['period'],
                    'customer_id'      => $item['customer_id'] ?? null,
                    'supplier_id'      => $item['supplier_id'] ?? null,
                    'invoice_ref'      => $item['invoice_ref'] ?? null,
                    'invoice_date'     => $item['invoice_date'] ?? null,
                    'due_date'         => $item['due_date'] ?? null,
                    'amount'           => (float) $item['amount'],
                    'remaining_amount' => $remaining,
                    'note'             => $item['note'] ?? null,
                    'created_by'       => auth()->id(),
                ]);

                if ($abs > 0) {
                    if ($isAr) {
                        $partyName      = Customer::find($item['customer_id'])?->name ?? '?';
                        $partyAccount   = '131';
                    } else {
                        $partySupplier  = Supplier::find($item['supplier_id']);
                        $partyName      = $partySupplier?->name ?? '?';
                        $partyAccount   = $partySupplier
                            ? $partySupplier->getPayableAccount()
                            : throw new \RuntimeException("Nhà cung cấp không tồn tại (id={$item['supplier_id']}).");
                    }

                    $lines[] = [
                        'account'      => $partyAccount,
                        'debit'        => $lineDr,
                        'credit'       => $lineCr,
                        'description'  => ($isAr ? 'Phải thu ĐK' : 'Phải trả ĐK') . " {$partyName}" .
                            ($item['invoice_ref'] ? " HĐ {$item['invoice_ref']}" : ''),
                        // partner_type/partner_id cần thiết cho ArDetail/ApDetail query theo đối tác
                        'partner_type' => $isAr ? 'customer' : 'supplier',
                        'partner_id'   => $isAr ? ($item['customer_id'] ?: null) : ($item['supplier_id'] ?: null),
                    ];
                }
            }

            if ($lines) {
                // TK 411 đối ứng — giữ cân bằng bút toán
                // Tổng Dr 131/331 > Tổng Cr 131/331 → Cr 411 phần chênh
                // Tổng Cr 131/331 > Tổng Dr 131/331 → Dr 411 phần chênh
                $dr411 = $totalCr > $totalDr ? round($totalCr - $totalDr, 2) : 0;
                $cr411 = $totalDr > $totalCr ? round($totalDr - $totalCr, 2) : 0;

                // TK 4111 — leaf account (TK 411 là tổng hợp, không ghi trực tiếp)
                $lines[] = [
                    'account'     => '4111',
                    'debit'       => $dr411,
                    'credit'      => $cr411,
                    'description' => ($isAr ? 'Công nợ phải thu' : 'Công nợ phải trả') . " đầu kỳ {$data['period']}",
                ];

                $this->accounting->post(
                    description: ($isAr ? 'Công nợ phải thu' : 'Công nợ phải trả') . " đầu kỳ {$data['period']}",
                    date: $date,
                    lines: $lines,
                    referenceType: ArApOpeningBalance::class,
                    referenceId: 0,
                    isAuto: false,
                    notes: null,
                    journalSourceType: $isAr ? 'receivable_opening' : 'payable_opening',
                    excludeFromPeriodMovement: true,
                    fiscalPeriod: $data['period'],
                );
            }
        });

        return redirect()->route('accounting.ar-ap-opening-balance.index')
            ->with('success', 'Đã nhập số dư đầu kỳ công nợ thành công.');
    }

    public function pay(Request $request, ArApOpeningBalance $arApOpeningBalance): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $isAr      = $arApOpeningBalance->type === 'ar';
        $remaining = (float) $arApOpeningBalance->remaining_amount;

        if ($remaining <= 0) {
            return back()->with('error', 'Công nợ đầu kỳ này đã được thanh toán đầy đủ.');
        }

        $data = $request->validate([
            'amount'       => ['required', 'numeric', 'min:0.01', 'max:' . $remaining],
            'payment_date' => ['required', 'date'],
            'method'       => ['required', 'string', 'in:cash,bank_transfer,other'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($arApOpeningBalance, $data, $isAr) {
            $amount       = round((float) $data['amount'], 2);
            $newRemaining = round((float) $arApOpeningBalance->remaining_amount - $amount, 2);
            $arApOpeningBalance->update(['remaining_amount' => $newRemaining]);

            $cashAccount = match($data['method']) {
                'bank_transfer', 'bank' => '1121',
                default => '1111',
            };
            $docRef = $arApOpeningBalance->invoice_ref
                ? "CN ĐK {$arApOpeningBalance->invoice_ref}"
                : "CN ĐK #{$arApOpeningBalance->id}";

            if ($isAr) {
                // AR: Thu tiền → Dr 111/112 / Cr 131
                $lines = [
                    ['account' => $cashAccount, 'debit' => $amount, 'credit' => 0,
                     'description' => "Thu tiền - {$docRef}"],
                    ['account' => '131', 'debit' => 0, 'credit' => $amount,
                     'description'  => "Xóa CN ĐK KH - {$docRef}",
                     'partner_type' => 'customer',
                     'partner_id'   => $arApOpeningBalance->customer_id],
                ];
                $desc = "Thu công nợ đầu kỳ {$docRef}";
            } else {
                // AP: Trả tiền NCC → Dr 3311/3312/... / Cr 1111/1121
                $arApOpeningBalance->loadMissing('supplier');
                $payableAccount = $arApOpeningBalance->supplier->getPayableAccount();
                $lines = [
                    ['account' => $payableAccount, 'debit' => $amount, 'credit' => 0,
                     'description'  => "Xóa CN ĐK NCC - {$docRef}",
                     'partner_type' => 'supplier',
                     'partner_id'   => $arApOpeningBalance->supplier_id],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => $amount,
                     'description' => "Trả tiền - {$docRef}"],
                ];
                $desc = "Thanh toán công nợ đầu kỳ {$docRef}";
            }

            $this->accounting->tryPost(
                $desc,
                Carbon::parse($data['payment_date']),
                $lines,
                'ar_ap_opening_balance', $arApOpeningBalance->id, 'collection'
            );
        });

        $msg = $isAr ? 'Đã ghi nhận thu tiền công nợ đầu kỳ.' : 'Đã ghi nhận thanh toán công nợ đầu kỳ.';
        return back()->with('success', $msg);
    }

    public function destroy(ArApOpeningBalance $arApOpeningBalance): RedirectResponse
    {
        if ($arApOpeningBalance->journal_entry_id) {
            return back()->with('error', 'Không thể xóa dòng đã có bút toán kế toán.');
        }
        $arApOpeningBalance->delete();
        return back()->with('success', 'Đã xóa.');
    }
}
