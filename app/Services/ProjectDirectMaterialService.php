<?php

namespace App\Services;

use App\Enums\DirectMaterialHandlingType;
use App\Models\AccountCode;
use App\Models\InventoryBalance;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Models\ProjectWipEntry;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectDirectMaterialService
{
    /** Map hình thức ghi nhận → TK Có mặc định (giống PAYMENT_MODES bên Chi phí PS, rút gọn cho vật tư) */
    public const PAYMENT_METHODS = [
        'cash'    => '1111',
        'bank'    => '1121',
        'advance' => '141',
        'payable' => '3311',
        'misc'    => null, // tự nhập tay
    ];

    public function __construct(
        private AccountingService $accounting,
    ) {}

    /**
     * Tạo vật tư phát sinh.
     * handling_type:
     *   tracking_only  — chỉ lưu record, không bút toán
     *   invoice_link   — liên kết purchase_invoice_item, không tạo bút toán mới
     *   journal_entry  — tạo bút toán N154(+N1331 nếu có VAT)/C{credit_account_code}
     *
     * post_immediately (default true): false → lưu nháp, không tạo JE/WIP.
     */
    public function create(Project $project, array $data): ProjectDirectMaterial
    {
        $total = round((float) $data['quantity'] * (float) $data['unit_price'], 2);
        $handling = DirectMaterialHandlingType::from($data['handling_type']);
        $postImmediately = $data['post_immediately'] ?? true;

        if ($handling === DirectMaterialHandlingType::InvoiceLink) {
            $this->assertNoDoublePost($data['purchase_invoice_item_id'] ?? null);
        }

        $creditCode = $data['credit_account_code']
            ?? ($data['payment_method'] ?? null ? self::PAYMENT_METHODS[$data['payment_method']] ?? null : null);

        $vatRate   = (float) ($data['vat_rate'] ?? 0);
        $vatAmount = (float) ($data['vat_amount'] ?? round($total * $vatRate / 100, 2));

        return DB::transaction(function () use ($project, $data, $total, $handling, $postImmediately, $creditCode, $vatRate, $vatAmount) {
            $material = ProjectDirectMaterial::create([
                'project_id'               => $project->id,
                'product_id'               => $data['product_id'] ?? null,
                'product_name'             => $data['product_name'] ?? null,
                'quantity'                 => $data['quantity'],
                'unit_price'               => $data['unit_price'],
                'vat_rate'                 => $vatRate ?: null,
                'vat_amount'               => $vatAmount,
                'total_amount'             => $total,
                'occurrence_date'          => $data['occurrence_date'],
                'handling_type'            => $handling->value,
                'payment_method'           => $data['payment_method'] ?? null,
                'supplier_id'              => $data['supplier_id'] ?? null,
                'credit_account_code'      => $creditCode,
                'purchase_invoice_item_id' => $data['purchase_invoice_item_id'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'source_document_ref'      => $data['source_document_ref'] ?? null,
                'status'                   => $postImmediately ? 'active' : 'draft',
                'created_by'               => auth()->id(),
            ]);

            if ($postImmediately && $handling === DirectMaterialHandlingType::JournalEntry && $total > 0) {
                $je = $this->postJournalEntry($project, $material, $total, $vatAmount, $data['occurrence_date'], $creditCode);
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
     * Ghi nhận (post) một vật tư phát sinh đang ở trạng thái nháp.
     */
    public function postExisting(ProjectDirectMaterial $material): void
    {
        if ($material->status !== 'draft') {
            throw new \RuntimeException('Chỉ có thể ghi nhận vật tư phát sinh đang ở trạng thái nháp.');
        }

        if ($material->handling_type !== DirectMaterialHandlingType::JournalEntry) {
            $material->update(['status' => 'active']);
            return;
        }

        if (empty($material->credit_account_code)) {
            throw new \RuntimeException('Phải chọn hình thức ghi nhận / tài khoản Có trước khi ghi nhận.');
        }

        DB::transaction(function () use ($material) {
            $total = (float) $material->total_amount;
            $je = $this->postJournalEntry(
                $material->project,
                $material,
                $total,
                (float) ($material->vat_amount ?? 0),
                $material->occurrence_date->format('Y-m-d'),
                $material->credit_account_code
            );
            $material->update(['journal_entry_id' => $je->id, 'status' => 'active']);
        });
    }

    /**
     * Cảnh báo mềm nếu sản phẩm đã có tồn kho thật — nên dùng phiếu xuất kho thay vì ghi trực tiếp.
     */
    public function checkStockOverlap(?int $productId): ?string
    {
        if (! $productId) return null;

        $stock = InventoryBalance::stockForProducts([$productId])->get($productId, 0);
        if ((float) $stock > 0) {
            return "Sản phẩm này đang có tồn kho ({$stock}). Nếu vật tư đã nhập kho, hãy dùng Phiếu xuất kho thay vì ghi Vật tư phát sinh để tránh trùng.";
        }

        return null;
    }

    /**
     * Hủy vật tư phát sinh. Nếu đã có bút toán thì reverse.
     */
    public function cancel(ProjectDirectMaterial $material, string $reason): void
    {
        if ($material->status === 'cancelled') {
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
        $total     = round((float) $data['quantity'] * (float) $data['unit_price'], 2);
        $vatRate   = (float) ($data['vat_rate'] ?? 0);
        $vatAmount = (float) ($data['vat_amount'] ?? round($total * $vatRate / 100, 2));
        $wipAccount = AccountingSettings::get('project_wip_account', '154');
        $creditCode = $data['credit_account_code'] ?? '3311';
        $creditName = AccountCode::find($creditCode)?->name ?? $creditCode;

        $lines = [
            ['account_code' => $wipAccount, 'side' => 'debit', 'amount' => $total, 'description' => 'Vật tư phát sinh cho dự án'],
        ];
        if ($vatAmount > 0) {
            $lines[] = ['account_code' => '1331', 'side' => 'debit', 'amount' => $vatAmount, 'description' => 'Thuế GTGT đầu vào'];
        }
        $lines[] = ['account_code' => $creditCode, 'side' => 'credit', 'amount' => $total + $vatAmount, 'description' => $creditName];

        return $lines;
    }

    /**
     * Tạo bút toán N154(+N1331 nếu có VAT)/C{creditCode} và WIP entry (amount = phần trước VAT).
     */
    private function postJournalEntry(
        Project $project,
        ProjectDirectMaterial $material,
        float $total,
        float $vatAmount,
        string $occurrenceDate,
        ?string $creditCode,
    ): \App\Models\JournalEntry {
        $creditCode = $creditCode ?? '3311';
        $wipAccount = AccountingSettings::get('project_wip_account', '154');

        $productName = $material->product?->name ?? $material->product_name ?? 'Vật tư phát sinh';
        $description = "Vật tư phát sinh DA {$project->code}: {$productName}";
        $date        = Carbon::parse($occurrenceDate);

        $lines = [
            ['account' => $wipAccount, 'debit' => $total, 'credit' => 0, 'description' => $description, 'project_id' => $project->id],
        ];
        if ($vatAmount > 0) {
            $lines[] = ['account' => '1331', 'debit' => $vatAmount, 'credit' => 0, 'description' => 'Thuế GTGT — ' . $description, 'project_id' => $project->id];
        }
        $lines[] = ['account' => $creditCode, 'debit' => 0, 'credit' => $total + $vatAmount, 'description' => $description, 'project_id' => $project->id];

        $je = $this->accounting->post(
            description: $description,
            date: $date,
            lines: $lines,
            referenceType: ProjectDirectMaterial::class,
            referenceId: $material->id,
            isAuto: false,
        );

        // Ghi WIP entry để TK154 tab nhận diện được nguồn — amount là phần TRƯỚC VAT
        ProjectWipEntry::create([
            'project_id'    => $project->id,
            'source_type'   => ProjectDirectMaterial::class,
            'source_id'     => $material->id,
            'cost_type'     => 'material',
            'amount'        => $total,
            'description'   => $description,
            'entry_date'    => $occurrenceDate,
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
