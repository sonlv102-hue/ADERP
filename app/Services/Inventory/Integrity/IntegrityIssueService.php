<?php

namespace App\Services\Inventory\Integrity;

use App\Models\InventoryIntegrityIssue as Issue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giao việc / trạng thái / ghi chú / chấp nhận chênh lệch. Không đụng dữ liệu nghiệp vụ.
 * - RESOLVED cho vấn đề có quy tắc tự động: CHỈ do audit kiểm tra lại (không đặt tay để che sai lệch).
 * - ACCEPTED_DIFFERENCE: bắt buộc giải trình, người có quyền; lưu snapshot để tự mở lại nếu số liệu thay đổi.
 * - Chủ đề chính sách (source=policy): đóng bằng quyết định có ghi nhận.
 */
class IntegrityIssueService
{
    public function assign(array $issueIds, ?int $assigneeId, ?string $note, ?string $dueDate, ?string $category): int
    {
        if ($category !== null && ! in_array($category, Issue::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Phân loại không hợp lệ.']);
        }
        return DB::transaction(function () use ($issueIds, $assigneeId, $note, $dueDate, $category) {
            $n = 0;
            foreach (Issue::whereIn('id', $issueIds)->lockForUpdate()->get() as $issue) {
                if (! $issue->isOpen()) {
                    continue;
                }
                $from = $issue->status;
                $issue->fill(array_filter(['assignee_id' => $assigneeId, 'request_note' => $note, 'due_date' => $dueDate, 'category' => $category], fn ($v) => $v !== null));
                if ($assigneeId && in_array($issue->status, ['OPEN'], true)) {
                    $issue->status = 'ASSIGNED';
                }
                $issue->save();
                $issue->log('assigned', ['from_status' => $from, 'to_status' => $issue->status, 'note' => $note,
                    'payload' => array_filter(['assignee_id' => $assigneeId, 'due_date' => $dueDate, 'category' => $category])]);
                $n++;
            }
            return $n;
        });
    }

    public function changeStatus(Issue $issue, string $status, ?string $note): void
    {
        if ($status === 'RESOLVED' || $status === 'ACCEPTED_DIFFERENCE') {
            throw ValidationException::withMessages(['status' => $status === 'RESOLVED'
                ? 'Không đánh dấu RESOLVED thủ công: hãy "Kiểm tra lại" — hệ thống tự đóng khi không còn sai lệch.'
                : 'Dùng chức năng "Xác nhận chênh lệch hợp lệ" (cần giải trình).']);
        }
        if (! in_array($status, Issue::MANUAL_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Trạng thái không hợp lệ.']);
        }
        if (! $issue->isOpen() && $issue->source === 'auto') {
            throw ValidationException::withMessages(['status' => 'Vấn đề đã đóng; hệ thống tự mở lại nếu sai lệch tái phát.']);
        }
        $from = $issue->status;
        $issue->update(['status' => $status]);
        $issue->log('status', ['from_status' => $from, 'to_status' => $status, 'note' => $note]);
    }

    public function addNote(Issue $issue, string $note): void
    {
        $issue->log('note', ['note' => $note]);
    }

    /** Kế toán ghi kết quả kiểm tra, nguyên nhân, phương án đã chọn, chứng từ/JE điều chỉnh. */
    public function saveResolution(Issue $issue, array $data): void
    {
        $data = array_filter(array_intersect_key($data, array_flip(['check_result', 'cause', 'chosen_option', 'adjustment_ref', 'business_note'])), fn ($v) => $v !== null && $v !== '');
        $issue->update(['resolution' => array_merge($issue->resolution ?? [], $data, ['updated_by' => auth()->id(), 'updated_at' => now()->toIso8601String()])]);
        $issue->log('resolution', ['payload' => $data]);
    }

    public function accept(Issue $issue, string $justification): void
    {
        if ($issue->source !== 'auto') {
            throw ValidationException::withMessages(['status' => 'Chủ đề chính sách dùng chức năng ghi nhận quyết định.']);
        }
        if (! $issue->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Vấn đề đã đóng.']);
        }
        $from = $issue->status;
        $issue->update(['status' => 'ACCEPTED_DIFFERENCE', 'accepted_by' => auth()->id(), 'accepted_at' => now(),
            'accepted_snapshot' => ['qty_diff' => $issue->qty_diff, 'value_diff' => $issue->value_diff, 'run_id' => $issue->last_seen_run_id]]);
        $issue->log('accepted', ['from_status' => $from, 'to_status' => 'ACCEPTED_DIFFERENCE', 'note' => $justification]);
    }

    public function decidePolicy(Issue $issue, string $decision): void
    {
        if ($issue->source !== 'policy') {
            throw ValidationException::withMessages(['status' => 'Chỉ áp dụng cho chủ đề chính sách.']);
        }
        $from = $issue->status;
        $issue->update(['status' => 'RESOLVED', 'resolved_at' => now(),
            'resolution' => array_merge($issue->resolution ?? [], ['chosen_option' => $decision, 'updated_by' => auth()->id()])]);
        $issue->log('policy_decided', ['from_status' => $from, 'to_status' => 'RESOLVED', 'note' => $decision]);
    }
}
