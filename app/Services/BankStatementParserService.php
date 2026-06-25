<?php

namespace App\Services;

use App\Models\CustomerBankAccount;
use App\Models\InternalBankAccount;
use App\Models\SupplierBankAccount;
use Carbon\Carbon;

/**
 * Stateless parsing helpers for bank statement Excel files.
 * Mirrors private methods in BankReconciliationService — kept separate to avoid
 * coupling the new batch-import flow to the legacy single-file import path.
 */
class BankStatementParserService
{
    private const COL_PATTERNS = [
        'value_date'          => ['ngày kh thực hiện', 'requesting date'],
        'date'                => ['ngày giao dịch', 'transaction date', 'ngày'],
        'reference'           => ['số bút toán', 'reference number', 'mã giao dịch', 'số tham chiếu', 'mã ct'],
        'counterpart_bank'    => ["ngân hàng đối tác", "remitter's bank", 'ngân hàng'],
        'counterpart_account' => ["tài khoản đích", "remitter's account number", 'tài khoản đối tác'],
        'counterpart_name'    => ["tên tài khoản đối ứng", "remitter's account name", 'tên đối tác'],
        'description'         => ['diễn giải/description', 'diễn giải', 'nội dung', 'mô tả', 'description'],
        'debit'               => ['nợ/debit', 'phát sinh nợ', 'phát sinh giảm', 'tiền ra', 'ghi nợ', 'debit'],
        'credit'              => ['có/credit', 'phát sinh có', 'phát sinh tăng', 'tiền vào', 'ghi có', 'credit'],
        'amount'              => ['số tiền', 'amount', 'giá trị'],
        'type'                => ['loại giao dịch', 'cr/dr'],
        'balance'             => ['số dư/running balance', 'số dư', 'running balance', 'balance'],
    ];

