<?php

namespace App\Services;

use App\Enums\SubcontractStatus;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use Illuminate\Support\Facades\DB;

class ProjectSubcontractService
{
    public function create(Project $project, array $data): ProjectSubcontract
    {
        $total = round((float) $data['amount_before_vat'] + (float) ($data['vat_amount'] ?? 0), 2);

        return DB::transaction(function () use ($project, $data, $total) {
            $subcontract = ProjectSubcontract::create([
                'project_id'        => $project->id,
                'contractor_id'     => $data['contractor_id'] ?? null,
                'contractor_name'   => $data['contractor_name'],
                'contractor_type'   => $data['contractor_type'],
                'contract_no'       => $data['contract_no'],
                'contract_date'     => $data['contract_date'],
                'scope_of_work'     => $data['scope_of_work'] ?? null,
                'cost_group'        => $data['cost_group'] ?? 'subcontractor',
                'amount_before_vat' => $data['amount_before_vat'],
                'vat_rate'          => $data['vat_rate'] ?? null,
                'vat_amount'        => $data['vat_amount'] ?? 0,
                'total_amount'      => $total,
                'advance_rate'      => $data['advance_rate'] ?? null,
                'retention_rate'    => $data['retention_rate'] ?? null,
                'start_date'        => $data['start_date'] ?? null,
                'end_date'          => $data['end_date'] ?? null,
                'status'            => 'draft',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => auth()->id(),
            ]);

            activity()
                ->performedOn($project)
                ->causedBy(auth()->user())
                ->withProperties(['subcontract_id' => $subcontract->id])
                ->log('Tạo hợp đồng khoán');

            return $subcontract;
        });
    }

    public function update(ProjectSubcontract $subcontract, array $data): ProjectSubcontract
    {
        if ($subcontract->status !== SubcontractStatus::Draft) {
            throw new \RuntimeException('Chỉ có thể sửa hợp đồng đang ở trạng thái nháp.');
        }

        $total = round((float) $data['amount_before_vat'] + (float) ($data['vat_amount'] ?? 0), 2);

        $subcontract->update([
            'contractor_id'     => $data['contractor_id'] ?? null,
            'contractor_name'   => $data['contractor_name'],
            'contractor_type'   => $data['contractor_type'],
            'contract_no'       => $data['contract_no'],
            'contract_date'     => $data['contract_date'],
            'scope_of_work'     => $data['scope_of_work'] ?? null,
            'cost_group'        => $data['cost_group'] ?? 'subcontractor',
            'amount_before_vat' => $data['amount_before_vat'],
            'vat_rate'          => $data['vat_rate'] ?? null,
            'vat_amount'        => $data['vat_amount'] ?? 0,
            'total_amount'      => $total,
            'advance_rate'      => $data['advance_rate'] ?? null,
            'retention_rate'    => $data['retention_rate'] ?? null,
            'start_date'        => $data['start_date'] ?? null,
            'end_date'          => $data['end_date'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'updated_by'        => auth()->id(),
        ]);

        return $subcontract;
    }

    public function approve(ProjectSubcontract $subcontract): void
    {
        if ($subcontract->status !== SubcontractStatus::Draft) {
            throw new \RuntimeException('Chỉ có thể duyệt hợp đồng đang ở trạng thái nháp.');
        }

        $subcontract->update(['status' => 'active', 'updated_by' => auth()->id()]);

        activity()
            ->performedOn($subcontract->project)
            ->causedBy(auth()->user())
            ->withProperties(['subcontract_id' => $subcontract->id])
            ->log('Duyệt hợp đồng khoán');
    }

    public function cancel(ProjectSubcontract $subcontract, string $reason): void
    {
        if ($subcontract->status === SubcontractStatus::Cancelled) {
            throw new \RuntimeException('Hợp đồng này đã bị hủy.');
        }

        $hasPostings = $subcontract->acceptances()->where('status', 'posted')->exists()
            || $subcontract->advances()->where('status', 'posted')->exists()
            || $subcontract->payments()->where('status', 'posted')->exists();

        if ($hasPostings) {
            throw new \RuntimeException('Hợp đồng đã có nghiệm thu/tạm ứng/thanh toán — phải hủy từng mục đó trước.');
        }

        $subcontract->update([
            'status'     => 'cancelled',
            'notes'      => trim(($subcontract->notes ?? '') . "\nHủy: {$reason}"),
            'updated_by' => auth()->id(),
        ]);

        activity()
            ->performedOn($subcontract->project)
            ->causedBy(auth()->user())
            ->withProperties(['subcontract_id' => $subcontract->id, 'reason' => $reason])
            ->log('Hủy hợp đồng khoán');
    }
}
