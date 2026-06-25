<?php

namespace App\Services;

use App\Enums\BankTransactionMatchStatus;
use App\Enums\BankTransactionStatus;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementImportRow;
use App\Models\BankTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class BankStatementImportService
{
    private const MAX_FILES      = 20;
    private const SYNC_MAX_FILES = 5;
    private const SYNC_MAX_ROWS  = 5000;

    public function __construct(private BankStatementParserService $parser) {}

    /** Upload và parse nhiều file. Trả về batch đã parsed. */
    public function uploadAndParse(BankAccount $account, array $files, int $userId): BankStatementImportBatch
    {
        if (count($files) > self::MAX_FILES) {
            throw new \RuntimeException('Tối đa ' . self::MAX_FILES . ' file mỗi lần import.');
        }

        $batch = BankStatementImportBatch::create([
            'bank_account_id' => $account->id,
            'source_type'     => 'excel',
            'total_files'     => count($files),
            'status'          => 'uploaded',
            'uploaded_by'     => $userId,
        ]);

        Storage::makeDirectory("bank_imports/{$batch->id}");

        foreach ($files as $file) {
            $this->processFile($batch, $account, $file);
        }

        $this->recalcBatchStats($batch);
        $batch->update(['status' => 'parsed']);

        // Guard against very large batches (queue not implemented yet)
        if (count($files) > self::SYNC_MAX_FILES || $batch->total_rows_detected > self::SYNC_MAX_ROWS) {
            \Log::warning("BankStatementImportBatch #{$batch->id}: vượt ngưỡng sync ({$batch->total_rows_detected} dòng).");
        }

        return $batch->fresh();
    }

    private function processFile(BankStatementImportBatch $batch, BankAccount $account, UploadedFile $file): void
    {
        $import = BankStatementImport::create([
            'batch_id'          => $batch->id,
            'bank_account_id'   => $account->id,
            'original_filename' => $file->getClientOriginalName(),
            'status'            => 'uploaded',
        ]);

        try {
            $path = $file->storeAs("bank_imports/{$batch->id}", $import->id . '_' . $file->getClientOriginalName());
            $import->update(['file_path' => $path]);

            $rows = Excel::toArray([], $file)[0] ?? [];
            [$detectedBank, $detectedAcctNum] = $this->parser->detectAccountNumber($rows);

            $isMismatch = false;
            if ($detectedAcctNum !== null) {
                $ndDet = preg_replace('/[\s\-\.]/', '', $detectedAcctNum);
                $ndExp = preg_replace('/[\s\-\.]/', '', $account->account_number);
                if ($ndDet !== $ndExp) {
                    $isMismatch = true;
                    $import->update([
                        'detected_bank_name'      => $detectedBank,
                        'detected_account_number' => $detectedAcctNum,
                        'error_message'           => "Số TK trong file ({$detectedAcctNum}) không khớp với TK đang mở ({$account->account_number}). Vẫn import được — hãy kiểm tra lại file.",
                    ]);
                }
            }

            [$headerIndex, $colMap] = $this->parser->findHeaderRow($rows);
            if ($headerIndex === null) {
                $import->update(['status' => 'error', 'error_message' => 'Không nhận dạng được cột Ngày/Nội dung trong file.']);
                return;
            }

            // Collect hashes already queued in this batch (dedup cross-file)
            $batchHashes = BankStatementImportRow::where('batch_id', $batch->id)
                ->whereNotNull('import_hash')->pluck('import_hash')->flip()->toArray();

            $rowNum = 0; $valid = 0; $dup = 0; $err = 0;
            $fromDate = null; $toDate = null;

            for ($i = $headerIndex + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
                $rowNum++;

                $data = $this->parser->parseRow($row, $colMap);
                if (! $data) { $err++; continue; }

                $this->parser->categorize($data);
                $hash   = $this->parser->computeHash($account->id, $data);
                $dbDup  = BankTransaction::where('bank_account_id', $account->id)->where('import_hash', $hash)->exists();
                $status = ($dbDup || isset($batchHashes[$hash])) ? 'duplicate' : 'valid';

                if ($status === 'valid') { $valid++; $batchHashes[$hash] = true; } else { $dup++; }

                $d = $data['transaction_date'];
                if ($d) {
                    if (! $fromDate || $d < $fromDate) $fromDate = $d;
                    if (! $toDate   || $d > $toDate)   $toDate   = $d;
                }

                BankStatementImportRow::create([
                    'batch_id'                    => $batch->id,
                    'import_id'                   => $import->id,
                    'row_number'                  => $rowNum,
                    'transaction_date'            => $d,
                    'transaction_no'              => $data['reference'],
                    'description'                 => $data['description'],
                    'counterparty_account_number' => $data['counterpart_account'],
                    'counterparty_account_name'   => $data['counterpart_name'],
                    'counterparty_bank_name'      => $data['counterpart_bank'],
                    'debit_amount'                => $data['debit'],
                    'credit_amount'               => $data['credit'],
                    'balance_after'               => $data['running_balance'],
                    'raw_data_json'               => ['tx_type' => $data['tx_type'] ?? 'unknown'],
                    'parse_status'                => $status,
                    'import_hash'                 => $status === 'valid' ? $hash : null,
                ]);
            }

            $import->update([
                'detected_bank_name'      => $detectedBank,
                'detected_account_number' => $detectedAcctNum,
                'statement_from_date'     => $fromDate,
                'statement_to_date'       => $toDate,
                'total_rows_detected'     => $rowNum,
                'total_rows_valid'        => $valid,
                'total_rows_duplicate'    => $dup,
                'total_rows_error'        => $err,
                'status'                  => $isMismatch ? 'account_mismatch' : 'parsed',
            ]);
        } catch (\Throwable $e) {
            $import->update(['status' => 'error', 'error_message' => $e->getMessage()]);
        }
    }

    public function getBatchPreview(BankStatementImportBatch $batch): array
    {
        $imports  = BankStatementImport::where('batch_id', $batch->id)->get();
        $rows     = BankStatementImportRow::where('batch_id', $batch->id)
            ->orderBy('import_id')->orderBy('row_number')->get();
        $validSum = $rows->where('parse_status', 'valid');

        return [
            'batch'   => [
                'id'                   => $batch->id,
                'status'               => $batch->status,
                'total_files'          => $batch->total_files,
                'total_rows_detected'  => $batch->total_rows_detected,
                'total_rows_valid'     => $batch->total_rows_valid,
                'total_rows_duplicate' => $batch->total_rows_duplicate,
                'total_rows_error'     => $batch->total_rows_error,
                'total_credit'         => $validSum->sum('credit_amount'),
                'total_debit'          => $validSum->sum('debit_amount'),
            ],
            'imports' => $imports->map(fn($imp) => [
                'id'                      => $imp->id,
                'filename'                => $imp->original_filename,
                'status'                  => $imp->status,
                'detected_account_number' => $imp->detected_account_number,
                'total_rows_detected'     => $imp->total_rows_detected,
                'total_rows_valid'        => $imp->total_rows_valid,
                'total_rows_duplicate'    => $imp->total_rows_duplicate,
                'total_rows_error'        => $imp->total_rows_error,
                'statement_from_date'     => $imp->statement_from_date?->toDateString(),
                'statement_to_date'       => $imp->statement_to_date?->toDateString(),
                'error_message'           => $imp->error_message,
            ])->values(),
            'rows'    => $rows->map(fn($r) => [
                'id'                          => $r->id,
                'import_id'                   => $r->import_id,
                'row_number'                  => $r->row_number,
                'transaction_date'            => $r->transaction_date?->toDateString(),
                'transaction_no'              => $r->transaction_no,
                'description'                 => $r->description,
                'counterparty_account_number' => $r->counterparty_account_number,
                'counterparty_account_name'   => $r->counterparty_account_name,
                'debit_amount'                => $r->debit_amount,
                'credit_amount'               => $r->credit_amount,
                'balance_after'               => $r->balance_after,
                'parse_status'                => $r->parse_status,
                'error_message'               => $r->error_message,
                'tx_type'                     => $r->raw_data_json['tx_type'] ?? 'unknown',
            ])->values(),
        ];
    }

    /** Xác nhận import: tạo bank_transactions cho các dòng valid. */
    public function confirmBatch(BankStatementImportBatch $batch, int $userId): array
    {
        $validRows = BankStatementImportRow::where('batch_id', $batch->id)
            ->where('parse_status', 'valid')->get();

        $account  = $batch->bankAccount;
        $imported = 0;

        DB::transaction(function () use ($validRows, $account, $batch, $userId, &$imported) {
            foreach ($validRows as $row) {
                // Final dedup guard
                if (BankTransaction::where('bank_account_id', $account->id)->where('import_hash', $row->import_hash)->exists()) {
                    $row->update(['parse_status' => 'duplicate']);
                    continue;
                }

                $tx = $account->transactions()->create([
                    'transaction_date'    => $row->transaction_date,
                    'value_date'          => $row->transaction_date,
                    'description'         => $row->description,
                    'reference'           => $row->transaction_no,
                    'credit'              => $row->credit_amount,
                    'debit'               => $row->debit_amount,
                    'running_balance'     => $row->balance_after,
                    'counterpart_account' => $row->counterparty_account_number,
                    'counterpart_name'    => $row->counterparty_account_name,
                    'counterpart_bank'    => $row->counterparty_bank_name,
                    'tx_type'             => $row->raw_data_json['tx_type'] ?? 'unknown',
                    'import_batch'        => (string) $batch->id,
                    'import_hash'         => $row->import_hash,
                    'status'              => BankTransactionStatus::Pending,
                    'match_status'        => BankTransactionMatchStatus::Unmatched,
                    'created_by'          => $userId,
                ]);

                $row->update(['bank_transaction_id' => $tx->id]);
                $imported++;
            }

            $batch->update(['status' => 'imported', 'confirmed_by' => $userId, 'confirmed_at' => now()]);
        });

        return ['imported' => $imported];
    }

    public function cancelBatch(BankStatementImportBatch $batch): void
    {
        Storage::deleteDirectory("bank_imports/{$batch->id}");
        $batch->update(['status' => 'cancelled']);
    }

    private function recalcBatchStats(BankStatementImportBatch $batch): void
    {
        $s = BankStatementImport::where('batch_id', $batch->id)
            ->selectRaw('SUM(total_rows_detected) rd, SUM(total_rows_valid) rv, SUM(total_rows_duplicate) rdup, SUM(total_rows_error) re')
            ->first();

        $batch->update([
            'total_rows_detected'  => $s?->rd   ?? 0,
            'total_rows_valid'     => $s?->rv   ?? 0,
            'total_rows_duplicate' => $s?->rdup ?? 0,
            'total_rows_error'     => $s?->re   ?? 0,
        ]);
    }
}
