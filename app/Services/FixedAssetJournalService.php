<?php

namespace App\Services;

use App\Models\AccountCode;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetRepair;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tạo bút toán kế toán cho mọi nghiệp vụ TSCĐ.
 * Bút toán tự động luôn tạo ở draft để kế toán kiểm tra trước khi post.
 */
class FixedAssetJournalService
{
    public function __construct(protected AccountingService $accounting) {}

    // -------------------------------------------------------
    // 1. Ghi nhận mua TSCĐ
    // -------------------------------------------------------

    public function createAcquisitionJournal(FixedAsset $asset, bool $isDraft = true): JournalEntry
    {
        $this->validateAccounts([
            $asset->getAssetAccountCode(),
            $asset->payable_account_code ?? '3311',
        ]);

        $originalCost = (float) $asset->acquisition_cost;
        $vatAmount    = (float) $asset->vat_amount;
        $totalAmount  = $originalCost + $vatAmount;
        $payableCode  = $asset->payable_account_code ?? '3311';
        $assetCode    = $asset->getAssetAccountCode();
        $entryDate    = $asset->recognition_date ?? $asset->acquisition_date;

        $lines = [
            [
                'account'       => $assetCode,
                'debit'         => $originalCost,
                'credit'        => 0,
                'description'   => "Nguyên giá TSCĐ: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ],
        ];

        if ($vatAmount > 0) {
            $this->validateAccounts(['1332']);
            $lines[] = [
                'account'     => '1332',
                'debit'       => $vatAmount,
                'credit'      => 0,
                'description' => "VAT mua TSCĐ: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ];
        }

        $lines[] = [
            'account'     => $payableCode,
            'debit'       => 0,
            'credit'      => $totalAmount,
            'description' => "Phải trả khi mua TSCĐ: {$asset->name}",
            'fixed_asset_id' => $asset->id,
        ];

        $je = $this->accounting->post(
            description: "Ghi nhận mua TSCĐ: {$asset->code} - {$asset->name}",
            date: Carbon::parse($entryDate),
            lines: $lines,
            referenceType: 'fixed_asset',
            referenceId: $asset->id,
            isAuto: $isDraft,
            journalSourceType: 'fixed_asset_acquisition',
        );

        $asset->update(['acquisition_journal_entry_id' => $je->id]);

        return $je;
    }

    // -------------------------------------------------------
    // 2. Bút toán khấu hao hàng tháng (một tài sản)
    // -------------------------------------------------------

    public function buildDepreciationLine(FixedAsset $asset, float $amount): array
    {
        return [
            'account'        => $asset->depreciation_expense_account_code ?? AccountingSettings::get('depreciation_expense_account', '6421'),
            'debit'          => $amount,
            'credit'         => 0,
            'description'    => "Khấu hao {$asset->code} - {$asset->name}",
            'fixed_asset_id' => $asset->id,
        ];
    }

    public function buildDepreciationCreditLine(FixedAsset $asset, float $amount): array
    {
        return [
            'account'        => $asset->getDepreciationAccountCode(),
            'debit'          => 0,
            'credit'         => $amount,
            'description'    => "Hao mòn {$asset->code} - {$asset->name}",
            'fixed_asset_id' => $asset->id,
        ];
    }

    /**
     * Tạo bút toán khấu hao tổng hợp cho nhiều tài sản trong một kỳ.
     * $items = [ ['asset' => FixedAsset, 'amount' => float], ... ]
     */
    public function createBatchDepreciationJournal(array $items, string $period, bool $isDraft = true): JournalEntry
    {
        $lines = [];
        $totalByExpenseAccount = [];
        $totalByDepAccount     = [];

        foreach ($items as ['asset' => $asset, 'amount' => $amount]) {
            $expCode = $asset->depreciation_expense_account_code ?? AccountingSettings::get('depreciation_expense_account', '6421');
            $depCode = $asset->getDepreciationAccountCode();

            $lines[] = $this->buildDepreciationLine($asset, $amount);
            $lines[] = $this->buildDepreciationCreditLine($asset, $amount);

            $totalByExpenseAccount[$expCode] = ($totalByExpenseAccount[$expCode] ?? 0) + $amount;
            $totalByDepAccount[$depCode]     = ($totalByDepAccount[$depCode] ?? 0) + $amount;
        }

        $this->validateAccounts(array_unique(
            array_merge(array_keys($totalByExpenseAccount), array_keys($totalByDepAccount))
        ));

        $total = array_sum(array_column($items, 'amount'));

        return $this->accounting->post(
            description: "Khấu hao TSCĐ kỳ {$period}",
            date: Carbon::parse($period . '-01')->endOfMonth(),
            lines: $lines,
            referenceType: 'fixed_asset_depreciation_batch',
            isAuto: $isDraft,
            journalSourceType: 'fixed_asset_depreciation',
            notes: "Tổng {$total} VND — " . count($items) . " tài sản",
        );
    }

    // -------------------------------------------------------
    // 3. Bút toán thanh lý / nhượng bán
    // -------------------------------------------------------

    public function createDisposalJournals(FixedAssetDisposal $disposal, bool $isDraft = true): array
    {
        $asset = $disposal->fixedAsset;
        $date  = Carbon::parse($disposal->disposal_date);
        $jeIds = [];

        // JE1: Xóa nguyên giá và hao mòn
        $nbv          = (float) $disposal->net_book_value_snapshot;
        $accDep       = (float) $disposal->accumulated_depreciation_snapshot;
        $originalCost = (float) $disposal->original_cost_snapshot;
        $assetCode    = $asset->getAssetAccountCode();
        $depCode      = $asset->getDepreciationAccountCode();
        $disposalCode = $disposal->disposal_account_code ?? '811';

        $this->validateAccounts([$assetCode, $depCode, $disposalCode]);

        $writeoffLines = [];
        if ($accDep > 0) {
            $writeoffLines[] = [
                'account'        => $depCode,
                'debit'          => $accDep,
                'credit'         => 0,
                'description'    => "Xóa hao mòn TSCĐ: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ];
        }
        if ($nbv > 0) {
            $writeoffLines[] = [
                'account'        => $disposalCode,
                'debit'          => $nbv,
                'credit'         => 0,
                'description'    => "Giá trị còn lại khi thanh lý: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ];
        }
        $writeoffLines[] = [
            'account'        => $assetCode,
            'debit'          => 0,
            'credit'         => $originalCost,
            'description'    => "Nguyên giá TSCĐ thanh lý: {$asset->name}",
            'fixed_asset_id' => $asset->id,
        ];

        $je1 = $this->accounting->post(
            description: "Xóa sổ TSCĐ: {$asset->code} - {$asset->name}",
            date: $date,
            lines: $writeoffLines,
            referenceType: 'fixed_asset_disposal',
            referenceId: $disposal->id,
            isAuto: $isDraft,
            journalSourceType: 'fixed_asset_disposal',
        );
        $jeIds[] = $je1->id;

        // JE2: Doanh thu thanh lý nếu có
        $sellingPrice = (float) $disposal->selling_price;
        if ($sellingPrice > 0) {
            $incomeCode = $disposal->income_account_code ?? '711';
            $this->validateAccounts([$incomeCode]);

            $revenueLines = [
                [
                    'account'        => '1111',
                    'debit'          => $sellingPrice + (float) $disposal->selling_vat_amount,
                    'credit'         => 0,
                    'description'    => "Thu từ thanh lý TSCĐ: {$asset->name}",
                    'fixed_asset_id' => $asset->id,
                ],
                [
                    'account'     => $incomeCode,
                    'debit'       => 0,
                    'credit'      => $sellingPrice,
                    'description' => "Thu nhập khác - thanh lý TSCĐ: {$asset->name}",
                    'fixed_asset_id' => $asset->id,
                ],
            ];

            if ((float) $disposal->selling_vat_amount > 0) {
                $this->validateAccounts(['3331']);
                $revenueLines[] = [
                    'account'     => '3331',
                    'debit'       => 0,
                    'credit'      => (float) $disposal->selling_vat_amount,
                    'description' => "VAT thanh lý TSCĐ",
                    'fixed_asset_id' => $asset->id,
                ];
            }

            $je2 = $this->accounting->post(
                description: "Doanh thu thanh lý TSCĐ: {$asset->code} - {$asset->name}",
                date: $date,
                lines: $revenueLines,
                referenceType: 'fixed_asset_disposal',
                referenceId: $disposal->id,
                isAuto: $isDraft,
                journalSourceType: 'fixed_asset_disposal',
            );
            $jeIds[] = $je2->id;
        }

        // JE3: Chi phí thanh lý nếu có
        $disposalCost = (float) $disposal->disposal_cost;
        if ($disposalCost > 0) {
            $costLines = [
                [
                    'account'        => $disposalCode,
                    'debit'          => $disposalCost,
                    'credit'         => 0,
                    'description'    => "Chi phí thanh lý TSCĐ: {$asset->name}",
                    'fixed_asset_id' => $asset->id,
                ],
            ];
            if ((float) $disposal->disposal_vat_amount > 0) {
                $costLines[] = [
                    'account'     => '1331',
                    'debit'       => (float) $disposal->disposal_vat_amount,
                    'credit'      => 0,
                    'description' => "VAT chi phí thanh lý",
                ];
            }
            $costLines[] = [
                'account'     => '1111',
                'debit'       => 0,
                'credit'      => $disposalCost + (float) $disposal->disposal_vat_amount,
                'description' => "Thanh toán chi phí thanh lý",
            ];

            $je3 = $this->accounting->post(
                description: "Chi phí thanh lý TSCĐ: {$asset->code}",
                date: $date,
                lines: $costLines,
                referenceType: 'fixed_asset_disposal',
                referenceId: $disposal->id,
                isAuto: $isDraft,
                journalSourceType: 'fixed_asset_disposal',
            );
            $jeIds[] = $je3->id;
        }

        return $jeIds;
    }

    // -------------------------------------------------------
    // 4. Bút toán sửa chữa / nâng cấp
    // -------------------------------------------------------

    public function createRepairJournal(FixedAssetRepair $repair, bool $isDraft = true): JournalEntry
    {
        $asset   = $repair->fixedAsset;
        $date    = Carbon::parse($repair->repair_date);
        $amount  = (float) $repair->amount;
        $vat     = (float) $repair->vat_amount;
        $payable = '3311';

        $debitCode = match($repair->accounting_treatment) {
            'prepaid_allocation'     => '242',
            'increase_original_cost' => '2413',
            default                  => $asset->depreciation_expense_account_code ?? '6421',
        };

        $this->validateAccounts([$debitCode, $payable]);

        $lines = [
            [
                'account'        => $debitCode,
                'debit'          => $amount,
                'credit'         => 0,
                'description'    => "{$repair->repairTypeLabel()}: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ],
        ];

        if ($vat > 0) {
            $this->validateAccounts(['1331']);
            $lines[] = [
                'account'     => '1331',
                'debit'       => $vat,
                'credit'      => 0,
                'description' => "VAT {$repair->repairTypeLabel()}",
            ];
        }

        $lines[] = [
            'account'     => $payable,
            'debit'       => 0,
            'credit'      => $amount + $vat,
            'description' => "Phải trả NCC sửa chữa TSCĐ: {$asset->name}",
        ];

        return $this->accounting->post(
            description: "{$repair->repairTypeLabel()} TSCĐ: {$asset->code} - {$asset->name}",
            date: $date,
            lines: $lines,
            referenceType: 'fixed_asset_repair',
            referenceId: $repair->id,
            isAuto: $isDraft,
            journalSourceType: 'fixed_asset_repair',
        );
    }

    /**
     * Khi hoàn thành nâng cấp: Dr 2111 / Cr 2413
     */
    public function createUpgradeCompleteJournal(FixedAssetRepair $repair, bool $isDraft = true): JournalEntry
    {
        $asset   = $repair->fixedAsset;
        $amount  = (float) $repair->amount;
        $assetCode = $asset->getAssetAccountCode();

        $this->validateAccounts([$assetCode, '2413']);

        $lines = [
            [
                'account'        => $assetCode,
                'debit'          => $amount,
                'credit'         => 0,
                'description'    => "Tăng nguyên giá TSCĐ sau nâng cấp: {$asset->name}",
                'fixed_asset_id' => $asset->id,
            ],
            [
                'account'     => '2413',
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "Kết chuyển XDCB dở dang vào TSCĐ",
            ],
        ];

        return $this->accounting->post(
            description: "Hoàn thành nâng cấp TSCĐ: {$asset->code} - {$asset->name}",
            date: Carbon::now(),
            lines: $lines,
            referenceType: 'fixed_asset_repair',
            referenceId: $repair->id,
            isAuto: $isDraft,
            journalSourceType: 'fixed_asset_repair',
        );
    }

    // -------------------------------------------------------
    // Helper
    // -------------------------------------------------------

    private function validateAccounts(array $codes): void
    {
        foreach ($codes as $code) {
            $account = AccountCode::where('code', $code)->first();
            if (! $account) {
                throw new \InvalidArgumentException("Tài khoản {$code} không tồn tại trong hệ thống.");
            }
            if (! $account->is_detail) {
                throw new \InvalidArgumentException("Tài khoản {$code} là tài khoản tổng hợp, không được hạch toán trực tiếp.");
            }
        }
    }
}
