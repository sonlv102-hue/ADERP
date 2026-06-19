<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Fund;
use App\Services\ArApLedgerService;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ArCollectionController extends Controller
{
    public function __construct(
        private ArApLedgerService $ledger,
        private CustomerAdvanceService $advanceService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['customer_id', 'status']);

        // Sum available advance per customer for the "advance_available" column
        $advByCustomer = DB::table('customer_opening_advances')
            ->whereIn('status', ['open', 'partially_applied'])
            ->where('remaining_amount', '>', 0)
            ->selectRaw('customer_id, SUM(remaining_amount) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $items = $this->ledger->receivables($filters, onlyOutstanding: true)
            ->map(fn ($item) => [
                'id'                => $item['id'],
                'source_type'       => $item['source_type'],
                'code'              => $item['code'],
                'customer_id'       => $item['partner_id'],
                'customer'          => $item['partner_name'],
                'issue_date'        => $item['doc_date'] ? date('d/m/Y', strtotime($item['doc_date'])) : '—',
                'due_date'          => $item['due_date'] ? date('d/m/Y', strtotime($item['due_date'])) : null,
                'total'             => $item['total'],
                'amount_paid'       => $item['paid'],
                'amount_due'        => $item['remaining'],
                'advance_available' => (float) ($advByCustomer[$item['partner_id']] ?? 0),
                'status'            => $item['status'],
                'status_label'      => $item['status_label'],
                'status_color'      => $item['status_color'],
            ])
            ->values();

        return Inertia::render('Accounting/ArCollections/Index', [
            'items'     => $items,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'code']),
            'funds'     => Fund::where('is_active', true)->orderBy('type')->orderBy('name')
                ->get(['id', 'name', 'type', 'account_code']),
            'statuses'  => [
                ['value' => 'sent',    'label' => 'Chưa thu'],
                ['value' => 'overdue', 'label' => 'Quá hạn'],
            ],
            'filters'   => $filters,
        ]);
    }

    public function customerAdvances(Request $request): JsonResponse
    {
        $customerId = $request->integer('customer_id');
        if (!$customerId) {
            return response()->json([]);
        }

        $advances = $this->advanceService->getAvailable($customerId);

        return response()->json($advances->map(fn ($a) => [
            'id'                 => $a->id,
            'advance_date'       => $a->advance_date,
            'advance_type'       => $a->advance_type,
            'advance_type_label' => $a->typeLabel(),
            'reference_no'       => $a->reference_no,
            'amount'             => (float) $a->amount,
            'remaining_amount'   => (float) $a->remaining_amount,
            'notes'              => $a->notes,
        ])->values());
    }
}
