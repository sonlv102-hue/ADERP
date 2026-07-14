<?php

namespace App\Services;

use App\Enums\SubcontractStatus;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractAcceptance;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectSubcontractAcceptanceService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Nghiệm thu = ghi nhận chi phí luôn trong 1 bước (giống ProjectExtraCostTransferService).
     * JE: Nợ 154 [+ Nợ 1331 nếu có VAT] / Có {payableAccount}.
     */
    public function post(ProjectSubcontract $subcontract, array $data): ProjectSubcontractAcceptance
    {
        $amount    = round((float) $data['amount_before_vat'], 2);
        $vatAmount = round((float) ($data['vat_amount'] ?? 0), 2);
        $total     = $amount + $vatAmount;
        $date      = Carbon::parse($data['acceptance_date']);
        $creditAcct = $subcontract->payableAccount();

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Giá trị nghiệm thu phải lớn hơn 0.');
        }

        if (!empty($data['invoice_no'])) {
            $this->assertNoDuplicatePurchaseInvoice($subcontract, $data['invoice_no']);
        }

        return DB::transaction(function () use ($subcontract, $data, $amount, $vatAmount, $total, $date, $creditAcct) {
            $description = "Nghiệm thu HĐ {$subcontract->contract_no}: " . ($data['description'] ?? $subcontract->contractor_name);

            $lines = [
                ['account' => '154', 'debit' => $amount, 'credit' => 0, 'description' => $description, 'project_id' => $subcontract->project_id],
            ];
            if ($vatAmount > 0) {
                $lines[] = ['account' => '1331', 'debit' => $vatAmount, 'credit' => 0, 'description' => 'Thuế GTGT — ' . $description, 'project_id' => $subcontract->project_id];
            }
            $lines[] = ['account' => $creditAcct, 'debit' => 0, 'credit' => $total, 'description' => $description, 'project_id' => $subcontract->project_id];

            $je = $this->accounting->post(
                description: $description,
                date: $date,
                lines: $lines,
                referenceType: ProjectSubcontractAcceptance::class,
                referenceId: null,
                isAuto: false,
            );

            $wip = ProjectWipEntry::create([
                'project_id'       => $subcontract->project_id,
                'source_type'      => ProjectSubcontractAcceptance::class,
                'source_id'        => 0,
                'cost_type'        => $subcontract->cost_group->wipCostType(),
                'amount'           => $amount,
                'vat_amount'       => $vatAmount,
                'description'      => $description,
                'entry_date'       => $date,
                'journal_entry_id' => $je->id,
                'status'           => 'active',
                'created_by'       => auth()->id(),
            ]);

            $acceptance = ProjectSubcontractAcceptance::create([
                'subcontract_id'        => $subcontract->id,
                'project_id'            => $subcontract->project_id,
                'acceptance_no'         => $data['acceptance_no'] ?? null,
                'acceptance_date'       => $date,
                'description'           => $data['description'] ?? null,
                'amount_before_vat'     => $amount,
                'vat_rate'              => $data['vat_rate'] ?? null,
                'vat_amount'            => $vatAmount,
                'total_amount'          => $total,
                'invoice_no'            => $data['invoice_no'] ?? null,
                'invoice_date'          => $data['invoice_date'] ?? null,
                'journal_entry_id'      => $je->id,
                'project_wip_entry_id'  => $wip->id,
                'status'                => 'posted',
                'created_by'            => auth()->id(),
            ]);

            $wip->update(['source_id' => $acceptance->id]);
            $je->update(['reference_id' => $acceptance->id]);

            // Cập nhật retention + status hợp đồng
            $retentionDelta = $subcontract->retention_rate
                ? round($total * (float) $subcontract->retention_rate / 100, 2)
                : 0;

            $acceptedWithVat = (float) $subcontract->acceptances()->where('status', 'posted')->sum('total_amount') + $total;
            $newStatus = $acceptedWithVat >= (float) $subcontract->total_amount
                ? SubcontractStatus::Completed->value
                : SubcontractStatus::PartiallyAccepted->value;

            $subcontract->update([
                'retention_amount' => (float) $subcontract->retention_amount + $retentionDelta,
                'status'           => $newStatus,
            ]);

            activity()
                ->performedOn($subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['subcontract_id' => $subcontract->id, 'acceptance_id' => $acceptance->id, 'amount' => $total])
                ->log('Nghiệm thu hợp đồng khoán');

            return $acceptance;
        });
    }

    public function cancel(ProjectSubcontractAcceptance $acceptance, string $reason): void
    {
        if ($acceptance->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể hủy nghiệm thu đang ở trạng thái đã ghi nhận.');
        }

        DB::transaction(function () use ($acceptance, $reason) {
            $je = $acceptance->journalEntry;
            if ($je && $je->status === 'posted') {
                $this->accounting->reverse($je, "Hủy nghiệm thu: {$reason}", now());
            }

            if ($acceptance->project_wip_entry_id) {
                ProjectWipEntry::where('id', $acceptance->project_wip_entry_id)->update([
                    'status'        => 'cancelled',
                    'cancel_reason' => $reason,
                    'cancelled_by'  => auth()->id(),
                    'cancelled_at'  => now(),
                ]);
            }

            $acceptance->update([
                'status'        => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by'  => auth()->id(),
                'cancelled_at'  => now(),
            ]);

            $subcontract = $acceptance->subcontract;
            $retentionDelta = $subcontract->retention_rate
                ? round((float) $acceptance->total_amount * (float) $subcontract->retention_rate / 100, 2)
                : 0;

            $subcontract->update([
                'retention_amount' => max(0, (float) $subcontract->retention_amount - $retentionDelta),
                'status'           => $subcontract->acceptances()->where('status', 'posted')->exists()
                    ? SubcontractStatus::PartiallyAccepted->value
                    : SubcontractStatus::Active->value,
            ]);

            activity()
                ->performedOn($subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['acceptance_id' => $acceptance->id, 'reason' => $reason])
                ->log('Hủy nghiệm thu hợp đồng khoán');
        });
    }

    /**
     * Chống nhập trùng: nếu hóa đơn mua (Hóa đơn mua dịch vụ) cùng số hóa đơn đã được nhập
     * và link vào hợp đồng khoán này, không cho tạo nghiệm thu trùng cùng số hóa đơn đó nữa.
     */
    private function assertNoDuplicatePurchaseInvoice(ProjectSubcontract $subcontract, string $invoiceNo): void
    {
        $exists = PurchaseInvoice::where('subcontract_id', $subcontract->id)
            ->where('invoice_number', $invoiceNo)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($exists) {
            throw new \RuntimeException(
                "Hóa đơn số {$invoiceNo} đã được nhập ở Hóa đơn mua và liên kết với hợp đồng này. Không nhập trùng ở Nghiệm thu."
            );
        }
    }
}