    public function findHeaderRow(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $colMap = $this->detectColumns($row);
            if (isset($colMap['date']) && isset($colMap['description'])) {
                return [$i, $colMap];
            }
        }
        return [null, []];
    }

    public function detectColumns(array $row): array
    {
        $cellBest = [];
        foreach ($row as $colIdx => $cell) {
            if ($cell === null || $cell === '') continue;
            $normalized = mb_strtolower(trim((string) $cell));
            $bestField  = null;
            $bestLen    = 0;
            foreach (self::COL_PATTERNS as $field => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($normalized, $kw) && mb_strlen($kw) > $bestLen) {
                        $bestField = $field;
                        $bestLen   = mb_strlen($kw);
                    }
                }
            }
            if ($bestField !== null) $cellBest[$colIdx] = [$bestField, $bestLen];
        }

        $map = [];
        $fieldBestLen = [];
        foreach ($cellBest as $colIdx => [$field, $len]) {
            if (!isset($fieldBestLen[$field]) || $len > $fieldBestLen[$field]) {
                $map[$field]          = $colIdx;
                $fieldBestLen[$field] = $len;
            }
        }
        return $map;
    }

    public function parseRow(array $row, array $colMap): ?array
    {
        $dateRaw = $this->cell($row, $colMap, 'date');
        if (! $dateRaw) return null;
        $txDate = $this->parseDate($dateRaw);
        if (! $txDate) return null;

        $valueDate = $txDate;
        if ($vdr = $this->cell($row, $colMap, 'value_date')) {
            $parsed = $this->parseDate($vdr);
            if ($parsed) $valueDate = $parsed;
        }

        $description = trim((string) ($this->cell($row, $colMap, 'description') ?? ''));
        if ($description === '') return null;

        $credit = 0; $debit = 0;
        if (isset($colMap['credit']) || isset($colMap['debit'])) {
            $rc = $this->cell($row, $colMap, 'credit');
            $rd = $this->cell($row, $colMap, 'debit');
            $credit = $rc !== null ? abs($this->parseAmount($rc)) : 0;
            $debit  = $rd !== null ? abs($this->parseAmount($rd))  : 0;
        } elseif (isset($colMap['amount'])) {
            $amount  = $this->parseAmount($this->cell($row, $colMap, 'amount'));
            $typeRaw = mb_strtolower((string) ($this->cell($row, $colMap, 'type') ?? ''));
            if ($amount > 0) {
                $isDebit = str_contains($typeRaw, 'dr') || str_contains($typeRaw, 'nợ') || str_contains($typeRaw, 'ra');
                $isDebit ? $debit = $amount : $credit = $amount;
            } elseif ($amount < 0) {
                $debit = abs($amount);
            }
        }

        if ($credit <= 0 && $debit <= 0) return null;

        return [
            'transaction_date'    => $txDate,
            'value_date'          => $valueDate,
            'description'         => $description,
            'reference'           => ($r = $this->cell($row, $colMap, 'reference')) ? trim((string) $r) : null,
            'credit'              => (int) round($credit),
            'debit'               => (int) round($debit),
            'running_balance'     => (int) round($this->parseAmount($this->cell($row, $colMap, 'balance'))),
            'counterpart_bank'    => ($v = $this->cell($row, $colMap, 'counterpart_bank'))    ? trim((string) $v) : null,
            'counterpart_account' => ($v = $this->cell($row, $colMap, 'counterpart_account')) ? trim((string) $v) : null,
            'counterpart_name'    => ($v = $this->cell($row, $colMap, 'counterpart_name'))    ? trim((string) $v) : null,
        ];
    }

    public function categorize(array &$data): void
    {
        $acct = $data['counterpart_account'] ?? null;
        if (! $acct) { $data['tx_type'] = 'unknown'; return; }

        $normalized = preg_replace('/[\s\-]/', '', $acct);

        $internal = InternalBankAccount::where('is_active', true)
            ->whereRaw("REPLACE(REPLACE(account_number, ' ', ''), '-', '') = ?", [$normalized])
            ->first();
        if ($internal) {
            $data['tx_type']             = 'internal_transfer';
            $data['internal_account_id'] = $internal->id;
            if ($data['debit'] > 0) {
                $data['alert_note'] = "Chuyển khoản nội bộ đến {$internal->name} ({$internal->account_number}). Cần hồ sơ đối ứng.";
            }
            return;
        }

        $supplierBank = SupplierBankAccount::where('is_active', true)
            ->where('normalized_account_number', $normalized)->first();
        if ($supplierBank) {
            $data['tx_type']                  = 'supplier_payment';
            $data['supplier_bank_account_id'] = $supplierBank->id;
            return;
        }

        if (($data['credit'] ?? 0) > 0) {
            $customerBank = CustomerBankAccount::where('is_active', true)
                ->where('normalized_account_number', $normalized)->first();
            if ($customerBank) {
                $data['tx_type']                  = 'customer_receipt';
                $data['customer_bank_account_id'] = $customerBank->id;
                return;
            }
        }

        $data['tx_type'] = 'unknown';
    }

    /** Scan first 20 rows to detect the bank account number present in the statement header. */
    public function detectAccountNumber(array $rows): array
    {
        for ($i = 0; $i < min(20, count($rows)); $i++) {
            $rowStr = implode(' ', array_filter($rows[$i], fn($v) => $v !== null && $v !== ''));
            if (empty($rowStr)) continue;

            if (preg_match('/\b(\d[\d\s]{6,20}\d)\b/', $rowStr, $m)) {
                $candidate = preg_replace('/\s+/', '', $m[1]);
                if (strlen($candidate) >= 8) {
                    $bankName = null;
                    if (preg_match('/(Techcombank|TCB|Vietcombank|VCB|BIDV|VietinBank|ACB|MB Bank|TPBank|VPBank|Agribank)/i', $rowStr, $bm)) {
                        $bankName = $bm[1];
                    }
                    return [$bankName, $candidate];
                }
            }
        }
        return [null, null];
    }

    public function computeHash(int $accountId, array $data): string
    {
        $key = $data['reference']
            ? "{$accountId}|ref|{$data['reference']}"
            : "{$accountId}|{$data['transaction_date']}|{$data['credit']}|{$data['debit']}|" . mb_substr($data['description'], 0, 100);
        return md5($key);
    }

    public function cell(array $row, array $colMap, string $field): mixed
    {
        if (! isset($colMap[$field])) return null;
        return $row[$colMap[$field]] ?? null;
    }

    public function parseDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->toDateString();
            } catch (\Throwable) {}
        }
        $str     = trim((string) $value);
        $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d', 'd-m-Y'];
        foreach ($formats as $fmt) {
            try { return Carbon::createFromFormat($fmt, $str)->toDateString(); } catch (\Throwable) {}
        }
        return null;
    }

    public function parseAmount(mixed $value): float
    {
        if ($value === null || $value === '') return 0;
        if (is_numeric($value)) return (float) $value;
        $cleaned = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $cleaned)) {
            $cleaned = str_replace(['.', ','], ['', '.'], $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }
        return (float) $cleaned;
    }
}
