<?php

namespace App\Services;

/**
 * Vietnam Personal Income Tax (PIT) + BHXH/BHYT/BHTN calculation.
 *
 * Căn cứ pháp lý:
 *   - Luật BHXH 2024 + Nghị định 158/2025/NĐ-CP (hiệu lực 01/07/2025)
 *   - PIT: Luật số 04/2007/QH12 sửa đổi 2012, 2014 (biểu thuế lũy tiến 7 bậc)
 *
 * ==========================================================================
 * PHÂN LOẠI KHOẢN THU NHẬP (Nghị định 158/2025/NĐ-CP)
 * ==========================================================================
 *
 * TÍNH vào căn cứ đóng BHXH ($bhxhAllowances):
 *   - Lương cơ bản theo công việc/chức danh          → $baseSalary
 *   - Phụ cấp chức vụ/trách nhiệm (ổn định, HĐLĐ)   ┐
 *   - Phụ cấp thâm niên, tay nghề, chuyên môn        ├ → $bhxhAllowances
 *   - Khoản bổ sung cố định, xác định được số tiền   ┘
 *
 * KHÔNG tính vào căn cứ đóng BHXH ($nonBhxhAllowances):
 *   - Hỗ trợ ăn trưa / ăn giữa ca
 *   - Hỗ trợ xăng xe / đi lại
 *   - Hỗ trợ điện thoại / liên lạc
 *   - Hỗ trợ nhà ở, giữ trẻ, hiếu hỉ, sinh nhật…
 *   - PC hiệu quả/KPI (biến động theo kết quả công việc)
 *   - Thưởng các loại
 *
 * Căn cứ BHXH = min($baseSalary + $bhxhAllowances, INSURANCE_CAP) × rate
 *
 * ==========================================================================
 * RATES (2025)
 * ==========================================================================
 *   Người lao động: BHXH 8%, BHYT 1.5%, BHTN 1%  → tổng 10.5%
 *   Người sử dụng LĐ: BHXH 17.5%, BHYT 3%, BHTN 1% → tổng 21.5%
 *     (BHXH 17.5% = 14% hưu trí/tử tuất + 3% ốm đau/thai sản + 0.5% TNLĐ-BNN)
 *   Trần đóng BHXH: 20 × lương tối thiểu vùng I (≈ 46,800,000 VND/tháng 2025)
 *
 * ==========================================================================
 * PIT DEDUCTIONS
 * ==========================================================================
 *   Bản thân: 11,000,000 VND/tháng
 *   Người phụ thuộc: 4,400,000 VND/người/tháng
 */
class PitCalculatorService
{
    public const PERSONAL_DEDUCTION  = 11_000_000;
    public const DEPENDENT_DEDUCTION = 4_400_000;
    public const INSURANCE_CAP       = 46_800_000;

    private const BRACKETS = [
        [5_000_000,  5],
        [10_000_000, 10],
        [18_000_000, 15],
        [32_000_000, 20],
        [52_000_000, 25],
        [80_000_000, 30],
        [null,        35],
    ];

