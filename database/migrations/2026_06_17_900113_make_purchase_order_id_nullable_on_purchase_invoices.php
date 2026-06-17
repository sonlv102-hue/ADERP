<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_invoices ALTER COLUMN purchase_order_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_invoices ALTER COLUMN purchase_order_id SET NOT NULL');
    }
};
