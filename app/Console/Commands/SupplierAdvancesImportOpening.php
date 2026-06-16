<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierOpeningAdvance;
use App\Services\SupplierAdvanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SupplierAdvancesImportOpening extends Command
{
    protected $signature = 'supplier-advances:import-opening
        {--year= : Năm tài chính (mặc định: năm hiện tại)}
        {--dry-run : Chỉ xem kết quả, không lưu}
        {--file= : File CSV chứa dữ liệu (supplier_code,amount,reference_no,original_payment_date,note)}';

    protected $description = 'Nhập số dư ứng trước đầu kỳ NCC từ file CSV hoặc nhập thủ công qua console';

    public function handle(): int
    {
        $year   = (int) ($this->option('year') ?? now()->year);
        $dryRun = (bool) $this->option('dry-run');
        $file   = $this->option('file');

        $this->info("Nhập ứng trước đầu kỳ năm {$year}" . ($dryRun ? ' [DRY RUN]' : ''));

        $rows = [];

        if ($file) {
            if (!file_exists($file)) {
                $this->error("Không tìm thấy file: {$file}");
                return 1;
            }
            $rows = $this->parseCSV($file);
            if (empty($rows)) {
                $this->error('File CSV rỗng hoặc không đúng định dạng.');
                $this->line('Định dạng: supplier_code,amount,reference_no,original_payment_date,note');
                return 1;
            }
        } else {
            // Interactive mode
            $this->line('Nhập dữ liệu ứng trước. Nhấn Ctrl+C để thoát.\n');
            while (true) {
                $supplierCode = $this->ask('Mã NCC (để trống để kết thúc)');
                if (empty($supplierCode)) break;

                $supplier = Supplier::where('code', $supplierCode)->first();
                if (!$supplier) {
                    $this->warn("Không tìm thấy NCC: {$supplierCode}");
                    continue;
                }

                $amount    = (float) $this->ask("Số tiền ứng trước cho {$supplier->name}");
                $reference = $this->ask('Mã tham chiếu (nếu có)') ?: null;
                $payDate   = $this->ask('Ngày chuyển khoản gốc (YYYY-MM-DD, nếu có)') ?: null;
                $note      = $this->ask('Ghi chú') ?: null;

                $rows[] = [
                    'supplier_id'           => $supplier->id,
                    'supplier_name'         => $supplier->name,
                    'amount'                => $amount,
                    'reference_no'          => $reference,
                    'original_payment_date' => $payDate,
                    'note'                  => $note,
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('Không có dữ liệu để nhập.');
            return 0;
        }

        $this->line('');
        $this->table(
            ['NCC', 'Số tiền', 'Tham chiếu', 'Ngày CK gốc', 'Ghi chú'],
            collect($rows)->map(fn ($r) => [
                $r['supplier_name'] ?? Supplier::find($r['supplier_id'])?->name ?? $r['supplier_id'],
                number_format($r['amount']),
                $r['reference_no'] ?? '—',
                $r['original_payment_date'] ?? '—',
                $r['note'] ?? '—',
            ])
        );

        if ($dryRun) {
            $this->warn('[DRY RUN] Không lưu dữ liệu. Chạy lại không có --dry-run để lưu.');
            return 0;
        }

        if (!$this->confirm('Xác nhận nhập ' . count($rows) . ' khoản ứng trước?')) {
            $this->line('Đã hủy.');
            return 0;
        }

        $service = app(SupplierAdvanceService::class);
        $success = 0;
        $errors  = 0;

        DB::transaction(function () use ($rows, $year, $service, &$success, &$errors) {
            foreach ($rows as $row) {
                try {
                    $data = [
                        'supplier_id'           => $row['supplier_id'],
                        'fiscal_year'           => $year,
                        'opening_date'          => now()->startOfYear()->toDateString(),
                        'amount'                => $row['amount'],
                        'reference_no'          => $row['reference_no'] ?? null,
                        'original_payment_date' => $row['original_payment_date'] ?? null,
                        'notes'                 => $row['note'] ?? null,
                    ];
                    // Use first admin user as creator
                    auth()->loginUsingId(1);
                    $service->create($data);
                    $success++;
                } catch (\Exception $e) {
                    $this->error("Lỗi: " . $e->getMessage());
                    $errors++;
                }
            }
        });

        $this->info("Đã nhập: {$success} khoản. Lỗi: {$errors}.");
        return $errors > 0 ? 1 : 0;
    }

    private function parseCSV(string $file): array
    {
        $rows = [];
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < 2) continue;
            [$supplierCode, $amount] = $line;
            $reference = $line[2] ?? null;
            $payDate   = $line[3] ?? null;
            $note      = $line[4] ?? null;

            $supplier = Supplier::where('code', trim($supplierCode))->first();
            if (!$supplier) {
                $this->warn("Bỏ qua: không tìm thấy NCC code={$supplierCode}");
                continue;
            }

            $rows[] = [
                'supplier_id'           => $supplier->id,
                'supplier_name'         => $supplier->name,
                'amount'                => (float) str_replace([',', ' '], '', trim($amount)),
                'reference_no'          => trim($reference ?: ''),
                'original_payment_date' => trim($payDate ?: '') ?: null,
                'note'                  => trim($note ?: '') ?: null,
            ];
        }

        fclose($handle);
        return $rows;
    }
}