    /**
     * Full payroll breakdown theo Nghị định 158/2025/NĐ-CP.
     *
     * @param float $baseSalary       Lương cơ bản (luôn tính BHXH)
     * @param float $bhxhAllowances   Phụ cấp lương ổn định (PC trách nhiệm, chức vụ, thâm niên…)
     *                                → TÍNH vào căn cứ đóng BHXH
     * @param float $nonBhxhAllowances Hỗ trợ & phúc lợi (ăn trưa, xăng xe, ĐT, hiệu quả KPI…)
     *                                → KHÔNG tính vào căn cứ đóng BHXH
     * @param int   $dependents       Số người phụ thuộc (tính giảm trừ thuế TNCN)
     * @param bool  $insuranceSubject Có đóng BHXH không (false: thời vụ, HĐ < 3 tháng)
     * @param int   $workingDays      Ngày công thực tế trong tháng
     * @param int   $standardDays     Ngày công chuẩn theo hợp đồng (thường 26)
     */
    public function breakdown(
        float $baseSalary,
        float $bhxhAllowances    = 0,
        float $nonBhxhAllowances = 0,
        int   $dependents        = 0,
        bool  $insuranceSubject  = true,
        int   $workingDays       = 26,
        int   $standardDays      = 26,
    ): array {
        $rate               = ($standardDays > 0) ? min($workingDays / $standardDays, 1.0) : 1.0;
        $effectiveBase      = round($baseSalary       * $rate);
        $effectiveBhxhAllw  = round($bhxhAllowances   * $rate);
        $effectiveNonBhxh   = round($nonBhxhAllowances * $rate);
        $gross              = $effectiveBase + $effectiveBhxhAllw + $effectiveNonBhxh;

        // Căn cứ BHXH = lương cơ bản + phụ cấp lương ổn định (tính BHXH), capped
        $insBase   = $insuranceSubject
            ? min($effectiveBase + $effectiveBhxhAllw, self::INSURANCE_CAP)
            : 0;

        $bhxhEmp   = round($insBase * 0.08);
        $bhytEmp   = round($insBase * 0.015);
        $bhtnEmp   = round($insBase * 0.01);
        $bhxhEmpl  = round($insBase * 0.175);
        $bhytEmpl  = round($insBase * 0.03);
        $bhtnEmpl  = round($insBase * 0.01);
        $insEmp    = $bhxhEmp + $bhytEmp + $bhtnEmp;
        $insEmpl   = $bhxhEmpl + $bhytEmpl + $bhtnEmpl;

        // Thu nhập tính thuế TNCN = Gross - BHXH/BHYT/BHTN NV - Giảm trừ gia cảnh
        $personalDed   = self::PERSONAL_DEDUCTION + ($dependents * self::DEPENDENT_DEDUCTION);
        $taxableForPit = max(0, $gross - $insEmp - $personalDed);
        $pit           = round($this->progressiveTax($taxableForPit));
        $net           = round($gross - $insEmp - $pit);

        return [
            'gross_salary'        => round($gross),
            'effective_base'      => round($effectiveBase),
            'effective_bhxh_allw' => round($effectiveBhxhAllw),
            'effective_non_bhxh'  => round($effectiveNonBhxh),
            'insurance_base'      => round($insBase),          // "Lương đóng BH" trên bảng lương
            'bhxh_employee'       => $bhxhEmp,
            'bhyt_employee'       => $bhytEmp,
            'bhtn_employee'       => $bhtnEmp,
            'bhxh_employer'       => $bhxhEmpl,
            'bhyt_employer'       => $bhytEmpl,
            'bhtn_employer'       => $bhtnEmpl,
            'ins_employee'        => $insEmp,
            'ins_employer'        => $insEmpl,
            'personal_deduction'  => round($personalDed),
            'taxable_for_pit'     => round($taxableForPit),
            'pit'                 => $pit,
            'net_salary'          => $net,
            'dependents_count'    => $dependents,
        ];
    }

    /** Tính lại PIT và net khi biết gross và ins_employee (dùng khi override BHXH). */
    public function calcPitFromGross(float $gross, float $insEmployee, int $dependents): array
    {
        $personalDed = self::PERSONAL_DEDUCTION + ($dependents * self::DEPENDENT_DEDUCTION);
        $taxable     = max(0, $gross - $insEmployee - $personalDed);
        $pit         = round($this->progressiveTax($taxable));
        $net         = round($gross - $insEmployee - $pit);
        return ['pit' => $pit, 'net_salary' => $net];
    }

    // ── Legacy helpers ────────────────────────────────────────────────────────

    public function insuranceBase(float $baseSalary): float
    {
        return min($baseSalary, self::INSURANCE_CAP);
    }

    private function progressiveTax(float $taxable): float
    {
        $tax  = 0.0;
        $prev = 0.0;

        foreach (self::BRACKETS as [$cap, $rate]) {
            if ($taxable <= $prev) break;
            $upper = $cap ?? PHP_FLOAT_MAX;
            $slice = min($taxable, $upper) - $prev;
            $tax  += $slice * ($rate / 100);
            $prev  = $upper;
            if ($cap === null || $taxable <= $cap) break;
        }

        return $tax;
    }
}
