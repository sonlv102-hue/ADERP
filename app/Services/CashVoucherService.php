<?php

namespace App\Services;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\AccountCode;
use App\Models\CashVoucher;
use App\Models\CashVoucherLine;
use App\Models\JournalEntry;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashVoucherService
{
    public function __construct(private AccountingService $accounting) {}

    // ─── Public actions ───────────────────────────────────────────────────────

    /**
     * Ghi sổ: validate bút toán → post JE ngay (isAuto=false) → confirmed.
     */
    public function confirm(CashVoucher $voucher): void
    {
        if ($voucher->status !== CashVoucherStatus::Draft) {
            throw new RuntimeException('Chỉ có thể ghi sổ phiếu ở trạng thái nháp.');
        }
        if ((float) $voucher->amount <= 0) {
            throw new RuntimeException('Số tiền phải lớn hơn 0.');
        }

        // Chống double-post
        $alreadyPosted = JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $voucher->id)
            ->where('status', 'posted')
            ->exists();
        if ($alreadyPosted) {
            throw new RuntimeException('Phiếu đã được ghi sổ trước đó.');
        }

        // Nếu chưa có dòng bút toán, tự sinh mặc định
        $lines = $voucher->journalLines;
        if ($lines->isEmpty()) {
            $defaultLines = $this->generateDefaultLines($voucher);
            if (empty($defaultLines)) {
                throw new RuntimeException('Phiếu chưa có bút toán. Chọn nghiệp vụ hoặc thêm bút toán thủ công.');
            }
            foreach ($defaultLines as $i => $data) {
                CashVoucherLine::create(array_merge($data, [
                    'cash_voucher_id' => $voucher->id,
                    'sort_order'      => $i,
                ]));
            }
            $lines = $voucher->journalLines()->orderBy('sort_order')->get();
        }

        $this->validateJournalLines($lines);

        $date    = Carbon::parse($voucher->voucher_date);
        $jeLines = $this->buildJeLines($voucher, $lines);

        DB::transaction(function () use ($voucher, $date, $jeLines) {
            $this->accounting->post(
                description:          "{$voucher->type->label()} {$voucher->code}",
                date:                 $date,
                lines:                $jeLines,
                referenceType:        'cash_voucher',
                referenceId:          $voucher->id,
                isAuto:               false,
                journalSourceType:    'cash_voucher',
            );
            $voucher->update(['status' => CashVoucherStatus::Confirmed]);
        });
    }

    /**
     * Thu hồi ghi sổ: đảo/xóa JE → status về draft.
     * Kỳ hiện tại phải còn mở (kiểm tra bên trong reverse()).
     */
    public function unpost(CashVoucher $voucher): void
    {
        if ($voucher->status !== CashVoucherStatus::Confirmed) {
            throw new RuntimeException('Chỉ có thể thu hồi phiếu đã ghi sổ.');
        }

        DB::transaction(function () use ($voucher) {
            $this->accounting->reverseOrDelete(
                'cash_voucher',
                $voucher->id,
                "Thu hồi {$voucher->type->label()} {$voucher->code}"
            );
            $voucher->update(['status' => CashVoucherStatus::Draft]);
        });

        activity()
            ->causedBy(auth()->user())
            ->performedOn($voucher)
            ->withProperties(['reason' => 'Thu hồi ghi sổ'])
            ->log('unpost');
    }

    /**
     * Hủy phiếu (vẫn giữ behavior cũ): đảo/xóa JE → cancelled.
     */
    public function cancel(CashVoucher $voucher): void
    {
        if ($voucher->status === CashVoucherStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        DB::transaction(function () use ($voucher) {
            $this->accounting->reverseOrDelete(
                'cash_voucher',
                $voucher->id,
                "Hủy {$voucher->type->label()} {$voucher->code}"
            );
            $voucher->update(['status' => CashVoucherStatus::Cancelled]);
        });
    }

    /**
     * Sinh dòng bút toán mặc định từ business_type + thông tin phiếu.
     * Trả về array dùng để insert vào cash_voucher_lines.
     */
    public function generateDefaultLines(CashVoucher $voucher): array
    {
        if (! $voucher->business_type) {
            return [];
        }

        $bt          = CashVoucherBusinessType::from($voucher->business_type);
        $fundAccount = $this->resolveFundAccount($voucher);
        $amount      = (float) $voucher->amount;
        $desc        = $voucher->description ?? '';
        $counter     = $this->resolveCounterAccountForType($voucher, $bt);

        [$partnerType, $partnerId] = $this->resolvePartnerForType($voucher, $bt);

        // Phiếu thu: Dr fund / Cr counter
        if ($bt->voucherType() === 'receipt') {
            return [[
                'debit_account'  => $fundAccount,
                'credit_account' => $counter,
                'amount'         => $amount,
                'description'    => "{$bt->label()}: {$desc}",
                'partner_type'   => $partnerType,
                'partner_id'     => $partnerId,
            ]];
        }

        // Phiếu chi: Dr counter / Cr fund
        return [[
            'debit_account'  => $counter,
            'credit_account' => $fundAccount,
            'amount'         => $amount,
            'description'    => "{$bt->label()}: {$desc}",
            'partner_type'   => $partnerType,
            'partner_id'     => $partnerId,
        ]];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function validateJournalLines(Collection $lines): void
    {
        if ($lines->isEmpty()) {
            throw new RuntimeException('Phiếu chưa có dòng bút toán.');
        }

        $allCodes   = $lines->flatMap(fn ($l) => [$l->debit_account, $l->credit_account])->unique()->values()->toArray();
        $accMap     = AccountCode::whereIn('code', $allCodes)->get()->keyBy('code');

        foreach ($lines as $line) {
            if ((float) $line->amount <= 0) {
                throw new RuntimeException("Số tiền bút toán phải lớn hơn 0 (dòng {$line->debit_account}/{$line->credit_account}).");
            }

            $this->assertDetailAccount($line->debit_account,  $accMap, 'Nợ');
            $this->assertDetailAccount($line->credit_account, $accMap, 'Có');

            // Tài khoản AR/AP/141/3388 bắt buộc có đối tượng
            $needsPartner = $this->accountRequiresPartner($line->debit_account)
                         || $this->accountRequiresPartner($line->credit_account);
            if ($needsPartner && ! $line->partner_id) {
                $requiredTk = $this->accountRequiresPartner($line->debit_account) ? $line->debit_account : $line->credit_account;
                throw new RuntimeException("TK {$requiredTk} bắt buộc có đối tượng công nợ.");
            }
        }
    }

    private function assertDetailAccount(string $code, \Illuminate\Support\Collection $accMap, string $side): void
    {
        $acc = $accMap->get($code);
        if (! $acc) {
            throw new RuntimeException("Tài khoản '{$code}' (bên {$side}) không tồn tại trong hệ thống.");
        }
        if (! $acc->is_detail) {
            throw new RuntimeException("TK {$code} (bên {$side}) là tài khoản tổng hợp. Vui lòng dùng tài khoản chi tiết.");
        }
    }

    private function accountRequiresPartner(string $code): bool
    {
        return str_starts_with($code, '131')
            || str_starts_with($code, '331')
            || $code === '141'
            || $code === '3388'
            || $code === '3411'
            || $code === '1388';
    }

    /**
     * Chuyển cash_voucher_lines → mảng lines cho AccountingService::post().
     * Partner được gắn vào bên cần partner (Dr hoặc Có), fund account không có partner.
     */
    private function buildJeLines(CashVoucher $voucher, Collection $lines): array
    {
        $jeLines = [];
        foreach ($lines as $line) {
            $debitNeedsPartner  = $this->accountRequiresPartner($line->debit_account);
            $creditNeedsPartner = $this->accountRequiresPartner($line->credit_account);

            $jeLines[] = [
                'account'      => $line->debit_account,
                'debit'        => (int) $line->amount,
                'credit'       => 0,
                'description'  => $line->description ?? $voucher->description,
                'partner_type' => $debitNeedsPartner ? $line->partner_type : null,
                'partner_id'   => $debitNeedsPartner ? $line->partner_id   : null,
            ];
            $jeLines[] = [
                'account'      => $line->credit_account,
                'debit'        => 0,
                'credit'       => (int) $line->amount,
                'description'  => $line->description ?? $voucher->description,
                'partner_type' => $creditNeedsPartner ? $line->partner_type : null,
                'partner_id'   => $creditNeedsPartner ? $line->partner_id   : null,
            ];
        }
        return $jeLines;
    }

    private function resolveFundAccount(CashVoucher $voucher): string
    {
        $voucher->loadMissing('fund');
        $fund = $voucher->fund;

        // Ưu tiên account_code được cấu hình trên quỹ (nhất quán với FundTransferService)
        if ($fund && ! empty($fund->account_code)) {
            return $fund->account_code;
        }

        return ($fund && $fund->type === 'bank')
            ? AccountingSettings::get('bank_account', '1121')
            : AccountingSettings::get('cash_account', '1111');
    }

    private function resolveCounterAccountForType(CashVoucher $voucher, CashVoucherBusinessType $bt): string
    {
        return match ($bt) {
            CashVoucherBusinessType::PaySupplier => $this->getSupplierPayable($voucher),
            CashVoucherBusinessType::CollectCustomer => $this->getCustomerReceivable($voucher),
            default => $bt->defaultCounterAccount(),
        };
    }

    private function resolvePartnerForType(CashVoucher $voucher, CashVoucherBusinessType $bt): array
    {
        $type = $voucher->partner_type ?? $bt->defaultPartnerType();
        return match ($type) {
            'employee'    => ['employee',    $voucher->employee_id],
            'supplier'    => ['supplier',    $voucher->supplier_id],
            'customer'    => ['customer',    $voucher->customer_id],
            'shareholder' => ['shareholder', $voucher->shareholder_id],
            default       => [null, null],
        };
    }

    private function getSupplierPayable(CashVoucher $voucher): string
    {
        if ($voucher->supplier_id) {
            $voucher->loadMissing('supplier');
            return $voucher->supplier->getPayableAccount();
        }
        return AccountingSettings::get('default_ap_account', '3311');
    }

    private function getCustomerReceivable(CashVoucher $voucher): string
    {
        if ($voucher->customer_id) {
            $voucher->loadMissing('customer');
            return $voucher->customer->getReceivableAccount();
        }
        return AccountingSettings::get('default_ar_account', '1311');
    }
}
