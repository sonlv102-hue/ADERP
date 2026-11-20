<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiếu nhập thủ công loại "Nhập lại vật tư từ dự án" (Nợ 156 / Có 154): dự án nguồn để ghi giảm WIP.
 * Không dùng stock_entry_items.project_id vì cột đó biến hàng thành lô dự án (FIFO), còn hàng trả về là tồn kho chung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('credit_account_code')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
