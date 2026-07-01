<?php

namespace App\Services;

use App\Exports\EmployeeImportErrorExport;
use App\Exports\EmployeeImportTemplateExport;
use App\Imports\EmployeeRawImport;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class EmployeeImportService
{
    private const CACHE_PREFIX = 'employee_import:';
    private const CACHE_TTL_MINUTES = 15;
    private const MAX_ROWS = 2000;

    public function __construct(private EmployeeImportRowValidator $validator) {}

    public function generateTemplate(): EmployeeImportTemplateExport
    {
        return new EmployeeImportTemplateExport();
    }

    /** @return array{preview_id:string, rows:array, summary:array} */
    public function previewImport(UploadedFile $file, bool $updateExisting): array
    {
        $reader = new EmployeeRawImport();
        Excel::import($reader, $file);
        $dataRows = $reader->rows->slice(1)->values();

        if ($dataRows->count() > self::MAX_ROWS) {
            throw new RuntimeException('File vượt quá giới hạn ' . self::MAX_ROWS . ' dòng.');
        }

        $parsed = $this->parseAndValidate($dataRows, $updateExisting);

        $previewId = (string) Str::uuid();
        Cache::put(self::CACHE_PREFIX . $previewId, $parsed, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'preview_id' => $previewId,
            'rows'       => $parsed,
            'summary'    => $this->summarize($parsed),
        ];
    }

    /** @return array{success:bool, created:int, updated:int, summary:array, errors:string[], error_file_id?:string} */
    public function confirmImport(string $previewId, bool $updateExisting): array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $previewId);
        if ($cached === null) {
            throw new RuntimeException('Phiên xem trước đã hết hạn, vui lòng tải file lên lại.');
        }

        // Re-validate: dữ liệu DB hoặc tùy chọn update có thể đã thay đổi từ lúc preview
        $rawRows = array_map(fn ($e) => $e['data'], $cached);
        $reparsed = $this->parseAndValidate(collect($rawRows)->map(fn ($d) => $this->dataToRawRow($d)), $updateExisting);

        $allErrors = array_merge(...array_column($reparsed, 'errors')) ?: [];

        if (!empty($allErrors)) {
            $errorFileId = (string) Str::uuid();
            Cache::put(self::CACHE_PREFIX . 'err:' . $errorFileId, $reparsed, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return [
                'success'       => false,
                'created'       => 0,
                'updated'       => 0,
                'summary'       => $this->summarize($reparsed),
                'errors'        => $allErrors,
                'error_file_id' => $errorFileId,
            ];
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($reparsed, &$created, &$updated) {
            foreach ($reparsed as $entry) {
                $data = $this->validator->toEmployeeData($entry['data']);
                $existing = Employee::withTrashed()->whereRaw('UPPER(code) = ?', [$data['code']])->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($data);
                    $updated++;
                } else {
                    Employee::create([...$data, 'created_by' => auth()->id()]);
                    $created++;
                }
            }
        });

        Cache::forget(self::CACHE_PREFIX . $previewId);

        return ['success' => true, 'created' => $created, 'updated' => $updated, 'summary' => $this->summarize($reparsed), 'errors' => []];
    }

    public function errorsExport(string $errorFileId): EmployeeImportErrorExport
    {
        $cached = Cache::get(self::CACHE_PREFIX . 'err:' . $errorFileId);
        if ($cached === null) {
            throw new RuntimeException('File lỗi đã hết hạn hoặc không tồn tại.');
        }

        return new EmployeeImportErrorExport($cached);
    }

    private function parseAndValidate(iterable $dataRows, bool $updateExisting): array
    {
        $seenCodes = [];
        $existsInDb = fn (string $code) => Employee::withTrashed()->whereRaw('UPPER(code) = ?', [$code])->exists();

        $parsed = [];
        foreach ($dataRows as $i => $row) {
            $rowNum = $i + 2;
            $mapped = $this->validator->mapRow(is_array($row) ? $row : $row->toArray());
            $errors = $this->validator->validateRow($mapped, $rowNum, $seenCodes, $updateExisting, $existsInDb);
            $parsed[] = ['row' => $rowNum, 'data' => $mapped, 'errors' => $errors];
        }

        return $parsed;
    }

    /** Chuyển data đã map ngược lại row thô (fixed-index) để tái sử dụng parseAndValidate khi confirm */
    private function dataToRawRow(array $d): array
    {
        return [
            0 => $d['code'], 1 => $d['name'], 2 => $d['gender_raw'] ?? '',
            3 => $d['birth_date'], 4 => $d['national_id'], 5 => $d['national_id_issue_date'],
            6 => $d['national_id_issue_place'], 7 => $d['phone'], 8 => $d['email'],
            9 => $d['address'], 10 => $d['department'], 11 => $d['position'],
            12 => $d['hire_date'], 13 => $d['employment_type_raw'] ?? '', 14 => $d['status_raw'] ?? '',
            15 => $d['base_salary_raw'], 16 => $d['allowance_raw'], 17 => $d['pit_tax_code'],
            18 => $d['social_insurance_no'], 19 => $d['bank_account_no'], 20 => $d['bank_name'], 21 => $d['notes'],
        ];
    }

    private function summarize(array $parsed): array
    {
        $errorRows = count(array_filter($parsed, fn ($e) => !empty($e['errors'])));

        return [
            'total_rows' => count($parsed),
            'valid_rows' => count($parsed) - $errorRows,
            'error_rows' => $errorRows,
        ];
    }
}
