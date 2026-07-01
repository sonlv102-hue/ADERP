<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Support\Carbon;

/**
 * Map + validate 1 dòng Excel theo đúng thứ tự cột của Mau_upload_nhan_vien.xlsx
 * (không dùng WithHeadingRow vì header tiếng Việt có dấu không slug ổn định).
 */
class EmployeeImportRowValidator
{
    public const INVALID_DATE = '__invalid_date__';

    public function mapRow(array $row): array
    {
        return [
            'code'                       => mb_strtoupper(trim((string) ($row[0] ?? ''))),
            'name'                       => trim((string) ($row[1] ?? '')),
            'gender_raw'                 => trim((string) ($row[2] ?? '')),
            'birth_date'                 => $this->parseDate($row[3] ?? null),
            'national_id'                => trim((string) ($row[4] ?? '')) ?: null,
            'national_id_issue_date'     => $this->parseDate($row[5] ?? null),
            'national_id_issue_place'    => trim((string) ($row[6] ?? '')) ?: null,
            'phone'                      => trim((string) ($row[7] ?? '')) ?: null,
            'email'                      => trim((string) ($row[8] ?? '')) ?: null,
            'address'                    => trim((string) ($row[9] ?? '')) ?: null,
            'department'                 => trim((string) ($row[10] ?? '')),
            'position'                   => trim((string) ($row[11] ?? '')) ?: null,
            'hire_date'                  => $this->parseDate($row[12] ?? null),
            'employment_type_raw'        => trim((string) ($row[13] ?? '')),
            'status_raw'                 => trim((string) ($row[14] ?? '')),
            'base_salary_raw'            => $row[15] ?? null,
            'allowance_raw'              => $row[16] ?? null,
            'pit_tax_code'               => trim((string) ($row[17] ?? '')) ?: null,
            'social_insurance_no'        => trim((string) ($row[18] ?? '')) ?: null,
            'bank_account_no'            => trim((string) ($row[19] ?? '')) ?: null,
            'bank_name'                  => trim((string) ($row[20] ?? '')) ?: null,
            'notes'                      => trim((string) ($row[21] ?? '')) ?: null,
        ];
    }

    /** @return string[] danh sách lỗi (rỗng = hợp lệ) */
    public function validateRow(array $r, int $rowNum, array &$seenCodes, bool $updateExisting, \Closure $existsInDb): array
    {
        $errors = [];

        if ($r['code'] === '') {
            $errors[] = "Dòng {$rowNum}: Mã nhân viên bị trống.";
        } elseif (isset($seenCodes[$r['code']])) {
            $errors[] = "Dòng {$rowNum}: Mã nhân viên \"{$r['code']}\" bị trùng với dòng {$seenCodes[$r['code']]} trong file.";
        } else {
            $seenCodes[$r['code']] = $rowNum;
            if (!$updateExisting && $existsInDb($r['code'])) {
                $errors[] = "Dòng {$rowNum}: Mã nhân viên \"{$r['code']}\" đã tồn tại. Bật \"Cho phép cập nhật\" nếu muốn ghi đè.";
            }
        }

        if ($r['name'] === '') {
            $errors[] = "Dòng {$rowNum}: Họ và tên bị trống.";
        }
        if ($r['department'] === '') {
            $errors[] = "Dòng {$rowNum}: Phòng ban bị trống.";
        }

        if ($r['gender_raw'] !== '' && $this->normalizeGender($r['gender_raw']) === null) {
            $errors[] = "Dòng {$rowNum}: Giới tính \"{$r['gender_raw']}\" không hợp lệ (Nam/Nữ).";
        }
        if ($r['birth_date'] === self::INVALID_DATE) {
            $errors[] = "Dòng {$rowNum}: Ngày sinh sai định dạng (dd/mm/yyyy hoặc yyyy-mm-dd).";
        }
        if ($r['national_id_issue_date'] === self::INVALID_DATE) {
            $errors[] = "Dòng {$rowNum}: Ngày cấp CCCD sai định dạng.";
        }
        if ($r['hire_date'] === self::INVALID_DATE) {
            $errors[] = "Dòng {$rowNum}: Ngày vào làm sai định dạng.";
        }
        if ($r['email'] && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Dòng {$rowNum}: Email \"{$r['email']}\" không đúng định dạng.";
        }
        if ($r['phone'] && !preg_match('/^[0-9+\-. ]{8,15}$/', $r['phone'])) {
            $errors[] = "Dòng {$rowNum}: Số điện thoại \"{$r['phone']}\" không hợp lệ.";
        }
        if ($r['base_salary_raw'] !== null && $r['base_salary_raw'] !== '' && !is_numeric($r['base_salary_raw'])) {
            $errors[] = "Dòng {$rowNum}: Lương cơ bản phải là số.";
        }
        if ($r['allowance_raw'] !== null && $r['allowance_raw'] !== '' && !is_numeric($r['allowance_raw'])) {
            $errors[] = "Dòng {$rowNum}: Phụ cấp phải là số.";
        }
        if ($r['employment_type_raw'] !== '' && $this->normalizeEmploymentType($r['employment_type_raw']) === null) {
            $errors[] = "Dòng {$rowNum}: Loại hợp đồng \"{$r['employment_type_raw']}\" không hợp lệ.";
        }
        if ($r['status_raw'] !== '' && $this->normalizeStatus($r['status_raw']) === null) {
            $errors[] = "Dòng {$rowNum}: Trạng thái \"{$r['status_raw']}\" không hợp lệ.";
        }

        return $errors;
    }

