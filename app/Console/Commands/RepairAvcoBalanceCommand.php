<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Services\Inventory\AvcoIntegrityChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sửa đúng 6 dòng AVCO đã xác minh trong INVENTORY_INTEGRITY_AND_TRANSACTION_LIFECYCLE_REVIEW_V1 (mục C1).
 * - Mặc định DRY-RUN. Ghi thật cần --apply.
 * - Chỉ sửa khi số dư hiện tại KHỚP CHÍNH XÁC snapshot "before" (có giao dịch mới → bỏ qua, báo lại).
 * - Chỉ sửa khi số lượng đích = Σ movement active − Σ lot (kiểm tra lại bằng AvcoIntegrityChecker).
 * - Một transaction + lockForUpdate; ghi activity_log + file JSON để rollback (--rollback=<file>).
 * Không tạo bút toán: sổ cái TK 156 đã khớp movement, sai lệch chỉ nằm ở sổ phụ inventory_balances.
 */
class RepairAvcoBalanceCommand extends Command
{
    protected $signature = 'inventory:repair-avco-balance
                            {--apply : Ghi thật (mặc định chỉ dry-run)}
                            {--rollback= : Đường dẫn file JSON (storage/app/...) của lần apply cần hoàn tác}';

    protected $description = 'Sửa 6 dòng inventory_balances sai lệch đã xác minh (dry-run mặc định, có rollback).';

    /** [product_id, warehouse_id, before_qty, before_value, after_qty, after_value, evidence] */
    private const WHITELIST = [
        [184, 1, 0, 0.00, 5, 119444444.00, 'A1: hủy XK-0042 (DA id 7) không hoàn AVCO; movement 907/909 = 5 × 23.888.888,80'],
        [145, 1, 0, 0.00, 5, 11342592.55, 'A1: hủy XK-0042 (DA id 7) không hoàn AVCO; movement 908/910 = 5 × 2.268.518,51'],
        [58, 1, 3, 56916666.99, 0, 0.00, 'A2: thu hồi NK-0100 sau khi XK-0041 đã xuất 3 → tồn ảo'],
        [146, 1, 4, 1777777.76, 2, 888888.88, 'A2: thu hồi NK-0065 khi AVCO=0, sau đó hủy XK-0030 cộng 2 → tồn ảo 2 (giữ avg)'],
        [147, 1, 4, 685185.16, 2, 342592.58, 'A2: như SP 146 → tồn ảo 2 (giữ avg)'],
        [28, 5, 1, 130000.00, 0, 0.00, 'A2: thu hồi NK-0054 sau khi XK-0022 đã xuất → tồn ảo 1'],
    ];

    public function handle(AvcoIntegrityChecker $checker): int
    {
        if ($file = $this->option('rollback')) {
            return $this->rollback($file);
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? '=== APPLY — ghi dữ liệu ===' : '=== DRY-RUN — không ghi dữ liệu ===');

        $plan = $this->plan($checker);
        $this->table(
            ['SP', 'Kho', 'Hiện tại (SL/GT)', 'Snapshot before', 'Đích (SL/GT)', 'Kỳ vọng mv−lot', 'Kết quả'],
            array_map(fn ($p) => [
                $p['product_id'], $p['warehouse_id'],
                $this->fmt($p['current_qty'], $p['current_value']), $this->fmt($p['before_qty'], $p['before_value']),
                $this->fmt($p['after_qty'], $p['after_value']), $p['expected_qty'] ?? 'n/a', $p['status'],
            ], $plan)
        );

        $ready = array_values(array_filter($plan, fn ($p) => $p['status'] === 'READY'));
        if (! $apply) {
            $this->line(count($ready) . '/' . count($plan) . ' dòng sẵn sàng. Chạy lại với --apply để ghi.');
            return self::SUCCESS;
        }
        if (empty($ready)) {
            $this->warn('Không có dòng nào đủ điều kiện sửa.');
            return self::SUCCESS;
        }

        $log = DB::transaction(function () use ($ready, $checker) {
            $log = [];
            foreach ($ready as $p) {
                $bal = InventoryBalance::where('product_id', $p['product_id'])->where('warehouse_id', $p['warehouse_id'])
                    ->lockForUpdate()->firstOrFail();
                if (! $this->matches($bal, $p['before_qty'], $p['before_value'])) {
                    throw new RuntimeException("SP {$p['product_id']} kho {$p['warehouse_id']} thay đổi trong lúc chạy — hủy toàn bộ.");
                }
                $before = $bal->only(['qty_on_hand', 'value_on_hand', 'avg_cost']);
                $bal->update(['qty_on_hand' => $p['after_qty'], 'value_on_hand' => $p['after_value']]);
                $log[] = ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'], 'evidence' => $p['evidence'],
                          'before' => $before, 'after' => $bal->fresh()->only(['qty_on_hand', 'value_on_hand', 'avg_cost'])];
            }

            // Kiểm tra lại sau khi ghi: các dòng đã sửa phải hết lệch, nếu không rollback
            $ids = array_column($ready, 'product_id');
            $left = $checker->check(null, $ids)->filter(fn ($r) => collect($ready)->contains(
                fn ($p) => $p['product_id'] === (int) $r->product_id && $p['warehouse_id'] === (int) $r->warehouse_id
            ));
            if ($left->isNotEmpty()) {
                throw new RuntimeException('Sau khi sửa vẫn còn lệch — đã rollback: ' . $left->pluck('product_id')->implode(','));
            }

            activity('inventory_repair')->causedBy(auth()->user())
                ->withProperties(['command' => 'inventory:repair-avco-balance', 'rows' => $log])
                ->log('Sửa số dư AVCO đã xác minh (INVENTORY_INTEGRITY_REMEDIATION_V1)');

            return $log;
        });

