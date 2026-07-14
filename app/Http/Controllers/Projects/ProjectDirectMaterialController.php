<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Services\ProjectDirectMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectDirectMaterialController extends Controller
{
    public function __construct(
        private ProjectDirectMaterialService $service,
    ) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('projects.manage');

        $data = $request->validate([
            'product_id'               => ['nullable', 'exists:products,id'],
            'product_name'             => ['nullable', 'string', 'max:255'],
            'quantity'                 => ['required', 'numeric', 'min:0.001'],
            'unit_price'               => ['required', 'numeric', 'min:0'],
            'vat_rate'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'               => ['nullable', 'numeric', 'min:0'],
            'occurrence_date'          => ['required', 'date'],
            'handling_type'            => ['required', 'in:tracking_only,invoice_link,journal_entry'],
            'payment_method'           => ['nullable', 'in:cash,bank,advance,payable,misc'],
            'supplier_id'              => ['nullable', 'exists:suppliers,id'],
            'credit_account_code'      => ['nullable', 'string', 'max:20'],
            'purchase_invoice_item_id' => ['nullable', 'exists:purchase_invoice_items,id'],
            'notes'                    => ['nullable', 'string', 'max:1000'],
            'source_document_ref'      => ['nullable', 'string', 'max:255'],
            'post_immediately'         => ['nullable', 'boolean'],
        ]);

        if (! ($data['product_id'] ?? null) && ! ($data['product_name'] ?? null)) {
            return back()->withErrors(['product_name' => 'Phải chọn sản phẩm hoặc nhập tên vật tư.']);
        }

        $postImmediately = $request->boolean('post_immediately', true);
        $data['post_immediately'] = $postImmediately;

        if ($postImmediately
            && $data['handling_type'] === 'journal_entry'
            && empty($data['credit_account_code'] ?? null)
            && empty($data['payment_method'] ?? null)
        ) {
            return back()->withErrors(['credit_account_code' => 'Phải chọn hình thức ghi nhận hoặc tài khoản Có khi ghi nhận TK 154.']);
        }

        try {
            $this->service->create($project, $data);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã thêm vật tư phát sinh.');
    }

    public function preview(Request $request, Project $project): JsonResponse
    {
        $this->authorize('projects.view');

        $data = $request->validate([
            'quantity'            => ['required', 'numeric', 'min:0'],
            'unit_price'          => ['required', 'numeric', 'min:0'],
            'vat_rate'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'          => ['nullable', 'numeric', 'min:0'],
            'credit_account_code' => ['required', 'string', 'max:20'],
            'product_id'          => ['nullable', 'exists:products,id'],
        ]);

        return response()->json([
            'lines'         => $this->service->previewJournalEntry($data),
            'stock_warning' => $this->service->checkStockOverlap($data['product_id'] ?? null),
        ]);
    }

    public function post(Project $project, ProjectDirectMaterial $directMaterial): RedirectResponse
    {
        $this->authorize('projects.manage');
        abort_unless($directMaterial->project_id === $project->id, 404);

        try {
            $this->service->postExisting($directMaterial);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận vật tư phát sinh.');
    }

    public function destroy(Project $project, ProjectDirectMaterial $directMaterial): RedirectResponse
    {
        $this->authorize('projects.manage');
        abort_unless($directMaterial->project_id === $project->id, 404);

        $reason = request()->input('cancel_reason', 'Hủy theo yêu cầu');

        try {
            $this->service->cancel($directMaterial, $reason);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy vật tư phát sinh.');
    }
}
