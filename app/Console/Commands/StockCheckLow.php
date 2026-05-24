<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class StockCheckLow extends Command
{
    protected $signature = 'stock:check-low';
    protected $description = 'Kiểm tra và liệt kê sản phẩm có tồn kho thấp hơn mức tối thiểu';

    public function handle(): int
    {
        $products = Product::where('is_active', true)
            ->where('min_stock', '>', 0)
            ->get();

        $lowStock = [];

        foreach ($products as $product) {
            $qty = (int) StockMovement::where('product_id', $product->id)->sum('quantity');
            if ($qty < $product->min_stock) {
                $lowStock[] = [
                    'Mã SP' => $product->code,
                    'Tên sản phẩm' => $product->name,
                    'Tồn kho' => $qty,
                    'Tối thiểu' => $product->min_stock,
                ];
            }
        }

        if (empty($lowStock)) {
            $this->info('Tất cả sản phẩm đều đủ tồn kho.');
            return self::SUCCESS;
        }

        $this->warn(count($lowStock) . ' sản phẩm có tồn kho thấp:');
        $this->table(array_keys($lowStock[0]), $lowStock);

        return self::SUCCESS;
    }
}