    public function toEmployeeData(array $r): array
    {
        return [
            'code'                    => $r['code'],
            'name'                    => $r['name'],
            'gender'                  => $this->normalizeGender($r['gender_raw']),
            'birth_date'              => $this->nullableDate($r['birth_date']),
            'national_id'             => $r['national_id'],
            'national_id_issue_date'  => $this->nullableDate($r['national_id_issue_date']),
            'national_id_issue_place' => $r['national_id_issue_place'],
            'phone'                   => $r['phone'],
            'email'                   => $r['email'],
            'address'                 => $r['address'],
            'department'              => $r['department'],
            'position'                => $r['position'],
            'hire_date'               => $this->nullableDate($r['hire_date']),
            'employment_type'         => $this->normalizeEmploymentType($r['employment_type_raw']) ?? EmploymentType::FullTime->value,
            'status'                  => $this->normalizeStatus($r['status_raw']) ?? EmployeeStatus::Active->value,
            'base_salary'             => (float) ($r['base_salary_raw'] ?: 0),
            'allowance'               => (float) ($r['allowance_raw'] ?: 0),
            'pit_tax_code'            => $r['pit_tax_code'],
            'social_insurance_no'     => $r['social_insurance_no'],
            'bank_account_no'         => $r['bank_account_no'],
            'bank_name'               => $r['bank_name'],
            'notes'                   => $r['notes'],
        ];
    }

    private function nullableDate(?string $val): ?string
    {
        return ($val && $val !== self::INVALID_DATE) ? $val : null;
    }

    private function parseDate($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance($raw)->format('Y-m-d');
        }
        $raw = trim((string) $raw);
        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat('!' . $format, $raw)->format('Y-m-d');
            } catch (\Throwable) {
                // try next format
            }
        }
        return self::INVALID_DATE;
    }

    private function normalizeGender(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        return match (true) {
            in_array($v, ['nam', 'male', 'm']) => 'male',
            in_array($v, ['nữ', 'nu', 'female', 'f']) => 'female',
            default => null,
        };
    }

    private function normalizeEmploymentType(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        foreach (EmploymentType::cases() as $case) {
            if ($v === $case->value || $v === mb_strtolower($case->label())) {
                return $case->value;
            }
        }
        return null;
    }

    private function normalizeStatus(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        foreach (EmployeeStatus::cases() as $case) {
            if ($v === $case->value || $v === mb_strtolower($case->label())) {
                return $case->value;
            }
        }
        return null;
    }
}
