<?php

namespace App\Services;

use App\Models\BankCounterpartyMapping;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Employee;
use App\Models\InternalBankAccount;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gợi ý phân loại dòng tiền (đối tượng + danh mục) dựa trên dữ liệu đối ứng ngân hàng
 * (sao kê): số TK, ngân hàng, tên TK, nội dung. Chỉ TÍNH TOÁN đề xuất — không tự ghi
 * cash_flow_category_id/party_* (Admin phải xác nhận qua CashFlowClassificationService).
 * Không tạo/tác động Journal Entry, không đụng BankTransactionMatchingService.
 */
class BankTransactionClassificationSuggestionService
{
    private const MIN_CONFIDENCE = 60;

    public function suggest(BankTransaction $tx): array
    {
        return $this->match($tx, $this->buildLookups());
    }

    /** @return array<int, array> đề xuất cho từng giao dịch, key = bank_transaction id */
    public function suggestBatch(Collection $transactions): array
    {
        $lookups = $this->buildLookups();

        return $transactions->mapWithKeys(fn (BankTransaction $tx) => [$tx->id => $this->match($tx, $lookups)])->all();
    }

    /**
     * Học từ lựa chọn cuối cùng của Admin (sau khi CashFlowClassificationService::update()
     * đã lưu thành công) — ghi/cập nhật mapping theo số TK đối ứng để lần sau gợi ý đúng.
     */
    public function learn(BankTransaction $tx, ?int $userId): void
    {
        if (!$tx->counterpart_account || $tx->party_type === null) {
            return;
        }

        BankCounterpartyMapping::updateOrCreate(
            ['bank_account_number' => $this->normalize($tx->counterpart_account)],
            [
                'bank_name'             => $tx->counterpart_bank ? trim($tx->counterpart_bank) : null,
                'party_type'            => $tx->party_type,
                'party_id'              => $tx->party_id,
                'party_name'            => $tx->party_name,
                'cash_flow_category_id' => $tx->cash_flow_category_id,
                'confidence'            => 95,
                'created_by'            => $userId,
            ]
        );
    }

    private function buildLookups(): array
    {
        return [
            'internal'  => InternalBankAccount::query()->where('is_active', true)->get()
                ->keyBy(fn ($a) => $this->normalize($a->account_number)),
            'mappings'  => BankCounterpartyMapping::query()->with('cashFlowCategory:id,name')->get()
                ->keyBy('bank_account_number'),
            'suppliers' => SupplierBankAccount::query()->where('is_active', true)->with('supplier:id,name')->get()
                ->keyBy('normalized_account_number'),
            'customers' => CustomerBankAccount::query()->where('is_active', true)->with('customer:id,name')->get()
                ->keyBy('normalized_account_number'),
            'employees' => Employee::query()->whereNotNull('bank_account_no')->where('bank_account_no', '!=', '')->get(['id', 'name', 'bank_account_no'])
                ->keyBy(fn ($e) => $this->normalize($e->bank_account_no)),
            // Fallback theo tên (không có/không khớp số TK) — chỉ nhân viên đang làm việc, tránh gợi ý nhầm người đã nghỉ việc.
            'employeesByName' => Employee::query()->working()->get(['id', 'name'])
                ->keyBy(fn ($e) => $this->normalizeName($e->name)),
            'suppliersByName' => Supplier::query()->where('is_active', true)->get(['id', 'name'])
                ->keyBy(fn ($s) => $this->normalizeName($s->name)),
            'customersByName' => Customer::query()->where('is_active', true)->get(['id', 'name'])
                ->keyBy(fn ($c) => $this->normalizeName($c->name)),
            'categories' => CashFlowCategory::query()->get(['id', 'code', 'name'])->keyBy('code'),
        ];
    }

