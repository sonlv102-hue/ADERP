<?php

namespace App\Services;

use App\Enums\CashFlowDirection;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PurchaseContract;
use App\Models\Shareholder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Entrypoint MUTATION duy nhất cho các field "Báo cáo dòng tiền tài khoản công ty"
 * trên BankTransaction. Whitelist field ở đây — không dựa vào validation ở
 * controller/frontend — để đảm bảo spec §22 (không sửa dữ liệu gốc ngân hàng).
 *
 * Validate thêm (spec §3/§4/§9/§12):
 *  - party_type/contract_type chỉ nhận key cố định, KHÔNG resolve class từ input
 *    client (map cứng PARTY_MODELS/CONTRACT_MODELS) — chặn injection.
 *  - party_id/contract_id phải tồn tại đúng bảng tương ứng với type đã chọn.
 *  - category phải active và đúng chiều (direction) với debit/credit của giao dịch —
 *    trừ khi giữ nguyên giá trị category đã có từ trước (không re-validate lịch sử).
 *  - concurrency: nếu client gửi kèm expected_updated_at khác với bản ghi hiện tại,
 *    từ chối thay vì âm thầm ghi đè (không mất thay đổi của người khác).
 */
class CashFlowClassificationService
{
    private const WHITELIST = [
        'cash_flow_category_id', 'project_id', 'contract_type', 'contract_id',
        'party_type', 'party_id', 'party_name', 'responsible_user_id', 'cash_flow_note',
    ];

    private const PARTY_TYPES = ['customer', 'supplier', 'employee', 'shareholder', 'bank', 'other_individual', 'other_entity'];

    /** party_type có master data thật — phải verify party_id tồn tại đúng bảng. */
    private const PARTY_MODELS = [
        'customer'    => Customer::class,
        'supplier'    => Supplier::class,
        'employee'    => Employee::class,
        'shareholder' => Shareholder::class,
    ];

    private const CONTRACT_MODELS = [
        'contract'          => Contract::class,
        'purchase_contract' => PurchaseContract::class,
    ];

    public function update(BankTransaction $tx, array $data): BankTransaction
    {
        $this->assertNoConcurrentModification($tx, $data);

        $payload = Arr::only($data, self::WHITELIST);

        $this->validateParty($tx, $payload);
        $this->validateContract($tx, $payload);
        $this->validateCategory($tx, $payload);
        $this->validateResponsibleUser($payload);

        $old = $tx->only(self::WHITELIST);

        DB::transaction(function () use ($tx, $payload) {
            $tx->update($payload);
        });

        $changed = array_diff_assoc($payload, $old);
        if (!empty($changed)) {
            activity()
                ->performedOn($tx)
                ->withProperties([
                    'action' => 'CASH_FLOW_CLASSIFY',
                    'old'    => Arr::only($old, array_keys($changed)),
                    'new'    => $changed,
                ])
                ->log('classify');
        }

        return $tx->fresh();
    }

    /** spec §12 — không âm thầm ghi đè thay đổi của người khác. */
    private function assertNoConcurrentModification(BankTransaction $tx, array $data): void
    {
        if (empty($data['expected_updated_at'])) {
            return;
        }

        $expected = Carbon::parse($data['expected_updated_at']);
        if ($tx->updated_at && !$tx->updated_at->equalTo($expected)) {
            throw ValidationException::withMessages([
                'concurrency' => 'Giao dịch đã được người khác cập nhật. Vui lòng tải lại trang trước khi lưu.',
            ]);
        }
    }

