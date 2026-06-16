<?php

namespace App\Services;

use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetMovement;
use App\Models\FixedAssetRepair;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function __construct(
        protected FixedAssetJournalService $journalService,
        protected FixedAssetDepreciationService $depreciationService,
    ) {}

    // -------------------------------------------------------
    // Tạo TSCĐ mới
    // -------------------------------------------------------

    public function create(array $data, bool $createJournal = false): FixedAsset
    {
        return DB::transaction(function () use ($data, $createJournal) {
            $data['code'] = $data['code'] ?? FixedAsset::generateCode();

            // Backfill depreciable_amount from acquisition_cost if not set
            if (empty($data['depreciable_amount'])) {
                $data['depreciable_amount'] = $data['acquisition_cost'] ?? 0;
            }
            if (empty($data['total_amount'])) {
                $data['total_amount'] = ($data['acquisition_cost'] ?? 0) + ($data['vat_amount'] ?? 0);
            }

            $data['status']     = $data['status'] ?? FixedAssetStatus::PendingUse->value;
            $data['created_by'] = auth()->id();

            $asset = FixedAsset::create($data);

            if ($createJournal) {
                $this->journalService->createAcquisitionJournal($asset, isDraft: true);
            }

            return $asset;
        });
    }

    // -------------------------------------------------------
    // Đưa vào sử dụng
    // -------------------------------------------------------

    public function placeInService(FixedAsset $asset, string $date, ?string $department = null): void
    {
        DB::transaction(function () use ($asset, $date, $department) {
            $updateData = [
                'placed_in_service_date'  => $date,
                'depreciation_start_date' => $date,
                'status'                  => FixedAssetStatus::Active->value,
            ];

            if ($department) $updateData['department'] = $department;

            // Compute depreciation_end_date
            if ($asset->useful_life_months > 0) {
                $updateData['depreciation_end_date'] = Carbon::parse($date)
                    ->addMonths($asset->useful_life_months - 1)
                    ->endOfMonth()
                    ->format('Y-m-d');
            }

            $asset->update($updateData);

            FixedAssetMovement::create([
                'fixed_asset_id' => $asset->id,
                'movement_type'  => 'placed_in_service',
                'movement_date'  => $date,
                'notes'          => 'Đưa vào sử dụng',
                'created_by'     => auth()->id(),
            ]);
        });
    }

    // -------------------------------------------------------
    // Điều chuyển bộ phận (không sinh bút toán)
    // -------------------------------------------------------

    public function transfer(FixedAsset $asset, array $data): FixedAssetMovement
    {
        return DB::transaction(function () use ($asset, $data) {
            $movement = FixedAssetMovement::create([
                'fixed_asset_id'            => $asset->id,
                'movement_type'             => 'department_transfer',
                'movement_date'             => $data['effective_date'],
                'from_department'           => $asset->department,
                'to_department'             => $data['to_department'],
                'from_expense_account_code' => $asset->depreciation_expense_account_code,
                'to_expense_account_code'   => $data['to_expense_account_code'] ?? null,
                'effective_from'            => $data['effective_date'],
                'notes'                     => $data['notes'] ?? null,
                'created_by'                => auth()->id(),
            ]);

            $updateData = ['department' => $data['to_department']];
            if (! empty($data['to_expense_account_code'])) {
                $updateData['depreciation_expense_account_code'] = $data['to_expense_account_code'];
            }

            $asset->update($updateData);

            return $movement;
        });
    }

    // -------------------------------------------------------
    // Tạm dừng / tiếp tục khấu hao
    // -------------------------------------------------------

    public function suspend(FixedAsset $asset, string $date, string $reason): void
    {
        $asset->update(['status' => FixedAssetStatus::Suspended->value]);

        FixedAssetMovement::create([
            'fixed_asset_id' => $asset->id,
            'movement_type'  => 'suspended',
            'movement_date'  => $date,
            'notes'          => $reason,
            'created_by'     => auth()->id(),
        ]);
    }

    public function resume(FixedAsset $asset, string $date): void
    {
        $asset->update(['status' => FixedAssetStatus::Active->value]);

        FixedAssetMovement::create([
            'fixed_asset_id' => $asset->id,
            'movement_type'  => 'resumed',
            'movement_date'  => $date,
            'notes'          => 'Tiếp tục khấu hao',
            'created_by'     => auth()->id(),
        ]);
    }

    // -------------------------------------------------------
    // Sửa chữa / nâng cấp
    // -------------------------------------------------------

    public function createRepair(FixedAsset $asset, array $data, bool $createJournal = true): FixedAssetRepair
    {
        return DB::transaction(function () use ($asset, $data, $createJournal) {
            $repair = FixedAssetRepair::create([
                ...$data,
                'fixed_asset_id' => $asset->id,
                'status'         => 'draft',
                'created_by'     => auth()->id(),
            ]);

            if ($createJournal) {
                $je = $this->journalService->createRepairJournal($repair, isDraft: true);
                $repair->update(['journal_entry_id' => $je->id, 'status' => 'draft']);
            }

            // Nếu nâng cấp tăng nguyên giá, cập nhật acquisition_cost
            if ($repair->accounting_treatment === 'increase_original_cost') {
                $asset->increment('acquisition_cost', (float) $repair->amount);
                $asset->increment('depreciable_amount', (float) $repair->amount);
                $asset->update(['total_amount' => (float) $asset->total_amount + (float) $repair->amount]);
            }

            return $repair;
        });
    }

    // -------------------------------------------------------
    // Thanh lý / nhượng bán
    // -------------------------------------------------------

    public function dispose(FixedAsset $asset, array $data, bool $createJournal = true): FixedAssetDisposal
    {
        return DB::transaction(function () use ($asset, $data, $createJournal) {
            $nbv    = max(0, (float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation);
            $accDep = (float) $asset->accumulated_depreciation;

            $gainLoss = (float) ($data['selling_price'] ?? 0)
                - (float) ($data['disposal_cost'] ?? 0)
                - $nbv;

            $disposal = FixedAssetDisposal::create([
                ...$data,
                'fixed_asset_id'                    => $asset->id,
                'original_cost_snapshot'            => $asset->acquisition_cost,
                'accumulated_depreciation_snapshot' => $accDep,
                'net_book_value_snapshot'           => $nbv,
                'gain_loss'                         => $gainLoss,
                'status'                            => 'draft',
                'created_by'                        => auth()->id(),
            ]);

            if ($createJournal) {
                $jeIds = $this->journalService->createDisposalJournals($disposal, isDraft: true);
                $disposal->update(['journal_entry_ids' => $jeIds]);
            }

            $asset->update(['status' => FixedAssetStatus::Disposed->value]);

            return $disposal;
        });
    }

    // -------------------------------------------------------
    // Backward compat: batch depreciation (gọi service mới)
    // -------------------------------------------------------

    public function runMonthlyDepreciation(string $period): array
    {
        $result = $this->depreciationService->runPeriod($period, createJournal: true, isDraft: true);
        return [
            'processed' => $result['processed'],
            'skipped'   => $result['skipped'],
            'errors'    => $result['errors'],
        ];
    }

    public function getSchedule(FixedAsset $asset): array
    {
        return $this->depreciationService->getFullSchedule($asset);
    }
}
