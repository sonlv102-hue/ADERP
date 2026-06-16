<?php

namespace App\Services;

use App\Enums\PersonalLoanStatus;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\PersonalLoan;
use App\Models\PersonalLoanRepayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PersonalLoanService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Ghi sổ khoản vay: Dr quỹ (1111/1121), Cr 3411.
     */
    public function post(PersonalLoan $loan): void
    {
        if ($loan->status !== PersonalLoanStatus::Draft) {
            throw new RuntimeException('Chỉ ghi sổ khoản vay ở trạng thái nháp.');
        }
        if ((float) $loan->amount <= 0) {
            throw new RuntimeException('Số tiền vay phải lớn hơn 0.');
        }

        $exists = JournalEntry::where('reference_type', 'personal_loan')
            ->where('reference_id', $loan->id)
            ->whereIn('status', ['posted', 'draft'])
            ->exists();
        if ($exists) {
            throw new RuntimeException('Khoản vay đã được ghi sổ trước đó.');
        }

        $loan->loadMissing('fund');
        $fund        = $loan->fund ?? throw new RuntimeException('Chưa chọn quỹ nhận tiền.');
        $fundAccount = $this->resolveFundAccount($fund);
        $this->assertDetailAccount($fundAccount, 'Quỹ nhận tiền');
        $this->assertDetailAccount('3411', 'Phải trả (vay)');

        [$partnerType, $partnerId] = $this->resolvePartner($loan);

        $amount = (int) round((float) $loan->amount);
        $desc   = "Nhận tiền vay từ {$loan->lenderName()}" . ($loan->purpose ? ": {$loan->purpose}" : '');

        DB::transaction(function () use ($loan, $fundAccount, $amount, $desc, $partnerType, $partnerId) {
            $je = $this->accounting->post(
                description:       $desc,
                date:              Carbon::parse($loan->loan_date),
                lines:             [
                    ['account' => $fundAccount, 'debit' => $amount,  'credit' => 0,       'description' => $desc],
                    ['account' => '3411',        'debit' => 0,        'credit' => $amount, 'description' => $desc,
                     'partner_type' => $partnerType, 'partner_id' => $partnerId],
                ],
                referenceType:     'personal_loan',
                referenceId:       $loan->id,
                isAuto:            false,
                journalSourceType: 'personal_loan_receipt',
            );

            $loan->update([
                'status'           => PersonalLoanStatus::Active,
                'journal_entry_id' => $je->id,
            ]);
        });

        activity()->causedBy(auth()->user())->performedOn($loan)
            ->withProperties(['amount' => $loan->amount])->log('posted');
    }

    /**
     * Thêm đợt trả nợ: Dr 3411, Cr quỹ (1111/1121).
     */
    public function addRepayment(PersonalLoan $loan, array $data): PersonalLoanRepayment
    {
        if (! in_array($loan->status->value, ['active', 'partially_repaid'])) {
            throw new RuntimeException('Chỉ trả nợ khoản vay đang active hoặc trả một phần.');
        }

        $amount = (int) round((float) $data['amount']);
        if ($amount <= 0) {
            throw new RuntimeException('Số tiền trả phải lớn hơn 0.');
        }
        if ($amount > $loan->remainingAmount()) {
            throw new RuntimeException('Số tiền trả vượt quá số dư còn lại.');
        }

        $fund = Fund::findOrFail($data['fund_id']);
        $fundAccount = $this->resolveFundAccount($fund);
        $this->assertDetailAccount($fundAccount, 'Quỹ thanh toán');
        $this->assertDetailAccount('3411', 'Phải trả (vay)');

        [$partnerType, $partnerId] = $this->resolvePartner($loan);

        $desc = $data['description'] ?? "Trả nợ vay {$loan->loan_no}";

        $repayment = null;
        DB::transaction(function () use ($loan, $fund, $amount, $desc, $data, $fundAccount, $partnerType, $partnerId, &$repayment) {
            $je = $this->accounting->post(
                description:       $desc,
                date:              Carbon::parse($data['repayment_date']),
                lines:             [
                    ['account' => '3411',        'debit' => $amount,  'credit' => 0,       'description' => $desc,
                     'partner_type' => $partnerType, 'partner_id' => $partnerId],
                    ['account' => $fundAccount,  'debit' => 0,        'credit' => $amount, 'description' => $desc],
                ],
                referenceType:     'personal_loan',
                referenceId:       $loan->id,
                isAuto:            false,
                journalSourceType: 'personal_loan_repayment',
            );

            $repayment = PersonalLoanRepayment::create([
                'personal_loan_id'  => $loan->id,
                'fund_id'           => $fund->id,
                'repayment_date'    => $data['repayment_date'],
                'amount'            => $amount,
                'description'       => $desc,
                'journal_entry_id'  => $je->id,
                'created_by'        => auth()->id(),
            ]);

            $newRepaid = (float) $loan->repaid_amount + $amount;
            $newStatus = $newRepaid >= (float) $loan->amount
                ? PersonalLoanStatus::Repaid
                : PersonalLoanStatus::PartiallyRepaid;

            $loan->update(['repaid_amount' => $newRepaid, 'status' => $newStatus]);
        });

        activity()->causedBy(auth()->user())->performedOn($loan)
            ->withProperties(['repayment' => $amount])->log('repaid');

        return $repayment;
    }

    /**
     * Hủy khoản vay nháp.
     */
    public function cancel(PersonalLoan $loan): void
    {
        if ($loan->status !== PersonalLoanStatus::Draft) {
            throw new RuntimeException('Chỉ hủy khoản vay ở trạng thái nháp.');
        }
        $loan->update(['status' => PersonalLoanStatus::Cancelled]);
        activity()->causedBy(auth()->user())->performedOn($loan)->log('cancelled');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolvePartner(PersonalLoan $loan): array
    {
        return match ($loan->lender_type) {
            'employee'    => ['employee',    $loan->employee_id],
            'shareholder' => ['shareholder', $loan->shareholder_id],
            default       => [null, null],
        };
    }

    private function resolveFundAccount(Fund $fund): string
    {
        if (! empty($fund->account_code)) {
            return $fund->account_code;
        }
        return $fund->type === 'bank'
            ? AccountingSettings::get('bank_account', '1121')
            : AccountingSettings::get('cash_account', '1111');
    }

    private function assertDetailAccount(string $code, string $label): void
    {
        $acc = \App\Models\AccountCode::where('code', $code)->first();
        if (! $acc) {
            throw new RuntimeException("Tài khoản '{$code}' ({$label}) không tồn tại.");
        }
        if (! $acc->is_detail) {
            throw new RuntimeException("TK {$code} ({$label}) là tài khoản tổng hợp — dùng TK chi tiết.");
        }
    }
}
