<?php

namespace App\Services\Inventory\Integrity;

use App\Models\InventoryAuditRun;
use App\Models\InventoryIntegrityIssue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Chạy audit và lưu kết quả vào bảng vấn đề. Chỉ ĐỌC dữ liệu nghiệp vụ; chỉ GHI inventory_audit_runs /
 * inventory_integrity_issues / *_logs. Toàn bộ đọc + ghi trong 1 transaction (PG: REPEATABLE READ → số liệu nhất quán);
 * lỗi giữa chừng → rollback, kết quả hợp lệ trước đó giữ nguyên, run = failed.
 */
class InventoryIntegrityAuditor
{
    private const QTY_EPS = IntegrityRuleCatalog::EPS_QTY;

    public function __construct(private IntegrityFindingCollector $collector) {}

    public function run(string $scopeType = 'all', ?string $scopeValue = null, ?int $userId = null): InventoryAuditRun
    {
        $scope = IntegrityScope::make($scopeType, $scopeValue);
        $lock = Cache::lock('inventory-integrity-audit', 600);
        if (! $lock->get()) {
            throw new RuntimeException('Đang có một lần kiểm tra khác chạy, vui lòng thử lại sau.');
        }
        $run = InventoryAuditRun::create(['scope_type' => $scopeType, 'scope_value' => $scopeValue, 'rules_version' => IntegrityRuleCatalog::VERSION,
            'status' => 'running', 'started_at' => now(), 'created_by' => $userId]);
        try {
            $counts = DB::transaction(function () use ($scope, $run) {
                if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) { // chỉ khi là transaction ngoài cùng
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                return $this->persist($scope, $run, $this->collector->collect($scope));
            });
            $run->update($counts + $this->collector->stats + ['status' => 'success', 'finished_at' => now(),
                'summary' => ['legacy_rows' => $this->collector->stats['legacy_rows'] ?? 0, 'rules' => count(IntegrityRuleCatalog::RULES)]]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);
        } finally {
            $lock->release();
        }
        return $run->fresh();
    }

    private function persist(IntegrityScope $scope, InventoryAuditRun $run, array $found): array
    {
        $now = now();
        $c = ['issues_found' => 0, 'issues_opened' => 0, 'issues_resolved' => 0, 'issues_reopened' => 0];
        $existing = InventoryIntegrityIssue::whereIn('issue_key', array_keys($found))->get()->keyBy('issue_key');

        foreach ($found as $key => $attrs) {
            if ($scope->issueKey !== null && $key !== $scope->issueKey) {
                continue;
            }
            $c['issues_found']++;
            $metrics = array_diff_key($attrs, array_flip(['issue_key', 'category', 'proposal', 'source']));
            $issue = $existing[$key] ?? null;
            if (! $issue) {
                $issue = InventoryIntegrityIssue::create($attrs + ['status' => $attrs['category'] === 'POLICY_DECISION' ? 'PENDING_POLICY' : 'OPEN',
                    'detected_at' => $now, 'last_checked_at' => $now, 'first_run_id' => $run->id, 'last_seen_run_id' => $run->id]);
                $issue->logs()->create(['action' => 'detected', 'run_id' => $run->id, 'to_status' => $issue->status, 'note' => $attrs['cause'], 'user_id' => $run->created_by]);
                $c['issues_opened']++;
                continue;
            }
            $reopen = $issue->source === 'auto' && ($issue->status === 'RESOLVED'   // chủ đề chính sách đã quyết định: không mở lại
                || ($issue->status === 'ACCEPTED_DIFFERENCE' && $this->changedSinceAccept($issue, $attrs)));
            $issue->fill($metrics + ['last_checked_at' => $now, 'last_seen_run_id' => $run->id]);
            if ($reopen) {
                $from = $issue->status;
                $issue->fill(['status' => 'OPEN', 'resolved_at' => null, 'resolved_run_id' => null, 'reopen_count' => $issue->reopen_count + 1]);
                $issue->logs()->create(['action' => 'reopened', 'run_id' => $run->id, 'from_status' => $from, 'to_status' => 'OPEN',
                    'note' => 'Sai lệch tái phát / thay đổi sau khi chấp nhận: ' . $attrs['cause'], 'user_id' => $run->created_by]);
                $c['issues_reopened']++;
            }
            $issue->save();
        }

        // Vấn đề trong phạm vi nhưng không còn phát hiện → tự đóng (chỉ quy tắc tự động)
        InventoryIntegrityIssue::whereNotIn('status', InventoryIntegrityIssue::CLOSED)->where('source', 'auto')
            ->whereNotIn('issue_key', array_keys($found))->get()
            ->filter(fn ($i) => $scope->matchesIssue($i))
            ->each(function (InventoryIntegrityIssue $i) use ($run, $now, &$c) {
                $from = $i->status;
                $i->update(['status' => 'RESOLVED', 'resolved_at' => $now, 'resolved_run_id' => $run->id, 'last_checked_at' => $now]);
                $i->logs()->create(['action' => 'auto_resolved', 'run_id' => $run->id, 'from_status' => $from, 'to_status' => 'RESOLVED',
                    'note' => "Kiểm tra lại (run #{$run->id}) không còn sai lệch", 'user_id' => $run->created_by]);
                $c['issues_resolved']++;
            });
        return $c;
    }

    private function changedSinceAccept(InventoryIntegrityIssue $issue, array $attrs): bool
    {
        $snap = $issue->accepted_snapshot ?? [];
        return abs((float) ($snap['qty_diff'] ?? 0) - (float) ($attrs['qty_diff'] ?? 0)) > self::QTY_EPS
            || abs((float) ($snap['value_diff'] ?? 0) - (float) ($attrs['value_diff'] ?? 0)) > 0.005;
    }
}
