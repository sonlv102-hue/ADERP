<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\FixedAssetStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\FixedAssetDepreciationService;
use App\Services\FixedAssetJournalService;
use App\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    public function __construct(
        protected FixedAssetService $service,
        protected FixedAssetDepreciationService $depreciationService,
    ) {}

    public function index(Request $request): Response
    {
        $query = FixedAsset::with(['category', 'supplier'])
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->department, fn ($q) => $q->where('department', 'ilike', '%' . $request->department . '%'))
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($q2) => $q2->where('code', 'ilike', "%{$s}%")
                    ->orWhere('name', 'ilike', "%{$s}%"));
            })
            ->orderByDesc('id');

        $assets = $query->paginate(50)->through(fn (FixedAsset $fa) => [
            'id'                       => $fa->id,
            'code'                     => $fa->code,
            'name'                     => $fa->name,
            'category_name'            => $fa->category?->name ?? $fa->category,
            'department'               => $fa->department,
            'acquisition_date'         => $fa->acquisition_date?->format('Y-m-d'),
            'placed_in_service_date'   => $fa->placed_in_service_date?->format('Y-m-d'),
            'acquisition_cost'         => (float) $fa->acquisition_cost,
            'accumulated_depreciation' => (float) $fa->accumulated_depreciation,
            'net_book_value'           => $fa->net_book_value,
            'useful_life_months'       => $fa->useful_life_months,
            'last_depreciation_period' => $fa->last_depreciation_period,
            'status'                   => $fa->status->value,
            'status_label'             => $fa->status->label(),
            'status_color'             => $fa->status->color(),
            'original_cost_account_code'         => $fa->original_cost_account_code,
            'accumulated_dep_account_code'       => $fa->accumulated_dep_account_code,
            'depreciation_expense_account_code'  => $fa->depreciation_expense_account_code,
        ]);

        return Inertia::render('Accounting/FixedAssets/Index', [
            'assets'     => $assets,
            'categories' => FixedAssetCategory::orderBy('name')->get(['id', 'name', 'code']),
            'statuses'   => collect(FixedAssetStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ]),
            'filters'    => $request->only(['search', 'category_id', 'status', 'department']),
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = null;
        if ($request->filled('purchase_invoice_id')) {
            $inv = PurchaseInvoice::with('supplier')->find($request->integer('purchase_invoice_id'));
            if ($inv) {
                $prefill = [
                    'purchase_invoice_id' => $inv->id,
                    'supplier_id'         => $inv->supplier_id,
                    'invoice_date'        => $inv->invoice_date?->format('Y-m-d'),
                    'acquisition_date'    => $inv->invoice_date?->format('Y-m-d'),
                    'acquisition_cost'    => (float) $inv->subtotal,
                    'vat_amount'          => (float) $inv->tax_amount,
                    'source_type'         => 'purchased',
                ];
            }
        }

        return $this->formResponse(null, $prefill);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAsset($request);
        $data = $this->applySedan1p6bCap($data);
        $createJournal = $request->boolean('create_journal');

        $asset = $this->service->create($data, $createJournal);

        return redirect()->route('accounting.fixed-assets.show', $asset)
            ->with('success', "Đã tạo TSCĐ {$asset->code}.");
    }

    public function show(FixedAsset $fixedAsset): Response
    {
        $fixedAsset->load(['category', 'supplier', 'purchaseInvoice', 'acquisitionJournalEntry']);
        $schedule  = $this->depreciationService->getFullSchedule($fixedAsset);
        $movements = $fixedAsset->movements()->with('createdByUser')->get();
        $repairs   = $fixedAsset->repairs()->with(['supplier', 'journalEntry'])->get();
        $disposals = $fixedAsset->disposals()->with('createdByUser')->get();

        $depreciations = $fixedAsset->depreciations()
            ->with('journalEntry')
            ->orderByDesc('period')
            ->paginate(24);

        return Inertia::render('Accounting/FixedAssets/Show', [
            'asset'        => $this->assetDetail($fixedAsset),
            'schedule'     => $schedule,
            'movements'    => $movements,
            'repairs'      => $repairs,
            'disposals'    => $disposals,
            'depreciations' => $depreciations,
        ]);
    }

    public function edit(FixedAsset $fixedAsset): Response
    {
        return $this->formResponse($fixedAsset, null);
    }

    public function update(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $this->validateAsset($request, $fixedAsset->id);
        $data = $this->applySedan1p6bCap($data);
        $data['updated_by'] = auth()->id();

        $fixedAsset->update($data);

        // Recompute depreciation_end_date if relevant fields changed
        if ($fixedAsset->depreciation_start_date && $fixedAsset->useful_life_months > 0) {
            $fixedAsset->update([
                'depreciation_end_date' => $fixedAsset->depreciation_start_date
                    ->addMonths($fixedAsset->useful_life_months - 1)
                    ->endOfMonth()
                    ->format('Y-m-d'),
            ]);
        }

        return redirect()->route('accounting.fixed-assets.show', $fixedAsset)
            ->with('success', 'Đã cập nhật TSCĐ.');
    }

    public function destroy(FixedAsset $fixedAsset): RedirectResponse
    {
        if ($fixedAsset->depreciations()->where('status', 'posted')->exists()) {
            return back()->with('error', 'Không thể xóa TSCĐ đã có bút toán khấu hao đã ghi sổ.');
        }

        $fixedAsset->delete();

        return redirect()->route('accounting.fixed-assets.index')
            ->with('success', 'Đã xóa TSCĐ.');
    }

    // -------------------------------------------------------
    // Actions
    // -------------------------------------------------------

    public function placeInService(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'placed_in_service_date' => 'required|date',
            'department'             => 'nullable|string|max:100',
        ]);

        $this->service->placeInService($fixedAsset, $data['placed_in_service_date'], $data['department'] ?? null);

        return back()->with('success', 'Đã đưa tài sản vào sử dụng.');
    }

    public function transfer(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'to_department'            => 'required|string|max:100',
            'effective_date'           => 'required|date',
            'to_expense_account_code'  => 'nullable|string|max:20',
            'notes'                    => 'nullable|string',
        ]);

        $this->service->transfer($fixedAsset, $data);

        return back()->with('success', 'Đã ghi nhận điều chuyển bộ phận.');
    }

    public function suspend(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'suspend_date' => 'required|date',
            'reason'       => 'required|string',
        ]);

        if ($fixedAsset->status !== FixedAssetStatus::Active) {
            return back()->with('error', 'Chỉ có thể tạm dừng tài sản đang sử dụng.');
        }

        $this->service->suspend($fixedAsset, $data['suspend_date'], $data['reason']);

        return back()->with('success', 'Đã tạm dừng khấu hao.');
    }

    public function resume(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate(['resume_date' => 'required|date']);

        if ($fixedAsset->status !== FixedAssetStatus::Suspended) {
            return back()->with('error', 'Tài sản không ở trạng thái tạm dừng.');
        }

        $this->service->resume($fixedAsset, $data['resume_date']);

        return back()->with('success', 'Đã tiếp tục khấu hao.');
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Áp dụng giới hạn 1,6 tỷ cho xe ô tô ≤9 chỗ (TT45 + Thông tư 96/2015).
     * tax_deductible_cost = min(acquisition_cost, 1.600.000.000).
     */
    private function applySedan1p6bCap(array $data): array
    {
        if (! empty($data['is_sedan_under_9_seats'])) {
            $cost = (float) ($data['acquisition_cost'] ?? 0);
            $data['tax_deductible_cost'] = min($cost, 1_600_000_000);
        } else {
            // Không phải xe ≤9 chỗ: tax_deductible_cost = null (= acquisition_cost)
            $data['tax_deductible_cost'] = null;
        }
        return $data;
    }

    private function formResponse(?FixedAsset $asset, ?array $prefill): Response
    {
        $detailAccounts = AccountCode::where('is_detail', true)->orderBy('code')->get(['code', 'name']);

        return Inertia::render('Accounting/FixedAssets/Form', [
            'asset'           => $asset ? $this->assetDetail($asset) : null,
            'prefill'         => $prefill,
            'categories'      => FixedAssetCategory::orderBy('name')->get(),
            'detailAccounts'  => $detailAccounts,
            'assetTypes'      => [
                ['value' => 'tangible',      'label' => 'TSCĐ hữu hình'],
                ['value' => 'intangible',    'label' => 'TSCĐ vô hình'],
                ['value' => 'finance_lease', 'label' => 'TSCĐ thuê tài chính'],
            ],
            'sourceTypes'     => [
                ['value' => 'purchased',    'label' => 'Mua ngoài'],
                ['value' => 'self_built',   'label' => 'Tự xây dựng'],
                ['value' => 'contributed',  'label' => 'Nhận góp vốn'],
                ['value' => 'transferred',  'label' => 'Điều chuyển'],
                ['value' => 'imported',     'label' => 'Nhập khẩu'],
                ['value' => 'other',        'label' => 'Khác'],
            ],
        ]);
    }

    private function assetDetail(FixedAsset $fa): array
    {
        return [
            'id'                                 => $fa->id,
            'code'                               => $fa->code,
            'name'                               => $fa->name,
            'category_id'                        => $fa->category_id,
            'category_name'                      => $fa->category?->name ?? $fa->category,
            'asset_type'                         => $fa->asset_type,
            'serial_number'                      => $fa->serial_number,
            'source_type'                        => $fa->source_type,
            'supplier_id'                        => $fa->supplier_id,
            'supplier_name'                      => $fa->supplier?->name,
            'purchase_invoice_id'                => $fa->purchase_invoice_id,
            'invoice_date'                       => $fa->invoice_date?->format('Y-m-d'),
            'acquisition_date'                   => $fa->acquisition_date?->format('Y-m-d'),
            'recognition_date'                   => $fa->recognition_date?->format('Y-m-d'),
            'placed_in_service_date'             => $fa->placed_in_service_date?->format('Y-m-d'),
            'depreciation_start_date'            => $fa->depreciation_start_date?->format('Y-m-d'),
            'depreciation_end_date'              => $fa->depreciation_end_date?->format('Y-m-d'),
            'acquisition_cost'                   => (float) $fa->acquisition_cost,
            'vat_amount'                         => (float) $fa->vat_amount,
            'total_amount'                       => (float) $fa->total_amount,
            'depreciable_amount'                 => (float) $fa->depreciable_amount,
            'opening_accumulated_depreciation'   => (float) $fa->opening_accumulated_depreciation,
            'accumulated_depreciation'           => (float) $fa->accumulated_depreciation,
            'net_book_value'                     => $fa->net_book_value,
            'monthly_depreciation'               => $fa->monthly_depreciation,
            'useful_life_months'                 => $fa->useful_life_months,
            'depreciation_method'                => $fa->depreciation_method,
            'last_depreciation_period'           => $fa->last_depreciation_period,
            'original_cost_account_code'         => $fa->original_cost_account_code,
            'accumulated_dep_account_code'       => $fa->accumulated_dep_account_code,
            'depreciation_expense_account_code'  => $fa->depreciation_expense_account_code,
            'payable_account_code'               => $fa->payable_account_code,
            'acquisition_journal_entry_id'       => $fa->acquisition_journal_entry_id,
            'department'                         => $fa->department,
            'responsible_user'                   => $fa->responsible_user,
            'usage_purpose'                      => $fa->usage_purpose,
            'is_for_business'                    => (bool) $fa->is_for_business,
            'is_sedan_under_9_seats'             => (bool) $fa->is_sedan_under_9_seats,
            'tax_deductible_cost'                => (float) ($fa->tax_deductible_cost ?? $fa->acquisition_cost),
            'monthly_non_deductible_depreciation' => $fa->monthly_non_deductible_depreciation,
            'location'                           => $fa->location,
            'notes'                              => $fa->notes,
            'status'                             => $fa->status->value,
            'status_label'                       => $fa->status->label(),
            'status_color'                       => $fa->status->color(),
        ];
    }

    private function validateAsset(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'code'                               => 'nullable|string|max:50|unique:fixed_assets,code' . ($excludeId ? ",{$excludeId}" : ''),
            'name'                               => 'required|string|max:255',
            'category_id'                        => 'nullable|exists:fixed_asset_categories,id',
            'asset_type'                         => 'required|in:tangible,intangible,finance_lease',
            'serial_number'                      => 'nullable|string|max:100',
            'source_type'                        => 'nullable|in:purchased,self_built,contributed,transferred,imported,other',
            'supplier_id'                        => 'nullable|exists:suppliers,id',
            'purchase_invoice_id'                => 'nullable|exists:purchase_invoices,id',
            'invoice_date'                       => 'nullable|date',
            'acquisition_date'                   => 'required|date',
            'recognition_date'                   => 'nullable|date',
            'placed_in_service_date'             => 'nullable|date',
            'depreciation_start_date'            => 'nullable|date',
            'acquisition_cost'                   => 'required|numeric|min:0',
            'vat_amount'                         => 'nullable|numeric|min:0',
            'depreciable_amount'                 => 'nullable|numeric|min:0',
            'opening_accumulated_depreciation'   => 'nullable|numeric|min:0',
            'useful_life_months'                 => 'required|integer|min:1',
            'depreciation_method'                => 'required|in:straight_line',
            'original_cost_account_code'         => 'nullable|string|max:20',
            'accumulated_dep_account_code'       => 'nullable|string|max:20',
            'depreciation_expense_account_code'  => 'nullable|string|max:20',
            'payable_account_code'               => 'nullable|string|max:20',
            'department'                         => 'nullable|string|max:100',
            'responsible_user'                   => 'nullable|string|max:100',
            'usage_purpose'                      => 'nullable|string',
            'is_for_business'                    => 'nullable|boolean',
            'is_sedan_under_9_seats'             => 'nullable|boolean',
            'tax_deductible_cost'                => 'nullable|numeric|min:0',
            'location'                           => 'nullable|string|max:255',
            'notes'                              => 'nullable|string',
        ]);
    }
}
