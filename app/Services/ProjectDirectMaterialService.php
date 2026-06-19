<?php

namespace App\Services;

use App\Enums\DirectMaterialHandlingType;
use App\Models\AccountCode;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Models\ProjectWipEntry;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectDirectMaterialService
{
    public function __construct(
        private AccountingService $accounting,
    ) {}

    /**
     * Tạo vật tư phát sinh.
     * handling_type:
     *   tracking_only  — chỉ lưu record, không bút toán
     *   invoice_link   — liên kết purchase_invoice_item, không tạo bút toán mới
     *   journal_entry  — tạo bút toán N154/C{credit_account_code}
     */
    public function create(Project $project, array $data): ProjectDirectMaterial
    {
        $total = round((float) $data['quantity'] * (float) $data['unit_price'], 2);
        $handling = DirectMaterialHandlingType::from($data['handling_type']);

        if ($handling === DirectMaterialHandlingType::InvoiceLink) {
            $this->assertNoDoublePost($data['purchase_invoice_item_id'] ?? null);
        }

        return DB::transaction(function () use ($project, $data, $total, $handling) {
            $material = ProjectDirectMaterial::create([
                'project_id'               => $project->id,
                'product_id'               => $data['product_id'] ?? null,
                'product_name'             => $data['product_name'] ?? null,
                'quantity'                 => $data['quantity'],
                'unit_price'               => $data['unit_price'],
                'total_amount'             => $total,
                'occurrence_date'          => $data['occurrence_date'],
                'handling_type'            => $handling->value,
                'supplier_id'              => $data['supplier_id'] ?? null,
                'credit_account_code'      => $data['credit_account_code'] ?? null,
                'purchase_invoice_item_id' => $data['purchase_invoice_item_id'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'source_document_ref'      => $data['source_document_ref'] ?? null,
                'status'                   => 'active',
                'created_by'               => auth()->id(),
            ]);

            if ($handling === DirectMaterialHandlingType::JournalEntry && $total > 0) {
                $je = $this->postJournalEntry($project, $material, $total, $data);
                $material->update(['journal_entry_id' => $je->id]);
            }

            activity()
                ->performedOn($project)
                ->causedBy(auth()->user())
                ->withProperties(['material_id' => $material->id, 'handling_type' => $handling->value, 'total' => $total])
                ->log('Thêm vật tư phát sinh');

            return $material;
        });
    }

    /**
     * Hủy vật tư phát sinh. Nếu đã có bút toán thì reverse.
     */
    public function cancel(ProjectDirectMaterial $material, string $reason): void
    {
        if ($material->status !== 'active') {
            throw new \RuntimeException('Vật tư phát sinh này đã bị hủy.');
        }

        DB::transaction(function () use ($material, $reason) {
            if ($material->journal_entry_id) {
                $je = $material->journalEntry;
                if ($je && $je->status === 'posted') {
                    $this->accounting->reverse(
                        $je,
                        "Hủy vật tư phát sinh: {$reason}",
                        now()
                    );
                }
            }

            $material->update([
                'status'       => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            activity()
                ->performedOn($material->project)
                ->causedBy(auth()->user())
                ->withProperties(['material_id' => $material->id, 'reason' => $reason])
                ->log('Hủy vật tư phát sinh');
        });
    }

    /**
     * Preview bút toán cho type journal_entry (không lưu).
     */
    public function previewJournalEntry(array $data): array
    {
        $total = round((float) $data['quantity'] * (float) $data['unit_price'], 2);
        $wipAccount = AccountingSettings::get('project_wip_account', '154');
        $creditCode = $data['credit_account_code'] ?? '3311';
        $creditName = AccountCode::find($creditCode)?->name ?? $creditCode;

        return [
            ['account_code' => $wipAccount,  'side' => 'debit',  'amount' => $total, 'description' => 'Vật tư phát sinh cho dự án'],
            ['account_code' => $creditCode,  'side' => 'credit', 'amount' => $total, 'description' => $creditName],
        ];
    }

    private function postJournalEntry(Project $project, ProjectDirectMaterial $material, float $total, array $data): \App\Models\JournalEntry
    {
        $creditCode = $data['credit_account_code'] ?? '3311';
        $wipAccount = AccountingSettings::get('project_wip_account', '154');

        $productName = $material->product?->name ?? $material->product_name ?? 'Vật tư phát sinh';
        $description = "Vật tư phát sinh DA {$project->code}: {$productName}";
        $date        = Carbon::parse($data['occurrence_date']);

        $je = $this->accounting->post(
            description: $description,
            date: $date,
            lines: [
                ['account' => $wipAccount, 'debit' => $total, 'credit' => 0, 'description' => $description, 'project_id' => $project->id],
                ['account' => $creditCode, 'debit' => 0, 'credit' => $total, 'description' => $description, 'project_id' => $project->id],
            ],
            referenceType: ProjectDirectMaterial::class,
            referenceId: $material->id,
            isAuto: false,
        );

        // Ghi WIP entry để TK154 tab nhận diện được nguồn
        ProjectWipEntry::create([
            'project_id'    => $project->id,
            'source_type'   => ProjectDirectMaterial::class,
            'source_id'     => $material->id,
            'cost_type'     => 'material',
            'amount'        => $total,
            'description'   => $description,
            'entry_date'    => $data['occurrence_date'],
            'journal_entry_id' => $je->id,
            'product_id'    => $material->product_id,
            'quantity'      => $material->quantity,
            'unit_cost'     => $material->unit_price,
            'created_by'    => auth()->id(),
        ]);

        return $je;
    }

    private function assertNoDoublePost(?int $invoiceItemId): void
    {
        if (! $invoiceItemId) return;

        $exists = ProjectDirectMaterial::where('purchase_invoice_item_id', $invoiceItemId)
            ->where('status', 'active')
            ->exists();

        if ($exists) {
            throw new \RuntimeException('Dòng hóa đơn này đã được liên kết với vật tư phát sinh khác. Tránh ghi trùng chi phí.');
        }
    }
}
