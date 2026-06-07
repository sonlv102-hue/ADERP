<?php

namespace App\Services;

use App\Enums\BankTransactionStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\InternalBankAccount;
use App\Models\JournalEntry;
use App\Models\SupplierBankAccount;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class BankReconciliationService
{
    // Longer keyword = more specific = wins in conflict resolution
    private const COL_PATTERNS = [
        'value_date'         => ['ngày kh thực hiện', 'requesting date'],
        'date'               => ['ngày giao dịch', 'transaction date', 'ngày'],
        'reference'          => ['số bút toán', 'reference number', 'mã giao dịch', 'số tham chiếu', 'mã ct'],
        'counterpart_bank'   => ["ngân hàng đối tác", "remitter's bank", 'ngân hàng'],
        'counterpart_account'=> ["tài khoản đích", "remitter's account number", 'tài khoản đối tác'],
        'counterpart_name'   => ["tên tài khoản đối ứng", "remitter's account name", 'tên đối tác'],
        'description'        => ['diễn giải/description', 'diễn giải', 'nội dung', 'mô tả', 'description'],
        'debit'              => ['nợ/debit', 'phát sinh nợ', 'phát sinh giảm', 'tiền ra', 'ghi nợ', 'debit'],
        'credit'             => ['có/credit', 'phát sinh có', 'phát sinh tăng', 'tiền vào', 'ghi có', 'credit'],
        'amount'             => ['số tiền', 'amount', 'giá trị'],
        'type'               => ['loại giao dịch', 'cr/dr'],
        'balance'            => ['số dư/running balance', 'số dư', 'running balance', 'balance'],
    ];

    /**
     * Import giao dịch từ file Excel của Techcombank.
     * Trả về: ['imported' => int, 'skipped' => int, 'errors' => string[]]
     */
    public function importExcel(BankAccount $account, UploadedFile $file): array
    {
        $batchId = Str::uuid()->toString();
        $rows    = Excel::toArray([], $file)[0] ?? [];

        [$headerIndex, $colMap] = $this->findHeaderRow($rows);

        if ($headerIndex === null) {
            throw new \RuntimeException(
                'Không nhận dạng được cấu trúc file. Đảm bảo file là sao kê Techcombank với cột Ngày, Nội dung, Số tiền.'
            );
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            try {
                $data = $this->parseRow($row, $colMap);
                if (! $data) {
                    $skipped++;
                    continue;
                }

                $this->categorize($data);

                // Use reference (e.g. FT26091449066122) when available — most reliable dedup key
                $hashKey = $data['reference']
                    ? "{$account->id}|ref|{$data['reference']}"
                    : "{$account->id}|{$data['transaction_date']}|{$data['credit']}|{$data['debit']}|" . mb_substr($data['description'], 0, 100);
                $hash = md5($hashKey);

                $exists = BankTransaction::where('bank_account_id', $account->id)
                    ->where('import_hash', $hash)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $account->transactions()->create([
                    ...$data,
                    'import_batch' => $batchId,
                    'import_hash'  => $hash,
                    'status'       => BankTransactionStatus::Pending,
                    'created_by'   => auth()->id(),
                ]);

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Dòng " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        return compact('imported', 'skipped', 'errors', 'batchId');
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function findHeaderRow(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $colMap = $this->detectColumns($row);
            if (isset($colMap['date']) && isset($colMap['description'])) {
                return [$i, $colMap];
            }
        }
        return [null, []];
    }

    /**
     * Best-match column detection: for each cell, pick the field whose keyword
     * matches longest (most specific). Prevents "ngày" from stealing "ngày giao dịch".
     */
    private function detectColumns(array $row): array
    {
        // Step 1: for each cell, find the best matching field
        $cellBest = []; // colIdx => [field, matchLen]
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

            if ($bestField !== null) {
                $cellBest[$colIdx] = [$bestField, $bestLen];
            }
        }

        // Step 2: build map — if multiple columns match same field, pick longest match
        $map         = [];
        $fieldBestLen = [];
        foreach ($cellBest as $colIdx => [$field, $len]) {
            if (!isset($fieldBestLen[$field]) || $len > $fieldBestLen[$field]) {
                $map[$field]         = $colIdx;
                $fieldBestLen[$field] = $len;
            }
        }

        return $map;
    }

    private function parseRow(array $row, array $colMap): ?array
    {
        // Use posting date (Col "Ngày giao dịch") as transaction_date
        $dateRaw = $this->cell($row, $colMap, 'date');
        if (! $dateRaw) return null;

        $txDate = $this->parseDate($dateRaw);
        if (! $txDate) return null;

        // Requesting date (Col "Ngày KH thực hiện") as value_date, fallback to txDate
        $valueDate = $txDate;
        $valueDateRaw = $this->cell($row, $colMap, 'value_date');
        if ($valueDateRaw) {
            $parsed = $this->parseDate($valueDateRaw);
            if ($parsed) $valueDate = $parsed;
        }

        $description = trim((string) ($this->cell($row, $colMap, 'description') ?? ''));
        if ($description === '') return null;

        // Resolve credit/debit
        $credit = 0;
        $debit  = 0;

        if (isset($colMap['credit']) || isset($colMap['debit'])) {
            // Techcombank: separate Credit/Debit columns
            // Debit values are stored as negative strings e.g. "-24663609"
            $rawCredit = $this->cell($row, $colMap, 'credit');
            $rawDebit  = $this->cell($row, $colMap, 'debit');
            $credit = $rawCredit !== null ? abs($this->parseAmount($rawCredit)) : 0;
            $debit  = $rawDebit  !== null ? abs($this->parseAmount($rawDebit))  : 0;
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

        $reference       = $this->cell($row, $colMap, 'reference');
        $counterpartBank = $this->cell($row, $colMap, 'counterpart_bank');
        $counterpartAcct = $this->cell($row, $colMap, 'counterpart_account');
        $counterpartName = $this->cell($row, $colMap, 'counterpart_name');

        return [
            'transaction_date'    => $txDate,
            'value_date'          => $valueDate,
            'description'         => $description,
            'reference'           => $reference ? trim((string) $reference) : null,
            'credit'              => (int) round($credit),
            'debit'               => (int) round($debit),
            'running_balance'     => (int) round($this->parseAmount($this->cell($row, $colMap, 'balance'))),
            'counterpart_bank'    => $counterpartBank ? trim((string) $counterpartBank) : null,
            'counterpart_account' => $counterpartAcct ? trim((string) $counterpartAcct) : null,
            'counterpart_name'    => $counterpartName ? trim((string) $counterpartName) : null,
        ];
    }

    /**
     * Phân loại giao dịch dựa trên số TK bên đối ứng.
     * Tra cứu supplier_bank_accounts và internal_bank_accounts.
     */
    private function categorize(array &$data): void
    {
        $acct = $data['counterpart_account'] ?? null;
        if (! $acct) {
            $data['tx_type'] = 'unknown';
            return;
        }

        // Normalise: remove spaces/dashes
        $normalized = preg_replace('/[\s\-]/', '', $acct);

        // Check internal accounts first (higher priority)
        $internal = InternalBankAccount::where('is_active', true)
            ->whereRaw("REPLACE(account_number, ' ', '') = ?", [$normalized])
            ->first();

        if ($internal) {
            $data['tx_type']           = 'internal_transfer';
            $data['internal_account_id'] = $internal->id;
            // Alert for debit (tiền ra) internal transfers
            if ($data['debit'] > 0) {
                $data['alert_note'] = "Chuyển khoản nội bộ đến {$internal->name} ({$internal->account_number}). Cần hồ sơ đối ứng. Nếu là tạm ứng, cần hoàn ứng sau khi sử dụng.";
            }
            return;
        }

        // Check supplier bank accounts
        $supplierBank = SupplierBankAccount::where('is_active', true)
            ->whereRaw("REPLACE(account_number, ' ', '') = ?", [$normalized])
            ->first();

        if ($supplierBank) {
            $data['tx_type']                  = 'supplier_payment';
            $data['supplier_bank_account_id'] = $supplierBank->id;
            return;
        }

        $data['tx_type'] = 'unknown';
    }

    private function cell(array $row, array $colMap, string $field): mixed
    {
        if (! isset($colMap[$field])) return null;
        $idx = $colMap[$field];
        return $row[$idx] ?? null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            // Excel serial date
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->toDateString();
            } catch (\Throwable) {}
        }

        $str = trim((string) $value);
        $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d', 'd-m-Y'];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $str)->toDateString();
            } catch (\Throwable) {}
        }
        return null;
    }

    private function parseAmount(mixed $value): float
    {
        if ($value === null || $value === '') return 0;
        if (is_numeric($value)) return (float) $value;
        // Remove currency symbols, dots as thousands separators, keep minus
        $cleaned = preg_replace('/[^\d,.\-]/', '', (string) $value);
        // Vietnamese format: 1.234.567,89 → 1234567.89
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $cleaned)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }
        return (float) $cleaned;
    }

    public function createTransaction(BankAccount $account, array $data): BankTransaction
    {
        return DB::transaction(function () use ($account, $data) {
            $tx = $account->transactions()->create([
                'transaction_date' => $data['transaction_date'],
                'value_date'       => $data['value_date'] ?? $data['transaction_date'],
                'description'      => $data['description'],
                'reference'        => $data['reference'] ?? null,
                'debit'            => $data['debit']  ?? 0,
                'credit'           => $data['credit'] ?? 0,
                'status'           => BankTransactionStatus::Pending,
                'created_by'       => auth()->id(),
            ]);

            return $tx;
        });
    }

    public function reconcile(BankTransaction $tx, int $journalEntryId): void
    {
        if ($tx->status === BankTransactionStatus::Reconciled) {
            throw new \RuntimeException('Giao dịch đã được đối chiếu.');
        }

        $je = JournalEntry::where('status', 'posted')->findOrFail($journalEntryId);

        $tx->update([
            'status'          => BankTransactionStatus::Reconciled,
            'journal_entry_id'=> $je->id,
            'reconciled_at'   => now(),
            'reconciled_by'   => auth()->id(),
        ]);
    }

    public function unreconcile(BankTransaction $tx): void
    {
        if ($tx->status !== BankTransactionStatus::Reconciled) {
            throw new \RuntimeException('Giao dịch chưa được đối chiếu.');
        }

        $tx->update([
            'status'           => BankTransactionStatus::Pending,
            'journal_entry_id' => null,
            'reconciled_at'    => null,
            'reconciled_by'    => null,
        ]);
    }

    /**
     * Phân loại lại các giao dịch tx_type = 'unknown' dựa trên danh sách TK nội bộ / NCC hiện tại.
     * Trả về: ['updated' => int, 'total' => int]
     */
    public function recategorizeUnknown(BankAccount $account): array
    {
        $transactions = BankTransaction::where('bank_account_id', $account->id)
            ->where(function ($q) {
                $q->where('tx_type', 'unknown')->orWhereNull('tx_type');
            })
            ->whereNotNull('counterpart_account')
            ->get();

        if ($transactions->isEmpty()) {
            return ['updated' => 0, 'total' => 0];
        }

        $internalAccounts = InternalBankAccount::where('is_active', true)->get();
        $supplierBanks    = SupplierBankAccount::where('is_active', true)->get();

        $updated = 0;
        foreach ($transactions as $tx) {
            $normalized = preg_replace('/[\s\-]/', '', $tx->counterpart_account);

            $internal = $internalAccounts->first(
                fn ($a) => preg_replace('/[\s\-]/', '', $a->account_number) === $normalized
            );

            if ($internal) {
                $data = ['tx_type' => 'internal_transfer', 'internal_account_id' => $internal->id];
                if ($tx->debit > 0) {
                    $data['alert_note'] = "Chuyển khoản nội bộ đến {$internal->name} ({$internal->account_number}). Cần hồ sơ đối ứng. Nếu là tạm ứng, cần hoàn ứng sau khi sử dụng.";
                }
                $tx->update($data);
                $updated++;
                continue;
            }

            $supplier = $supplierBanks->first(
                fn ($a) => preg_replace('/[\s\-]/', '', $a->account_number) === $normalized
            );

            if ($supplier) {
                $tx->update(['tx_type' => 'supplier_payment', 'supplier_bank_account_id' => $supplier->id]);
                $updated++;
            }
        }

        return ['updated' => $updated, 'total' => $transactions->count()];
    }
}
