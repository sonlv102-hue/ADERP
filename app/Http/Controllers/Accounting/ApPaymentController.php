<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Supplier;
use App\Services\ArApLedgerService;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApPaymentController extends Controller
{
    public function __construct(
        private ArApLedgerService $ledger,
        private SupplierAdvanceService $advanceService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['supplier_id', 'status']);

        // Sum available advance per supplier for the "advance_available" column
        $advBySupplier = DB::table('supplier_opening_advances')
            ->whereIn('status', ['open', 'partially_applied'])
            ->where('remaining_amount', '>', 0)
            ->selectRaw('supplier_id, SUM(remaining_amount) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $items = $this->ledger->payables($filters, onlyOutstanding: true)
            ->map(fn ($item) => [
                'id'                => $item['id'],
                'source_type'       => $item['source_type'],
                'code'              => $item['code'],
                'supplier_id'       => $item['partner_id'],
                'supplier'          => $item['partner_name'],
                'invoice_date'      => $item['doc_date'] ? date('d/m/Y', strtotime($item['doc_date'])) : '—',
                'due_date'          => $item['due_date'] ? date('d/m/Y', strtotime($item['due_date'])) : null,
                'total'             => $item['total'],
                'amount_paid'       => $item['paid'],
                'amount_due'        => $item['remaining'],
                'advance_available' => (float) ($advBySupplier[$item['partner_id']] ?? 0),
                'status'            => $item['status'],
                'status_label'      => $item['status_label'],
                'status_color'      => $item['status_color'],
            ])
            ->values();

        return Inertia::render('Accounting/ApPayments/Index', [
            'items'     => $items,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'funds'     => Fund::where('is_active', true)->orderBy('type')->orderBy('name')
                ->get(['id', 'name', 'type', 'account_code']),
            'statuses'  => [
                ['value' => 'valid',        'label' => 'Hợp lệ / Chưa TT'],
                ['value' => 'partial_paid', 'label' => 'TT một phần'],
            ],
            'filters'   => $filters,
        ]);
    }

    public function advances(Request $request): JsonResponse
    {
        $supplierId = $request->integer('supplier_id');
        if (!$supplierId) {
            return response()->json([]);
        }

        $advances = $this->advanceService->getAvailable($supplierId);

        return response()->json($advances->map(fn ($a) => [
            'id'                 => $a->id,
            'opening_date'       => $a->opening_date,
            'advance_type'       => $a->advance_type,
            'advance_type_label' => match ($a->advance_type) {
                'opening_balance' => 'Số dư đầu kỳ',
                'prepayment'      => 'Trả trước',
                default           => $a->advance_type,
            },
            'reference_no'       => $a->reference_no,
            'amount'             => (float) $a->amount,
            'remaining_amount'   => (float) $a->remaining_amount,
            'notes'              => $a->notes,
        ])->values());
    }
}