        $path = 'inventory-repairs/avco-' . now()->format('Ymd_His') . '.json';
        Storage::disk('local')->put($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Đã sửa ' . count($log) . ' dòng. File rollback: ' . $path . ' (' . Storage::disk('local')->path($path) . ')');

        return self::SUCCESS;
    }

    private function plan(AvcoIntegrityChecker $checker): array
    {
        $issues = $checker->check(null, array_column(self::WHITELIST, 0))
            ->keyBy(fn ($r) => $r->product_id . '-' . $r->warehouse_id);

        return array_map(function ($w) use ($issues) {
            [$pid, $wid, $bq, $bv, $aq, $av, $evidence] = $w;
            $bal = InventoryBalance::where('product_id', $pid)->where('warehouse_id', $wid)->first();
            $issue = $issues[$pid . '-' . $wid] ?? null;
            $expected = $issue?->expected;

            $status = match (true) {
                $bal === null                          => 'SKIP: không có dòng AVCO',
                $this->matches($bal, $aq, $av)         => 'SKIP: đã áp dụng trước đó',
                ! $this->matches($bal, $bq, $bv)       => 'SKIP: số dư đã thay đổi (có giao dịch mới) — cần khảo sát lại',
                $issue === null                        => 'SKIP: hiện không còn lệch',
                abs($expected - $aq) > 0.0005          => "SKIP: kỳ vọng mv−lot = {$expected} ≠ đích {$aq}",
                default                                => 'READY',
            };

            return [
                'product_id' => $pid, 'warehouse_id' => $wid, 'evidence' => $evidence,
                'current_qty' => $bal?->qty_on_hand, 'current_value' => $bal?->value_on_hand,
                'before_qty' => $bq, 'before_value' => $bv, 'after_qty' => $aq, 'after_value' => $av,
                'expected_qty' => $expected, 'status' => $status,
            ];
        }, self::WHITELIST);
    }

    private function rollback(string $file): int
    {
        $path = preg_replace('#^(storage/app/private/|storage/app/)#', '', str_replace('\\', '/', $file));
        if (! Storage::disk('local')->exists($path)) {
            $this->error("Không tìm thấy file: {$file}");
            return self::FAILURE;
        }
        $rows = json_decode(Storage::disk('local')->get($path), true);

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $bal = InventoryBalance::where('product_id', $r['product_id'])->where('warehouse_id', $r['warehouse_id'])
                    ->lockForUpdate()->firstOrFail();
                if (! $this->matches($bal, (float) $r['after']['qty_on_hand'], (float) $r['after']['value_on_hand'])) {
                    throw new RuntimeException("SP {$r['product_id']} kho {$r['warehouse_id']} đã thay đổi sau khi sửa — không rollback tự động.");
                }
                $bal->update(['qty_on_hand' => $r['before']['qty_on_hand'], 'value_on_hand' => $r['before']['value_on_hand']]);
            }
            activity('inventory_repair')->causedBy(auth()->user())
                ->withProperties(['command' => 'inventory:repair-avco-balance --rollback', 'rows' => $rows])
                ->log('Rollback sửa số dư AVCO');
        });

        $this->info('Đã rollback ' . count($rows) . ' dòng.');
        return self::SUCCESS;
    }

    private function matches(InventoryBalance $b, float $qty, float $value): bool
    {
        return abs((float) $b->qty_on_hand - $qty) < 0.0005 && abs((float) $b->value_on_hand - $value) < 0.005;
    }

    private function fmt($q, $v): string
    {
        return $q === null ? 'null' : (string) (float) $q . ' / ' . number_format((float) $v, 2);
    }
}
