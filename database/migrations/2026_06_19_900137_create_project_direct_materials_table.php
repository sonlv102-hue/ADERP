<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_direct_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Sản phẩm catalogue (nullable — cho phép nhập tay nếu không có trong danh mục)
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 255)->nullable(); // free-text khi không có product_id

            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0); // stored = quantity * unit_price

            $table->date('occurrence_date');

            // Loại xử lý: tracking_only | invoice_link | journal_entry
            $table->string('handling_type', 30)->default('tracking_only');

            // Nhà cung cấp (cho type journal_entry khi Có 3311)
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            // TK Có khi tạo bút toán N154 (chỉ dùng khi handling_type = journal_entry)
            $table->string('credit_account_code', 20)->nullable();

            // Liên kết hóa đơn mua — cho type invoice_link
            $table->foreignId('purchase_invoice_item_id')->nullable()
                ->constrained('purchase_invoice_items')->nullOnDelete();

            // Bút toán đã tạo — cho type journal_entry
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->string('status', 20)->default('active'); // active | cancelled
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();
            $table->string('source_document_ref', 255)->nullable(); // chứng từ nguồn bên ngoài

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('handling_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_direct_materials');
    }
};