    /** spec §3 — party_type chỉ nhận key whitelist, party_id phải tồn tại đúng bảng. */
    private function validateParty(BankTransaction $tx, array &$payload): void
    {
        $effectiveType = array_key_exists('party_type', $payload) ? $payload['party_type'] : $tx->party_type;
        $effectiveId = array_key_exists('party_id', $payload) ? $payload['party_id'] : $tx->party_id;

        if ($effectiveType === null) {
            return;
        }

        if (!in_array($effectiveType, self::PARTY_TYPES, true)) {
            throw new InvalidArgumentException('party_type không hợp lệ.');
        }

        if (!isset(self::PARTY_MODELS[$effectiveType])) {
            // bank/other_individual/other_entity: không có master data — luôn free-text, party_id phải null.
            if (array_key_exists('party_id', $payload)) {
                $payload['party_id'] = null;
            }
            return;
        }

        if ($effectiveId === null) {
            throw new InvalidArgumentException("party_id là bắt buộc khi party_type là '{$effectiveType}'.");
        }

        $modelClass = self::PARTY_MODELS[$effectiveType];
        $model = $modelClass::query()->find($effectiveId);
        if (!$model) {
            throw new InvalidArgumentException('Đối tượng đã chọn không tồn tại trong hệ thống.');
        }

        // Chỉ tự lấy lại tên (chống spoofing) khi party_id thực sự đang được set/đổi.
        if (array_key_exists('party_id', $payload)) {
            $payload['party_name'] = $model->name;
        }
    }

    /** spec §3 — contract_type chỉ nhận key whitelist, contract_id phải tồn tại đúng bảng. */
    private function validateContract(BankTransaction $tx, array &$payload): void
    {
        $effectiveType = array_key_exists('contract_type', $payload) ? $payload['contract_type'] : $tx->contract_type;
        $effectiveId = array_key_exists('contract_id', $payload) ? $payload['contract_id'] : $tx->contract_id;

        if ($effectiveType === null) {
            return;
        }

        if (!isset(self::CONTRACT_MODELS[$effectiveType])) {
            throw new InvalidArgumentException('contract_type không hợp lệ.');
        }

        if ($effectiveId === null) {
            throw new InvalidArgumentException("contract_id là bắt buộc khi contract_type là '{$effectiveType}'.");
        }

        $modelClass = self::CONTRACT_MODELS[$effectiveType];
        if (!$modelClass::query()->whereKey($effectiveId)->exists()) {
            throw new InvalidArgumentException('Hợp đồng đã chọn không tồn tại trong hệ thống.');
        }
    }

    /** spec §4/§9 — category phải active + đúng chiều với debit/credit, trừ khi giữ nguyên giá trị cũ. */
    private function validateCategory(BankTransaction $tx, array $payload): void
    {
        if (!array_key_exists('cash_flow_category_id', $payload)) {
            return;
        }

        $categoryId = $payload['cash_flow_category_id'];
        if ($categoryId === null || $categoryId === $tx->cash_flow_category_id) {
            return; // bỏ chọn, hoặc giữ nguyên category đã gán trước đó — không re-validate lịch sử.
        }

        $category = CashFlowCategory::find($categoryId);
        if (!$category) {
            throw new InvalidArgumentException('Nguồn tiền/mục đích chi đã chọn không tồn tại.');
        }
        if (!$category->is_active) {
            throw new InvalidArgumentException('Không thể gán danh mục đã bị vô hiệu hoá.');
        }

        $txDirection = (float) $tx->credit > 0 ? CashFlowDirection::In : CashFlowDirection::Out;
        if ($category->direction !== $txDirection) {
            throw new InvalidArgumentException('Danh mục không khớp chiều giao dịch (tiền vào/tiền ra).');
        }
    }

    /**
     * spec §1/§12 — responsible_user_id phải tồn tại trong bảng users. Trước hardening
     * này chỉ được validate ở Laravel Request rule (controller) — không nhất quán với
     * party/contract/category vốn luôn được chặn ngay ở service (defense-in-depth,
     * service là entrypoint duy nhất, không phụ thuộc controller).
     */
    private function validateResponsibleUser(array $payload): void
    {
        if (!array_key_exists('responsible_user_id', $payload) || $payload['responsible_user_id'] === null) {
            return;
        }

        if (!User::query()->whereKey($payload['responsible_user_id'])->exists()) {
            throw new InvalidArgumentException('Người phụ trách đã chọn không tồn tại trong hệ thống.');
        }
    }
}
