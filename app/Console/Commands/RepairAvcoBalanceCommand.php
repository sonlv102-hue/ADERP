<?php

namespace App\Console\Commands;

use App\Services\Inventory\AvcoRepairPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Sửa inventory_balances theo MANIFEST đã khảo sát (không hardcode sản phẩm trong code).
 * - Manifest JSON đặt ngoài Git: storage/app/private/inventory-repairs/*.json (xem AvcoRepairPlanner).
 * - Mặc định DRY-RUN; --apply mới ghi. Một dòng không khớp điều kiện → dừng cả batch.
 * - Idempotent: dòng đã đạt "after" → SKIP_APPLIED.
 * - --rollback=<file log> hoàn tác, chặn nếu đã có giao dịch phát sinh sau repair.
 * Không tạo bút toán: chỉ đồng bộ sổ phụ AVCO với sổ kho (movement) đã khớp TK 156.
 */
class RepairAvcoBalanceCommand extends Command
{
    protected $signature = 'inventory:repair-avco-balance
                            {--manifest= : File manifest JSON (đường dẫn tuyệt đối hoặc tương đối storage/app/private)}
                            {--apply : Ghi thật (mặc định chỉ dry-run)}
                            {--reason= : Lý do/đợt repair ghi vào audit log}
                            {--rollback= : File log JSON của lần apply cần hoàn tác}';

    protected $description = 'Sửa số dư AVCO theo manifest đã khảo sát (dry-run mặc định, transaction, lock, idempotent, rollback).';

    public function handle(AvcoRepairPlanner $planner): int
    {
        if ($file = $this->option('rollback')) {
            $rows = $this->readJson($file);
            if ($rows === null) {
                return self::FAILURE;
            }
            $planner->rollback($rows);
            $this->info('Đã rollback ' . count($rows) . ' dòng.');
            return self::SUCCESS;
        }

        $manifest = $this->option('manifest') ? $this->readJson($this->option('manifest')) : null;
        if (! $manifest) {
            $this->error('Cần --manifest=<file>. Manifest liệt kê từng dòng được phép sửa kèm before/after/evidence.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? '=== APPLY — ghi dữ liệu ===' : '=== DRY-RUN — không ghi dữ liệu ===');
        $plan = $planner->plan($manifest);
        $fmt = fn ($v) => $v === null ? 'chưa có' : (float) $v['qty'] . ' / ' . number_format((float) $v['value'], 2);
        $this->table(['SP', 'Kho', 'Hiện tại', 'Before', 'After', 'Sổ kho kỳ vọng', 'Kết quả'], array_map(fn ($p) => [
            $p['product_id'], $p['warehouse_id'], $fmt($p['current']), $fmt($p['before'] ?? null), $fmt($p['after']), $fmt($p['expected']), $p['status'],
        ], $plan));

        $ready = count(array_filter($plan, fn ($p) => $p['status'] === 'READY'));
        if (! $apply) {
            $this->line("{$ready}/" . count($plan) . ' dòng READY. Chạy lại với --apply để ghi.');
            return self::SUCCESS;
        }
        if ($ready === 0) {
            $this->warn('Không có dòng READY (đã áp dụng hoặc bị chặn).');
            return str_contains(json_encode(array_column($plan, 'status')), 'BLOCKED') ? self::FAILURE : self::SUCCESS;
        }

        $log = $planner->apply($plan, (string) ($this->option('reason') ?: basename($this->option('manifest'))));
        $path = 'inventory-repairs/avco-' . now()->format('Ymd_His') . '.json';
        Storage::disk('local')->put($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Đã sửa ' . count($log) . ' dòng. File rollback: ' . Storage::disk('local')->path($path));
        return self::SUCCESS;
    }

    private function readJson(string $file): ?array
    {
        $rel = preg_replace('#^(storage/app/private/|storage/app/)#', '', str_replace('\\', '/', $file));
        $content = is_file($file) ? file_get_contents($file) : (Storage::disk('local')->exists($rel) ? Storage::disk('local')->get($rel) : null);
        $data = $content === null ? null : json_decode($content, true);
        if (! is_array($data)) {
            $this->error("Không đọc được file JSON: {$file}");
            return null;
        }
        return $data;
    }
}
