<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-tính unit_cogs cho các order_items có unit_cogs_source = 'backfill_estimated'.
 *
 * Chạy sau khi kế toán đã cập nhật products.vat_percent.
 * Chỉ ảnh hưởng các dòng được backfill bởi migration; không đụng đến snapshot thật.
 */
class RebackfillEstimatedCogs extends Command
{
    protected $signature = 'cogs:rebackfill-estimated
                            {--dry-run : Hiển thị số dòng sẽ bị ảnh hưởng mà không cập nhật}';

    protected $description = 'Re-tính unit_cogs cho order_items đã backfill ước tính (cogs_source=backfill_estimated)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $count = DB::table('order_items')
            ->where('unit_cogs_source', 'backfill_estimated')
            ->whereNotNull('product_id')
            ->count();

        if ($count === 0) {
            $this->info('Không có dòng backfill_estimated nào cần re-tính.');
            return self::SUCCESS;
        }

        $this->line("Sẽ re-tính unit_cogs cho {$count} dòng order_items (backfill_estimated).");

        if ($dryRun) {
            $this->warn('[dry-run] Không cập nhật thực tế. Chạy lại không có --dry-run để áp dụng.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Tiếp tục cập nhật?', false)) {
            $this->line('Đã hủy.');
            return self::SUCCESS;
        }

        $updated = DB::statement("
            UPDATE order_items oi
            SET unit_cogs = ROUND(
                    p.cost_price / (1 + COALESCE(p.vat_percent, 0) / 100.0),
                    2
                )
            FROM products p
            WHERE oi.product_id = p.id
              AND oi.unit_cogs_source = 'backfill_estimated'
        ");

        $this->info("Đã cập nhật {$count} dòng.");
        $this->line('Lưu ý: unit_cogs_source vẫn là backfill_estimated vì dữ liệu gốc tại thời điểm bán không thể khôi phục.');

        return self::SUCCESS;
    }
}