    private function match(BankTransaction $tx, array $lookups): array
    {
        $empty = $this->build(null, null, null, null, null, 0, []);

        if (!$tx->counterpart_account) {
            return $empty;
        }

        $account          = $this->normalize($tx->counterpart_account);
        $direction        = (float) $tx->credit > 0 ? 'in' : 'out';
        $desc             = mb_strtoupper($tx->description ?? '');
        $hasSalaryKeyword = $this->hasSalaryKeyword($desc);

        // Rule 1a — đã có mapping học được từ lần xác nhận trước (ưu tiên tuyệt đối — quyết định tường minh của Admin)
        if ($mapping = $lookups['mappings']->get($account)) {
            return $this->build(
                $mapping->party_type, $mapping->party_id, $mapping->party_name,
                $mapping->cash_flow_category_id, $mapping->cashFlowCategory?->name,
                (int) $mapping->confidence, ['Đã có mapping trước đó (đối chiếu số TK đối ứng)']
            );
        }

        $counterpartName = $this->normalizeName($tx->counterpart_name);

        // ===== Priority: Trả lương (có bằng chứng) > NCC > KH > Chuyển khoản nội bộ > gợi ý nhẹ/không đủ dữ liệu =====
        // Trước đây "TK đối ứng thuộc TK nội bộ" được xét ngay sau mapping, khiến các trường hợp TK cá
        // nhân của nhân sự (kể cả lãnh đạo) trùng/lẫn với danh sách TK nội bộ công ty bị đề xuất nhầm
        // "Chuyển khoản nội bộ" thay vì đúng bản chất (vd: trả lương giám đốc).
        //
        // Xác định trước "đối ứng có phải nhân viên không" (theo số TK hoặc theo tên, chỉ chiều tiền ra),
        // nhưng CHỈ được gán category "Trả lương" khi nội dung THỰC SỰ có từ khóa lương — tên/TK trùng
        // nhân viên một mình không đủ bằng chứng (tránh false positive: nhân viên đó có thể tạm ứng,
        // hoàn ứng, hoặc bất kỳ giao dịch nào khác không phải lương).
        $employeeMatch = null;
        if ($direction === 'out') {
            if ($employeeByAccount = $lookups['employees']->get($account)) {
                $employeeMatch = ['model' => $employeeByAccount, 'reason' => "Trùng số TK nhân viên ({$employeeByAccount->name})", 'strong' => true];
            } elseif ($counterpartName && $employeeByName = $lookups['employeesByName']->get($counterpartName)) {
                $employeeMatch = ['model' => $employeeByName, 'reason' => "Tên đối ứng khớp tên nhân viên ({$employeeByName->name})", 'strong' => false];
            }
        }

        // 1. Trả lương — CHỈ khi có bằng chứng nội dung (money_out + là nhân viên + từ khóa lương)
        if ($employeeMatch && $hasSalaryKeyword) {
            $category   = $lookups['categories']->get('out_salary');
            $employee   = $employeeMatch['model'];
            $confidence = $employeeMatch['strong'] ? 97 : 95;
            return $this->build('employee', $employee->id, $employee->name, $category?->id, $category?->name,
                $confidence, [$employeeMatch['reason'], 'Nội dung chuyển khoản có từ khóa lương']);
        }

        // 2. Thanh toán NCC — theo số TK hoặc theo tên (chiều tiền ra)
        if ($direction === 'out' && $supplierAcc = $lookups['suppliers']->get($account)) {
            $category = $lookups['categories']->get('out_supplier_payment');
            $name = $supplierAcc->supplier?->name;
            return $this->build('supplier', $supplierAcc->supplier_id, $name, $category?->id, $category?->name,
                96, ['Trùng số TK nhà cung cấp' . ($name ? " ({$name})" : '')]);
        }
        if ($direction === 'out' && $counterpartName && $supplierByName = $lookups['suppliersByName']->get($counterpartName)) {
            $category = $lookups['categories']->get('out_supplier_payment');
            return $this->build('supplier', $supplierByName->id, $supplierByName->name, $category?->id, $category?->name,
                78, ["Tên đối ứng khớp tên nhà cung cấp ({$supplierByName->name})"]);
        }

        // 3. Thu khách hàng — theo số TK hoặc theo tên (chiều tiền vào)
        if ($direction === 'in' && $customerAcc = $lookups['customers']->get($account)) {
            $category = $lookups['categories']->get('in_customer_payment');
            $name = $customerAcc->customer?->name;
            return $this->build('customer', $customerAcc->customer_id, $name, $category?->id, $category?->name,
                96, ['Trùng số TK khách hàng' . ($name ? " ({$name})" : '')]);
        }
        if ($direction === 'in' && $counterpartName && $customerByName = $lookups['customersByName']->get($counterpartName)) {
            $category = $lookups['categories']->get('in_customer_payment');
            return $this->build('customer', $customerByName->id, $customerByName->name, $category?->id, $category?->name,
                78, ["Tên đối ứng khớp tên khách hàng ({$customerByName->name})"]);
        }

        // 4. Chuyển khoản nội bộ — CHỈ áp dụng khi không phải lương (có bằng chứng)/NCC/KH ở trên
        if ($internal = $lookups['internal']->get($account)) {
            $category = $lookups['categories']->get($direction === 'in' ? 'in_internal_transfer' : 'out_internal_transfer');
            return $this->build('bank', null, $internal->name, $category?->id, $category?->name,
                100, ["Trùng số TK nội bộ công ty ({$internal->name})"]);
        }

        // 5. Là nhân viên nhưng KHÔNG có từ khóa lương trong nội dung — KHÔNG tự gán "Trả lương" (tránh
        // false positive). Chỉ gợi ý nhẹ "đây là giao dịch với nhân viên" để Admin tự quyết định loại.
        if ($employeeMatch) {
            $employee = $employeeMatch['model'];
            return $this->build('employee', $employee->id, $employee->name, null, null,
                65, [$employeeMatch['reason'], 'Không có từ khóa lương trong nội dung — cần Admin xác nhận loại giao dịch']);
        }

        // Rule 3 — nội dung chuyển khoản: chỉ tăng tín hiệu, không đủ để xác nhận chắc chắn khi không xác định được đối tượng
        if ($direction === 'out' && $hasSalaryKeyword) {
            $category = $lookups['categories']->get('out_salary');
            return $this->build(null, null, $tx->counterpart_name, $category?->id, $category?->name,
                55, ['Nội dung chuyển khoản có từ khóa lương']);
        }
        if ($direction === 'out' && str_contains($desc, 'THANH TOAN')) {
            $category = $lookups['categories']->get('out_supplier_payment');
            return $this->build(null, null, $tx->counterpart_name, $category?->id, $category?->name,
                50, ['Nội dung chuyển khoản có từ khóa thanh toán']);
        }

        return $empty;
    }

    private function build(?string $partyType, ?int $partyId, ?string $partyName, ?int $categoryId, ?string $categoryName, int $confidence, array $reasons): array
    {
        return [
            'suggestion'    => $confidence >= self::MIN_CONFIDENCE,
            'party_type'    => $partyType,
            'party_id'      => $partyId,
            'party_name'    => $partyName,
            'category_id'   => $categoryId,
            'category_name' => $categoryName,
            'confidence'    => $confidence,
            'reasons'       => $reasons,
        ];
    }

    private function normalize(?string $account): string
    {
        return $account ? preg_replace('/[\s\-\.]/', '', $account) : '';
    }

    /** Nội dung chuyển khoản có nhắc tới lương (LUONG/LƯƠNG/SALARY/THANG/THÁNG). */
    private function hasSalaryKeyword(string $descUpper): bool
    {
        foreach (['LUONG', 'LƯƠNG', 'SALARY', 'THANG', 'THÁNG'] as $keyword) {
            if (str_contains($descUpper, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** Chuẩn hóa tên để so khớp bỏ dấu, không phân biệt hoa/thường (đối ứng sao kê thường không dấu). */
    private function normalizeName(?string $name): string
    {
        return mb_strtoupper(trim(Str::ascii((string) $name)));
    }
}
