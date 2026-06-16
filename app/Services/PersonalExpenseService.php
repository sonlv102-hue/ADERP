<?php

namespace App\Services;

use App\Enums\PersonalExpenseStatus;
use App\Models\AccountCode;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\PersonalExpenseLine;
use App\Models\PersonalExpenseReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PersonalExpenseService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Tạo phiếu ghi nhận chi hộ cùng các dòng chi tiết.
     */
    public function create(array $data): PersonalExpenseReport
    {
        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            throw new RuntimeException('Phiếu chi hộ phải có ít nhất một dòng chi tiết.');
        }

        $report = null;
        DB::transaction(function () use ($data, $lines, &$report) {
            $report = PersonalExpenseReport::create([
                'report_no'    => PersonalExpenseReport::generateNo(),
                'person_type'  => $data['person_type'],
                'employee_id'  => $data['employee_id'] ?? null,
                'shareholder_id' => $data['shareholder_id'] ?? null,
                'person_name'  => $data['person_name'] ?? null,
                'expense_date' => $data['expense_date'],
                'description'  => $data['description'],
                'total_amount' => 0,
                'vat_amount'   => 0,
                'status'       => PersonalExpenseStatus::Draft,
                'created_by'   => auth()->id(),
            ]);

            $totalAmount = 0;
            $totalVat    = 0;
            foreach ($lines as $i => $line) {
                $amount    = (int) round((float) ($line['amount'] ?? 0));
                $vatRate   = (float) ($line['vat_rate'] ?? 0);
                $vatAmount = (int) round($amount * $vatRate / (100 + $vatRate));
                $netAmount = $amount - $vatAmount;

                PersonalExpenseLine::create([
                    'personal_expense_report_id' => $report->id,
                    'expense_account'  => $line['expense_account'],
                    'description'      => $line['description'],
                    'amount'           => $amount,
                    'vat_rate'         => $vatRate,
                    'vat_amount'       => $vatAmount,
                    'net_amount'       => $netAmount,
                    'sort_order'       => $i,
                ]);
                $totalAmount += $amount;
                $totalVat    += $vatAmount;
            }

            $report->update(['total_amount' => $totalAmount, 'vat_amount' => $totalVat]);
        });

        return $report;
    }

    /**
     * Ghi sổ: sinh JE ghi nhận chi phí.
     * Dr expense_accounts (net) + Dr 1331 (VAT), Cr 3388 (total, partner = người chi hộ)
     */
    public function post(PersonalExpenseReport $report): void
    {
        if ($report->status !== PersonalExpenseStatus::Draft) {
            throw new RuntimeException('Chỉ ghi sổ phiếu ở trạng thái nháp.');
        }

        $report->loadMissing('lines');
        if ($report->lines->isEmpty()) {
            throw new RuntimeException('Phiếu không có dòng chi tiết.');
        }

        $exists = JournalEntry::where('reference_type', 'personal_expense')
            ->where('reference_id', $report->id)
            ->whereIn('status', ['posted', 'draft'])
            ->exists();
        if ($exists) {
            throw new RuntimeException('Phiếu đã được ghi sổ trước đó.');
        }

        $this->assertDetailAccount('3388', 'Phải trả chi hộ');
        $this->assertDetailAccount('1331', 'Thuế GTGT đầu vào');

        [$partnerType, $partnerId] = $this->resolvePartner($report);

        $desc    = "Chi hộ: {$report->description}";
        $jeLines = [];

        foreach ($report->lines as $line) {
            $this->assertDetailAccount($line->expense_account, "Chi phí dòng {$line->sort_order}");
            if ((int) $line->net_amount > 0) {
                $jeLines[] = [
                    'account'     => $line->expense_account,
                    'debit'       => (int) $line->net_amount,
                    'credit'      => 0,
                    'description' => $line->description,
                ];
            }
            if ((int) $line->vat_amount > 0) {
                $jeLines[] = [
                    'account'     => '1331',
                    'debit'       => (int) $line->vat_amount,
                    'credit'      => 0,
                    'description' => "Thuế GTGT: {$line->description}",
                ];
            }
        }

        $jeLines[] = [
            'account'      => '3388',
            'debit'        => 0,
            'credit'       => (int) $report->total_amount,
            'description'  => $desc,
            'partner_type' => $partnerType,
            'partner_id'   => $partnerId,
        ];

        DB::transaction(function () use ($report, $desc, $jeLines) {
            $je = $this->accounting->post(
                description:       $desc,
                date:              Carbon::parse($report->expense_date),
                lines:             $jeLines,
                referenceType:     'personal_expense',
                referenceId:       $report->id,
                isAuto:            false,
                journalSourceType: 'personal_expense',
            );

            $report->update([
                'status'           => PersonalExpenseStatus::Posted,
                'journal_entry_id' => $je->id,
            ]);
        });

        activity()->causedBy(auth()->user())->performedOn($report)->log('posted');
    }

    /**
     * Hoàn tiền cho người chi hộ: Dr 3388, Cr quỹ (1111/1121).
     */
    public function reimburse(PersonalExpenseReport $report, array $data): void
    {
        if ($report->status !== PersonalExpenseStatus::Posted) {
            throw new RuntimeException('Chỉ hoàn tiền phiếu đã ghi sổ.');
        }

        $fund = Fund::findOrFail($data['fund_id']);
        $fundAccount = $this->resolveFundAccount($fund);
        $this->assertDetailAccount($fundAccount, 'Quỹ hoàn tiền');
        $this->assertDetailAccount('3388', 'Phải trả chi hộ');

        [$partnerType, $partnerId] = $this->resolvePartner($report);

        $amount = (int) $report->total_amount;
        $desc   = $data['description'] ?? "Hoàn tiền chi hộ {$report->report_no}";

        DB::transaction(function () use ($report, $fund, $fundAccount, $amount, $desc, $partnerType, $partnerId) {
            $je = $this->accounting->post(
                description:       $desc,
                date:              Carbon::parse(now()),
                lines:             [
                    ['account' => '3388',        'debit' => $amount,  'credit' => 0,       'description' => $desc,
                     'partner_type' => $partnerType, 'partner_id' => $partnerId],
                    ['account' => $fundAccount,  'debit' => 0,        'credit' => $amount, 'description' => $desc],
                ],
                referenceType:     'personal_expense',
                referenceId:       $report->id,
                isAuto:            false,
                journalSourceType: 'personal_expense_reimburse',
            );

            $report->update([
                'status'                    => PersonalExpenseStatus::Reimbursed,
                'reimburse_journal_entry_id' => $je->id,
                'reimbursed_fund_id'         => $fund->id,
                'reimbursed_at'              => now(),
            ]);
        });

        activity()->causedBy(auth()->user())->performedOn($report)->log('reimbursed');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolvePartner(PersonalExpenseReport $report): array
    {
        return match ($report->person_type) {
            'employee'    => ['employee',    $report->employee_id],
            'shareholder' => ['shareholder', $report->shareholder_id],
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
        $acc = AccountCode::where('code', $code)->first();
        if (! $acc) {
            throw new RuntimeException("Tài khoản '{$code}' ({$label}) không tồn tại.");
        }
        if (! $acc->is_detail) {
            throw new RuntimeException("TK {$code} ({$label}) là tài khoản tổng hợp — dùng TK chi tiết.");
        }
    }
}
