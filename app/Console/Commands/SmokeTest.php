<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmokeTest extends Command
{
    protected $signature = 'app:smoke-test {--fix : Hiển thị gợi ý fix}';
    protected $description = 'Kiểm tra nhanh các query report có lỗi column/table không tồn tại sau deploy';

    private array $errors = [];
    private array $passed = [];

    public function handle(): int
    {
        $this->info('=== Mini ERP Smoke Test ===');

        $this->checkQuery('commissions.code/notes', function () {
            DB::table('commissions')->select('id', 'code', 'notes', 'amount', 'status')->limit(1)->get();
        });

        $this->checkQuery('customers.is_active', function () {
            DB::table('customers')->select('id', 'code', 'name')->where('is_active', true)->limit(1)->get();
        });

        $this->checkQuery('project_inventory_lots table', function () {
            DB::table('project_inventory_lots')->limit(1)->get();
        });

        $this->checkQuery('stock_exit_item_lot_allocations table', function () {
            DB::table('stock_exit_item_lot_allocations')->limit(1)->get();
        });

        $this->checkQuery('stock_entry_items.purchase_order_item_id/project_id/unit_cost', function () {
            DB::table('stock_entry_items')
                ->select('id', 'purchase_order_item_id', 'project_id', 'unit_cost')
                ->limit(1)->get();
        });

        $this->checkQuery('stock_exit_items.cost_price/total_cost', function () {
            DB::table('stock_exit_items')->select('id', 'cost_price', 'total_cost')->limit(1)->get();
        });

        $this->checkQuery('stock_exits.issue_purpose', function () {
            DB::table('stock_exits')->select('id', 'issue_purpose')->limit(1)->get();
        });

        $this->checkQuery('purchase_order_items.project_id/received_quantity', function () {
            DB::table('purchase_order_items')
                ->select('id', 'project_id', 'received_quantity')
                ->limit(1)->get();
        });

        $this->checkQuery('products.inventory_account_code', function () {
            DB::table('products')->select('id', 'inventory_account_code')->limit(1)->get();
        });

        $this->checkQuery('journal_entries.voided_at', function () {
            DB::table('journal_entries')->select('id', 'voided_at', 'void_reason')->limit(1)->get();
        });

        $this->checkQuery('expense_detail: commissions query', function () {
            DB::table('commissions')
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->select(DB::raw("DATE(created_at) as date"), 'code as ref',
                         DB::raw("notes as description"), 'amount')
                ->limit(1)->get();
        });

        $this->checkQuery('project_wip_entries.source_type/source_id', function () {
            DB::table('project_wip_entries')
                ->select('id', 'source_type', 'source_id', 'journal_entry_id')
                ->limit(1)->get();
        });

        // Tổng kết
        $this->newLine();
        $this->info(sprintf('✓ Passed: %d checks', count($this->passed)));

        if ($this->errors) {
            $this->error(sprintf('✗ Failed: %d checks', count($this->errors)));
            foreach ($this->errors as $err) {
                $this->line("  • {$err}");
            }
            return Command::FAILURE;
        }

        $this->info('All checks passed.');
        return Command::SUCCESS;
    }

    private function checkQuery(string $label, callable $query): void
    {
        try {
            $query();
            $this->line("  ✓ {$label}");
            $this->passed[] = $label;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Lấy phần ngắn của error message
            $short = preg_match('/ERROR:\s+(.+?)(?:\n|$)/i', $msg, $m) ? trim($m[1]) : substr($msg, 0, 120);
            $this->error("  ✗ {$label}: {$short}");
            $this->errors[] = "{$label}: {$short}";
        }
    }
}
