<?php

namespace App\Services;

/**
 * Vietnam Personal Income Tax (PIT) calculation per Luật số 04/2007/QH12 (sửa đổi 2012, 2014).
 *
 * Rates (monthly taxable income — Biểu thuế lũy tiến từng phần):
 *   ≤  5,000,000           → 5%
 *   5M  – 10,000,000       → 10%
 *   10M – 18,000,000       → 15%
 *   18M – 32,000,000       → 20%
 *   32M – 52,000,000       → 25%
 *   52M – 80,000,000       → 30%
 *   > 80,000,000           → 35%
 *
 * Personal deduction  : 11,000,000 VND / month
 * Dependent deduction : 4,400,000 VND / dependent / month
 * Insurance cap       : 20 × base salary (2,340,000) = 46,800,000 VND
 */
class PitCalculatorService
{
    public const PERSONAL_DEDUCTION  = 11_000_000;
    public const DEPENDENT_DEDUCTION = 4_400_000;
    public const INSURANCE_CAP       = 46_800_000;

    // Rates: [upper_limit_inclusive, rate_percent]  (upper=null means no cap)
    private const BRACKETS = [
        [5_000_000,  5],
        [10_000_000, 10],
        [18_000_000, 15],
        [32_000_000, 20],
        [52_000_000, 25],
        [80_000_000, 30],
        [null,        35],
    ];

    public function insuranceBase(float $grossSalary): float
    {
        return min($grossSalary, self::INSURANCE_CAP);
    }

    public function bhxhEmployee(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.08);
    }

    public function bhytEmployee(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.015);
    }

    public function bhtnEmployee(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.01);
    }

    public function bhxhEmployer(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.175);
    }

    public function bhytEmployer(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.03);
    }

    public function bhtnEmployer(float $grossSalary): float
    {
        return round($this->insuranceBase($grossSalary) * 0.01);
    }

    public function totalInsuranceEmployee(float $grossSalary): float
    {
        return $this->bhxhEmployee($grossSalary)
            + $this->bhytEmployee($grossSalary)
            + $this->bhtnEmployee($grossSalary);
    }

    public function totalInsuranceEmployer(float $grossSalary): float
    {
        return $this->bhxhEmployer($grossSalary)
            + $this->bhytEmployer($grossSalary)
            + $this->bhtnEmployer($grossSalary);
    }

    public function taxableIncome(float $grossSalary, int $dependents = 0): float
    {
        $insuranceEmp = $this->totalInsuranceEmployee($grossSalary);
        $deductions   = self::PERSONAL_DEDUCTION + ($dependents * self::DEPENDENT_DEDUCTION);
        return max(0, $grossSalary - $insuranceEmp - $deductions);
    }

    public function pit(float $grossSalary, int $dependents = 0): float
    {
        $taxable = $this->taxableIncome($grossSalary, $dependents);
        return round($this->progressiveTax($taxable));
    }

    public function netSalary(float $grossSalary, int $dependents = 0): float
    {
        return $grossSalary
            - $this->totalInsuranceEmployee($grossSalary)
            - $this->pit($grossSalary, $dependents);
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

    /** Return full breakdown array for a given gross + dependents */
    public function breakdown(float $grossSalary, int $dependents = 0): array
    {
        $base      = $this->insuranceBase($grossSalary);
        $bhxhEmp   = $this->bhxhEmployee($grossSalary);
        $bhytEmp   = $this->bhytEmployee($grossSalary);
        $bhtnEmp   = $this->bhtnEmployee($grossSalary);
        $bhxhEmpl  = $this->bhxhEmployer($grossSalary);
        $bhytEmpl  = $this->bhytEmployer($grossSalary);
        $bhtnEmpl  = $this->bhtnEmployer($grossSalary);
        $insEmp    = $bhxhEmp + $bhytEmp + $bhtnEmp;
        $insEmpl   = $bhxhEmpl + $bhytEmpl + $bhtnEmpl;
        $pit       = $this->pit($grossSalary, $dependents);
        $net       = $grossSalary - $insEmp - $pit;

        return [
            'gross_salary'     => round($grossSalary),
            'insurance_base'   => round($base),
            'bhxh_employee'    => $bhxhEmp,
            'bhyt_employee'    => $bhytEmp,
            'bhtn_employee'    => $bhtnEmp,
            'bhxh_employer'    => $bhxhEmpl,
            'bhyt_employer'    => $bhytEmpl,
            'bhtn_employer'    => $bhtnEmpl,
            'ins_employee'     => $insEmp,
            'ins_employer'     => $insEmpl,
            'pit'              => $pit,
            'net_salary'       => round($net),
            'dependents_count' => $dependents,
        ];
    }
}
