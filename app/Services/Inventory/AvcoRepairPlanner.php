<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lập kế hoạch / áp dụng / hoàn tác sửa inventory_balances theo manifest đã khảo sát.
 * Mỗi dòng manifest: {product_id, warehouse_id, before:{qty,value}|null, after:{qty,value}, evidence, category}.
 * Điều kiện READY (không ước lượng):
 *  - số dư hiện tại khớp CHÍNH XÁC "before" (null = chưa có dòng AVCO);
 *  - after.qty = Σ movement active − Σ lô dự án (tính lại tại thời điểm chạy);
 *  - after.value = giá trị sổ kho kỳ vọng (Σ amount − giá trị lô), hoặc after = 0/0 khi hết hàng.
 * Một dòng BLOCKED → dừng cả batch, không sửa một phần.
 */
class AvcoRepairPlanner
{
    public function __construct(private InventoryFullAuditService $audit) {}

    public function plan(array $manifest): array
    {
        $ids = array_values(array_unique(array_map(fn ($m) => (int) $m['product_id'], $manifest)));
        $audit = $this->audit->run(['product' => $ids])->keyBy(fn ($r) => $r->product_id . '-' . $r->warehouse_id);

        return array_map(function ($m) use ($audit) {
            $bal = InventoryBalance::where('product_id', $m['product_id'])->where('warehouse_id', $m['warehouse_id'])->first();
            $row = $audit[$m['product_id'] . '-' . $m['warehouse_id']] ?? null;
            $m['current'] = $bal ? ['qty' => (float) $bal->qty_on_hand, 'value' => (float) $bal->value_on_hand] : null;
            $m['expected'] = $row ? ['qty' => $row->expected_avco_qty, 'value' => $row->expected_avco_value] : ['qty' => 0.0, 'value' => 0.0];
            $m['status'] = $this->status($m);
            return $m;
        }, $manifest);
    }

    private function status(array $m): string
    {
        [$cur, $before, $after, $exp] = [$m['current'], $m['before'] ?? null, $m['after'], $m['expected']];
        if ($this->same($cur, $after)) {
            return 'SKIP_APPLIED';
        }
        if (! $this->same($cur, $before)) {
            return 'BLOCKED: số dư hiện tại ' . json_encode($cur) . ' ≠ before (có giao dịch mới) — cần khảo sát lại';
        }
        if (abs($after['qty'] - $exp['qty']) > 0.0005) {
            return "BLOCKED: after.qty {$after['qty']} ≠ movement−lô {$exp['qty']}";
        }
        $zero = abs($after['qty']) < 0.0005 && abs($after['value']) < 0.005;
        if (! $zero && abs($after['value'] - $exp['value']) > 0.01) {
            return "BLOCKED: after.value {$after['value']} ≠ giá trị sổ kho {$exp['value']}";
        }
        return 'READY';
    }

    /** @return array<int, array> log before/after của các dòng đã sửa */
    public function apply(array $plan, string $reason): array
    {
        $blocked = array_filter($plan, fn ($p) => str_starts_with($p['status'], 'BLOCKED'));
        if ($blocked) {
            throw new RuntimeException('Dừng batch: ' . count($blocked) . ' dòng không còn khớp điều kiện — không sửa dòng nào.');
        }
        $ready = array_values(array_filter($plan, fn ($p) => $p['status'] === 'READY'));

        return DB::transaction(function () use ($ready, $reason) {
            $log = [];
            foreach ($ready as $p) {
                $bal = InventoryBalance::where('product_id', $p['product_id'])->where('warehouse_id', $p['warehouse_id'])->lockForUpdate()->first();
                $cur = $bal ? ['qty' => (float) $bal->qty_on_hand, 'value' => (float) $bal->value_on_hand] : null;
                if (! $this->same($cur, $p['before'] ?? null)) {
                    throw new RuntimeException("SP {$p['product_id']} kho {$p['warehouse_id']} thay đổi trong lúc chạy — hủy toàn bộ.");
                }
                $snapshot = $bal?->only(['qty_on_hand', 'value_on_hand', 'avg_cost', 'initialized_from']);
                $attrs = ['qty_on_hand' => $p['after']['qty'], 'value_on_hand' => $p['after']['value']];
                if ($p['after']['qty'] > 0) {
                    $attrs['avg_cost'] = round($p['after']['value'] / $p['after']['qty'], 6);
                }
                $bal ? $bal->update($attrs) : InventoryBalance::create($attrs + [
                    'product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'], 'avg_cost' => $attrs['avg_cost'] ?? 0,
                    'initialized_from' => 'repair_manifest', 'initialized_at' => now(),
                ]);
                $log[] = ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'], 'category' => $p['category'] ?? null,
                          'evidence' => $p['evidence'], 'before' => $snapshot, 'after' => $p['after'], 'repaired_at' => now()->toDateTimeString()];
            }

            $left = $this->audit->run(['product' => array_column($ready, 'product_id')])->filter(fn ($r) => collect($ready)->contains(
                fn ($p) => (int) $p['product_id'] === (int) $r->product_id && (int) $p['warehouse_id'] === (int) $r->warehouse_id
            ) && array_intersect($r->statuses, ['PHANTOM_STOCK', 'QUANTITY_MISMATCH', 'MISSING_BALANCE', 'VALUE_MISMATCH']));
            if ($left->isNotEmpty()) {
                throw new RuntimeException('Sau khi sửa vẫn còn lệch — đã rollback: ' . $left->pluck('product_id')->implode(','));
            }

            activity('inventory_repair')->causedBy(auth()->user())
                ->withProperties(['command' => 'inventory:repair-avco-balance', 'reason' => $reason, 'rows' => $log])
                ->log("Sửa số dư AVCO theo manifest ({$reason})");
            return $log;
        });
    }

    public function rollback(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $bal = InventoryBalance::where('product_id', $r['product_id'])->where('warehouse_id', $r['warehouse_id'])->lockForUpdate()->first();
                $after = $r['after'];
                $after = isset($after['qty']) ? $after : ['qty' => (float) $after['qty_on_hand'], 'value' => (float) $after['value_on_hand']];
                $cur = $bal ? ['qty' => (float) $bal->qty_on_hand, 'value' => (float) $bal->value_on_hand] : null;
                $newer = isset($r['repaired_at']) && StockMovement::where('product_id', $r['product_id'])->where('warehouse_id', $r['warehouse_id'])
                    ->where('created_at', '>', $r['repaired_at'])->exists();
                if ($newer || ! $this->same($cur, $after)) {
                    throw new RuntimeException("SP {$r['product_id']} kho {$r['warehouse_id']} đã có giao dịch/thay đổi sau khi sửa — không rollback tự động.");
                }
                $r['before'] === null ? $bal->delete() : $bal->update($r['before']);
            }
            activity('inventory_repair')->causedBy(auth()->user())
                ->withProperties(['command' => 'inventory:repair-avco-balance --rollback', 'rows' => $rows])->log('Rollback sửa số dư AVCO');
        });
    }

    private function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return abs((float) $a['qty'] - (float) $b['qty']) < 0.0005 && abs((float) $a['value'] - (float) $b['value']) < 0.005;
    }
}
