<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    private int $adminId;
    private int $salesId;

    public function run(): void
    {
        $hasInvoices = DB::table('invoices')->where('code', 'HĐ-0001')->exists();
        $hasProjects = DB::table('projects')->count() > 0;
        $hasTickets  = DB::table('tickets')->count() > 0;

        if ($hasInvoices && $hasProjects && $hasTickets) {
            $this->command->info('Demo data already exists. Skipping.');
            return;
        }

        if ($hasInvoices) {
            // Initialize IDs needed by seedProjects/seedTickets
            $this->adminId = (int) DB::table('users')->where('email', 'admin@minierp.local')->value('id');
            $this->salesId = (int) DB::table('users')->where('email', 'sales@minierp.local')->value('id');
            // Ensure extended customers exist (KH-0003..5 needed by projects/tickets)
            $this->ensureExtendedCustomers();
            if (!$hasProjects) $this->seedProjects();
            if (!$hasTickets)  $this->seedTickets();
            $this->command->info('Demo data (projects/tickets) updated.');
            return;
        }

        $this->adminId = (int) DB::table('users')->where('email', 'admin@minierp.local')->value('id');
        $this->salesId = (int) DB::table('users')->where('email', 'sales@minierp.local')->value('id');

        $this->enrichExistingData();
        $this->seedAdditionalMasterData();
        $this->seedAccountingPeriods();
        $this->seedBankAccounts();
        $this->seedFixedAssets();
        $this->seedInvoicesAndPayments();
        $this->seedPurchaseInvoices();
        $this->seedCashVouchers();
        $this->seedPayrolls();
        $this->seedJournalEntries();

        if (DB::table('projects')->count() === 0) {
            $this->seedProjects();
        }
        if (DB::table('tickets')->count() === 0) {
            $this->seedTickets();
        }

        $this->command->info('Demo data seeded successfully!');
    }

    // ─── Enrich existing data ─────────────────────────────────────────────────

    private function enrichExistingData(): void
    {
        DB::table('customers')->where('id', 1)->update([
            'company'      => 'Công ty TNHH Best Pacific VN',
            'tax_code'     => '0100109106',
            'phone'        => '024.3826.8899',
            'address'      => '12 Nguyễn Trãi, Hà Đông, Hà Nội',
            'credit_limit' => 1000000000,
        ]);
        DB::table('customers')->where('id', 2)->update([
            'company'      => 'Công ty CP Pacific Foods VN',
            'tax_code'     => '0312345678',
            'phone'        => '028.3826.9900',
            'address'      => '100 Nguyễn Văn Linh, Quận 7, TP.HCM',
            'credit_limit' => 800000000,
        ]);
        DB::table('users')->where('email', 'sales@minierp.local')->update([
            'base_salary' => 15000000, 'allowance' => 3000000, 'dependents_count' => 1,
        ]);
        DB::table('users')->where('email', 'kt@minierp.local')->update([
            'base_salary' => 18000000, 'allowance' => 2000000, 'dependents_count' => 0,
        ]);
        DB::table('users')->where('email', 'kho@minierp.local')->update([
            'base_salary' => 12000000, 'allowance' => 1500000, 'dependents_count' => 2,
        ]);
    }

    // ─── Additional master data ───────────────────────────────────────────────

    private function seedAdditionalMasterData(): void
    {
        $now = now();

        // Product categories
        $catIds = [];
        $catDefs = [
            ['Thiết bị mạng',      'thiet-bi-mang'],
            ['Máy chủ & Lưu trữ', 'may-chu-luu-tru'],
            ['Camera & An ninh',   'camera-an-ninh'],
            ['Phụ kiện & Cáp',    'phu-kien-cap'],
        ];
        foreach ($catDefs as $i => [$name, $slug]) {
            $id = DB::table('product_categories')->insertGetId([
                'name' => $name, 'slug' => $slug, 'description' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $catIds[$i] = $id;
        }

        // Additional products (SP-0005 to SP-0010)
        $products = [
            ['code'=>'SP-0005','name'=>'Switch Cisco SG350-28P','unit'=>'cái','category_id'=>$catIds[0],'cost_price'=>13200000,'sell_price'=>16500000,'has_serial'=>true,'warranty_months'=>36,'vat_percent'=>10],
            ['code'=>'SP-0006','name'=>'Router Cisco ISR4321',  'unit'=>'cái','category_id'=>$catIds[0],'cost_price'=>27500000,'sell_price'=>34500000,'has_serial'=>true,'warranty_months'=>36,'vat_percent'=>10],
            ['code'=>'SP-0007','name'=>'Server Dell PowerEdge R540','unit'=>'cái','category_id'=>$catIds[1],'cost_price'=>66000000,'sell_price'=>82000000,'has_serial'=>true,'warranty_months'=>36,'vat_percent'=>10],
            ['code'=>'SP-0008','name'=>'Camera Dahua IP 4MP',   'unit'=>'cái','category_id'=>$catIds[2],'cost_price'=>2420000,'sell_price'=>3200000,'has_serial'=>true,'warranty_months'=>24,'vat_percent'=>10],
            ['code'=>'SP-0009','name'=>'NVR Dahua 16 kênh',     'unit'=>'cái','category_id'=>$catIds[2],'cost_price'=>7700000,'sell_price'=>9800000,'has_serial'=>true,'warranty_months'=>24,'vat_percent'=>10],
            ['code'=>'SP-0010','name'=>'Cáp mạng Cat6 305m',   'unit'=>'cuộn','category_id'=>$catIds[3],'cost_price'=>770000,'sell_price'=>990000,'has_serial'=>false,'warranty_months'=>0,'vat_percent'=>10],
        ];
        foreach ($products as $p) {
            DB::table('products')->insert(array_merge($p, [
                'business_cost'=>0,'total_cost'=>$p['cost_price'],'min_stock'=>5,
                'is_active'=>true,'description'=>null,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }

        // Services (DV-0001 to DV-0003) — table only has: code, name, unit, price
        $services = [
            ['code'=>'DV-0001','name'=>'Thiết kế hạ tầng mạng','unit'=>'hạng mục','price'=>12000000],
            ['code'=>'DV-0002','name'=>'Lắp đặt & cấu hình','unit'=>'ngày','price'=>5000000],
            ['code'=>'DV-0003','name'=>'Bảo trì định kỳ','unit'=>'tháng','price'=>3000000],
        ];
        foreach ($services as $s) {
            DB::table('services')->insertOrIgnore(array_merge($s, [
                'is_active'=>true,'description'=>null,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }

        // Additional customers (KH-0003, KH-0004, KH-0005)
        $customers = [
            ['code'=>'KH-0003','name'=>'Ngân hàng CP Phương Đông','company'=>'OCB','tax_code'=>'0301456789','phone'=>'028.3822.8899','address'=>'45 Lê Duẩn, Quận 1, TP.HCM','credit_limit'=>1500000000],
            ['code'=>'KH-0004','name'=>'Trường ĐH Bách Khoa Hà Nội','company'=>'HUST','tax_code'=>'0100101459','phone'=>'024.3869.3939','address'=>'1 Đại Cồ Việt, Hai Bà Trưng, Hà Nội','credit_limit'=>500000000],
            ['code'=>'KH-0005','name'=>'Tập đoàn VietTech Corp','company'=>'VTC','tax_code'=>'0102030405','phone'=>'024.3944.5566','address'=>'55 Giải Phóng, Đống Đa, Hà Nội','credit_limit'=>2000000000],
        ];
        foreach ($customers as $c) {
            DB::table('customers')->insert(array_merge($c, [
                'created_at'=>$now,'updated_at'=>$now,
            ]));
        }

        // Additional suppliers (NCC-0003, NCC-0004)
        $suppliers = [
            ['code'=>'NCC-0003','name'=>'Dell EMC Việt Nam','phone'=>'028.3910.8899','address'=>'10 Phan Đình Giót, Tân Bình, TP.HCM','tax_code'=>'0306789012'],
            ['code'=>'NCC-0004','name'=>'Công ty CP Thiên Minh Tech','phone'=>'024.3915.2233','address'=>'88 Nguyễn Chí Thanh, Đống Đa, Hà Nội','tax_code'=>'0104567890'],
        ];
        foreach ($suppliers as $s) {
            DB::table('suppliers')->insert(array_merge($s, [
                'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }

    // ─── Accounting periods 2026 ──────────────────────────────────────────────

    private function seedAccountingPeriods(): void
    {
        $now = now();
        for ($m = 1; $m <= 12; $m++) {
            DB::table('accounting_periods')->insertOrIgnore([
                'year'=>2026,'month'=>$m,'status'=>'open',
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    // ─── Bank accounts ────────────────────────────────────────────────────────

    private function seedBankAccounts(): void
    {
        $now = now();
        DB::table('bank_accounts')->insert([
            ['name'=>'Tài khoản thanh toán VCB','bank_name'=>'Vietcombank',
             'account_number'=>'0441000123456','account_code'=>'112.VCB',
             'currency'=>'VND','opening_balance'=>1500000000,
             'is_active'=>true,'notes'=>'TK thanh toán chính','created_by'=>$this->adminId,
             'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Tài khoản thanh toán BIDV','bank_name'=>'BIDV',
             'account_number'=>'21110001234567','account_code'=>'112.BIDV',
             'currency'=>'VND','opening_balance'=>500000000,
             'is_active'=>true,'notes'=>'TK dự phòng','created_by'=>$this->adminId,
             'created_at'=>$now,'updated_at'=>$now],
        ]);
    }

    // ─── Fixed assets ─────────────────────────────────────────────────────────

    private function seedFixedAssets(): void
    {
        $now = now();
        $assets = [
            ['code'=>'TSCĐ-001','name'=>'Xe ô tô Toyota Fortuner 2.4G',
             'category'=>'vehicle','acquisition_date'=>'2024-01-15',
             'acquisition_cost'=>850000000,'useful_life_months'=>60,
             'depreciation_method'=>'straight_line',
             'accumulated_depreciation'=>255000000,
             'last_depreciation_period'=>'2026-04',
             'status'=>'active','notes'=>'Xe công vụ giám đốc','location'=>'Hà Nội'],
            ['code'=>'TSCĐ-002','name'=>'Máy chủ Dell PowerEdge R740',
             'category'=>'equipment','acquisition_date'=>'2025-03-01',
             'acquisition_cost'=>180000000,'useful_life_months'=>36,
             'depreciation_method'=>'straight_line',
             'accumulated_depreciation'=>15000000,
             'last_depreciation_period'=>'2026-04',
             'status'=>'active','notes'=>'Máy chủ trung tâm hệ thống','location'=>'Phòng máy chủ'],
            ['code'=>'TSCĐ-003','name'=>'Thiết bị văn phòng tổng hợp',
             'category'=>'office','acquisition_date'=>'2025-01-01',
             'acquisition_cost'=>120000000,'useful_life_months'=>60,
             'depreciation_method'=>'straight_line',
             'accumulated_depreciation'=>20000000,
             'last_depreciation_period'=>'2026-04',
             'status'=>'active','notes'=>'Bàn ghế, điều hòa, tủ hồ sơ','location'=>'Văn phòng HN'],
        ];
        foreach ($assets as $a) {
            DB::table('fixed_assets')->insert(array_merge($a, [
                'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }

    // ─── Invoices and payments ────────────────────────────────────────────────

    private function seedInvoicesAndPayments(): void
    {
        $now = now();
        // Look up customer IDs by code (safe regardless of sequence gaps)
        $cIds = DB::table('customers')->pluck('id', 'code');
        $kh1 = $cIds['KH-0001']; $kh2 = $cIds['KH-0002'];
        $kh3 = $cIds['KH-0003']; $kh5 = $cIds['KH-0005'];

        // Look up order IDs
        $oIds = DB::table('orders')->pluck('id', 'code');

        $invoices = [
            // Feb 2026
            ['code'=>'HĐ-0001','customer_id'=>$kh1,'order_id'=>null,'issue_date'=>'2026-02-05','due_date'=>'2026-03-07','subtotal'=>200000000,'tax_amount'=>20000000,'total'=>220000000,'status'=>'paid','notes'=>'Cung cấp thiết bị mạng tháng 2/2026'],
            ['code'=>'HĐ-0002','customer_id'=>$kh2,'order_id'=>null,'issue_date'=>'2026-02-05','due_date'=>'2026-03-07','subtotal'=>70000000,'tax_amount'=>7000000,'total'=>77000000,'status'=>'paid','notes'=>'Dịch vụ thiết kế hạ tầng tháng 2/2026'],
            // Mar 2026
            ['code'=>'HĐ-0003','customer_id'=>$kh1,'order_id'=>null,'issue_date'=>'2026-03-05','due_date'=>'2026-04-05','subtotal'=>300000000,'tax_amount'=>30000000,'total'=>330000000,'status'=>'paid','notes'=>'Cung cấp server & dịch vụ triển khai tháng 3/2026'],
            ['code'=>'HĐ-0004','customer_id'=>$kh3,'order_id'=>null,'issue_date'=>'2026-03-05','due_date'=>'2026-04-05','subtotal'=>100000000,'tax_amount'=>10000000,'total'=>110000000,'status'=>'paid','notes'=>'Hệ thống camera an ninh tháng 3/2026'],
            // Apr 2026
            ['code'=>'HĐ-0005','customer_id'=>$kh1,'order_id'=>null,'issue_date'=>'2026-04-05','due_date'=>'2026-05-05','subtotal'=>250000000,'tax_amount'=>25000000,'total'=>275000000,'status'=>'sent','notes'=>'Nâng cấp hạ tầng mạng tháng 4/2026'],
            ['code'=>'HĐ-0006','customer_id'=>$kh5,'order_id'=>null,'issue_date'=>'2026-04-05','due_date'=>'2026-05-01','subtotal'=>100000000,'tax_amount'=>10000000,'total'=>110000000,'status'=>'overdue','notes'=>'Cung cấp thiết bị CCTV tháng 4/2026'],
            // May 2026 — từ các đơn hàng đã có
            ['code'=>'HĐ-0007','customer_id'=>$kh1,'order_id'=>$oIds['DH-0001']??null,'issue_date'=>'2026-05-25','due_date'=>'2026-06-25','subtotal'=>84040000,'tax_amount'=>8404000,'total'=>92444000,'status'=>'sent','notes'=>'Đơn hàng DH-0001'],
            ['code'=>'HĐ-0008','customer_id'=>$kh2,'order_id'=>$oIds['DH-0002']??null,'issue_date'=>'2026-05-25','due_date'=>'2026-06-25','subtotal'=>36696000,'tax_amount'=>3669600,'total'=>40365600,'status'=>'sent','notes'=>'Đơn hàng DH-0002'],
            ['code'=>'HĐ-0009','customer_id'=>$kh1,'order_id'=>$oIds['DH-0006']??null,'issue_date'=>'2026-05-28','due_date'=>'2026-06-28','subtotal'=>29000000,'tax_amount'=>2900000,'total'=>31900000,'status'=>'sent','notes'=>'Đơn hàng DH-0006'],
            ['code'=>'HĐ-0010','customer_id'=>$kh1,'order_id'=>$oIds['DH-0007']??null,'issue_date'=>'2026-05-28','due_date'=>'2026-06-28','subtotal'=>29000000,'tax_amount'=>2900000,'total'=>31900000,'status'=>'sent','notes'=>'Đơn hàng DH-0007'],
        ];

        foreach ($invoices as $inv) {
            DB::table('invoices')->insert(array_merge($inv, [
                'created_by'=>$this->salesId,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }

        // Payments — column is 'method' not 'payment_method'
        $invIds = DB::table('invoices')->orderBy('id')->pluck('id', 'code');
        $payments = [
            ['invoice_id'=>$invIds['HĐ-0001'],'amount'=>220000000,'method'=>'bank_transfer','payment_date'=>'2026-02-15','notes'=>'CK qua VCB'],
            ['invoice_id'=>$invIds['HĐ-0002'],'amount'=>77000000,'method'=>'bank_transfer','payment_date'=>'2026-02-15','notes'=>'CK qua VCB'],
            ['invoice_id'=>$invIds['HĐ-0003'],'amount'=>330000000,'method'=>'bank_transfer','payment_date'=>'2026-03-20','notes'=>'CK qua BIDV'],
            ['invoice_id'=>$invIds['HĐ-0004'],'amount'=>110000000,'method'=>'bank_transfer','payment_date'=>'2026-03-20','notes'=>'CK qua VCB'],
            ['invoice_id'=>$invIds['HĐ-0005'],'amount'=>150000000,'method'=>'bank_transfer','payment_date'=>'2026-04-20','notes'=>'Thanh toán lần 1/2'],
        ];
        foreach ($payments as $p) {
            DB::table('payments')->insert(array_merge($p, [
                'created_by'=>$this->salesId,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }

    // ─── Purchase invoices ────────────────────────────────────────────────────

    private function seedPurchaseInvoices(): void
    {
        $now = now();
        $wh1 = DB::table('warehouses')->value('id');

        // Create 3 purchase orders for linking to purchase invoices
        $po1 = DB::table('purchase_orders')->insertGetId(['code'=>'MH-0004','supplier_id'=>1,'warehouse_id'=>$wh1,'created_by'=>$this->adminId,'order_date'=>'2026-02-08','expected_date'=>'2026-02-12','status'=>'received','notes'=>'Mua thiết bị mạng T2','created_at'=>$now,'updated_at'=>$now]);
        $po2 = DB::table('purchase_orders')->insertGetId(['code'=>'MH-0005','supplier_id'=>1,'warehouse_id'=>$wh1,'created_by'=>$this->adminId,'order_date'=>'2026-03-08','expected_date'=>'2026-03-12','status'=>'received','notes'=>'Mua server & thiết bị T3','created_at'=>$now,'updated_at'=>$now]);
        $po3 = DB::table('purchase_orders')->insertGetId(['code'=>'MH-0006','supplier_id'=>2,'warehouse_id'=>$wh1,'created_by'=>$this->adminId,'order_date'=>'2026-04-08','expected_date'=>'2026-04-12','status'=>'received','notes'=>'Mua thiết bị camera T4','created_at'=>$now,'updated_at'=>$now]);

        $pis = [
            ['code'=>'HD-NCC-0001','purchase_order_id'=>$po1,'supplier_id'=>1,'invoice_number'=>'SI/2026/02/001','invoice_date'=>'2026-02-10','due_date'=>'2026-03-10','subtotal'=>200000000,'tax_amount'=>20000000,'total'=>220000000,'paid_amount'=>220000000,'status'=>'paid','notes'=>'Mua thiết bị mạng tháng 2'],
            ['code'=>'HD-NCC-0002','purchase_order_id'=>$po2,'supplier_id'=>1,'invoice_number'=>'SI/2026/03/001','invoice_date'=>'2026-03-10','due_date'=>'2026-04-10','subtotal'=>270000000,'tax_amount'=>27000000,'total'=>297000000,'paid_amount'=>250000000,'status'=>'partial_paid','notes'=>'Mua server & thiết bị tháng 3'],
            ['code'=>'HD-NCC-0003','purchase_order_id'=>$po3,'supplier_id'=>2,'invoice_number'=>'DELL/2026/04/05','invoice_date'=>'2026-04-10','due_date'=>'2026-05-10','subtotal'=>230000000,'tax_amount'=>23000000,'total'=>253000000,'paid_amount'=>0,'status'=>'valid','notes'=>'Mua thiết bị camera tháng 4'],
        ];
        foreach ($pis as $pi) {
            DB::table('purchase_invoices')->insert(array_merge($pi, [
                'created_by'=>$this->adminId,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }

        // Purchase invoice payments
        $piIds = DB::table('purchase_invoices')->orderBy('id')->pluck('id', 'code');
        $piPayments = [
            ['purchase_invoice_id'=>$piIds['HD-NCC-0001'],'amount'=>220000000,'payment_date'=>'2026-02-25','method'=>'bank_transfer','notes'=>'CK thanh toán NCC-0001'],
            ['purchase_invoice_id'=>$piIds['HD-NCC-0002'],'amount'=>250000000,'payment_date'=>'2026-03-25','method'=>'bank_transfer','notes'=>'CK thanh toán NCC-0001'],
        ];
        foreach ($piPayments as $p) {
            DB::table('purchase_invoice_payments')->insert(array_merge($p, [
                'created_by'=>$this->adminId,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }

    // ─── Cash vouchers ────────────────────────────────────────────────────────

    private function seedCashVouchers(): void
    {
        $now = now();
        // Create a fund — columns: code, name, type, bank_name, bank_account_no, opening_balance, is_active, notes
        $fundId = DB::table('funds')->insertGetId([
            'name'            => 'Quỹ tiền mặt chính',
            'code'            => 'QTM-001',
            'type'            => 'cash',
            'opening_balance' => 200000000,
            'is_active'       => true,
            'notes'           => 'Quỹ tiền mặt văn phòng chính',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $vouchers = [
            // Receipts (PT-)
            ['code'=>'PT-0001','type'=>'receipt','status'=>'confirmed','amount'=>5000000,'voucher_date'=>'2026-02-28','counterparty'=>'Khách lẻ','description'=>'Thu tiền bảo trì thiết bị phát sinh','fund_id'=>$fundId],
            ['code'=>'PT-0002','type'=>'receipt','status'=>'confirmed','amount'=>8000000,'voucher_date'=>'2026-03-31','counterparty'=>'KH nhỏ lẻ','description'=>'Thu phí dịch vụ tư vấn','fund_id'=>$fundId],
            ['code'=>'PT-0003','type'=>'receipt','status'=>'confirmed','amount'=>6000000,'voucher_date'=>'2026-04-30','counterparty'=>'Khách vãng lai','description'=>'Thu phí đào tạo nhân viên','fund_id'=>$fundId],
            ['code'=>'PT-0004','type'=>'receipt','status'=>'draft','amount'=>10000000,'voucher_date'=>'2026-05-28','counterparty'=>'PFVN','description'=>'Thu tiền tạm ứng hỗ trợ kỹ thuật','fund_id'=>$fundId],
            // Payments (PC-)
            ['code'=>'PC-0001','type'=>'payment','status'=>'confirmed','amount'=>15000000,'voucher_date'=>'2026-02-05','counterparty'=>'CĐT Sunrise Tower','description'=>'Tiền thuê văn phòng tháng 2/2026','fund_id'=>$fundId],
            ['code'=>'PC-0002','type'=>'payment','status'=>'confirmed','amount'=>15000000,'voucher_date'=>'2026-03-05','counterparty'=>'CĐT Sunrise Tower','description'=>'Tiền thuê văn phòng tháng 3/2026','fund_id'=>$fundId],
            ['code'=>'PC-0003','type'=>'payment','status'=>'confirmed','amount'=>15000000,'voucher_date'=>'2026-04-05','counterparty'=>'CĐT Sunrise Tower','description'=>'Tiền thuê văn phòng tháng 4/2026','fund_id'=>$fundId],
            ['code'=>'PC-0004','type'=>'payment','status'=>'confirmed','amount'=>15000000,'voucher_date'=>'2026-05-05','counterparty'=>'CĐT Sunrise Tower','description'=>'Tiền thuê văn phòng tháng 5/2026','fund_id'=>$fundId],
        ];
        foreach ($vouchers as $v) {
            DB::table('cash_vouchers')->insert(array_merge($v, [
                'created_by'=>$this->adminId,'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }

    // ─── Payrolls ─────────────────────────────────────────────────────────────

    private function seedPayrolls(): void
    {
        $now = now();
        $users = DB::table('users')->whereIn('email', [
            'sales@minierp.local','kt@minierp.local','kho@minierp.local',
        ])->get(['id','name','base_salary','allowance','dependents_count']);

        $periods = ['2026-03' => 'paid', '2026-04' => 'confirmed'];
        foreach ($periods as $period => $status) {
            $totalBase = $totalAllow = $totalGross = $totalInsEmp = $totalInsEmpr = $totalPit = $totalNet = 0;

            $payrollId = DB::table('payrolls')->insertGetId([
                'code'   => 'BL-' . str_replace('-', '', $period),
                'period' => $period,
                'status' => $status,
                'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
                'total_gross' => 0, 'total_insurance_employee' => 0,
                'total_insurance_employer' => 0, 'total_pit' => 0,
                'total_deductions' => 0, 'total_net_salary' => 0,
                'created_by' => $this->adminId,
                'notes' => "Bảng lương kỳ {$period}",
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($users as $u) {
                $base  = (float) ($u->base_salary ?? 15000000);
                $allow = (float) ($u->allowance   ?? 2000000);
                $gross = $base + $allow;
                $ins_base = min($gross, 46800000);
                $bhxh_emp  = round($ins_base * 0.08);
                $bhyt_emp  = round($ins_base * 0.015);
                $bhtn_emp  = round($ins_base * 0.01);
                $ins_emp   = $bhxh_emp + $bhyt_emp + $bhtn_emp;
                $bhxh_empr = round($ins_base * 0.175);
                $bhyt_empr = round($ins_base * 0.03);
                $bhtn_empr = round($ins_base * 0.01);
                $ins_empr  = $bhxh_empr + $bhyt_empr + $bhtn_empr;
                $taxable   = $gross - $ins_emp - 11000000 - ((int)($u->dependents_count ?? 0) * 4400000);
                $pit = max(0, $this->calcPit($taxable));
                $net = $gross - $ins_emp - $pit;

                DB::table('payroll_items')->insert([
                    'payroll_id'             => $payrollId,
                    'user_id'                => $u->id,
                    'base_salary'            => $base,
                    'allowance'              => $allow,
                    'bonus'                  => 0,
                    'insurance_base'         => $ins_base,
                    'bhxh_employee'          => $bhxh_emp,
                    'bhyt_employee'          => $bhyt_emp,
                    'bhtn_employee'          => $bhtn_emp,
                    'bhxh_employer'          => $bhxh_empr,
                    'bhyt_employer'          => $bhyt_empr,
                    'bhtn_employer'          => $bhtn_empr,
                    'pit'                    => $pit,
                    'deductions'             => 0,
                    'net_salary'             => $net,
                    'dependents_count'       => (int)($u->dependents_count ?? 0),
                    'status'                 => 'active',
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);

                $totalBase  += $base;
                $totalAllow += $allow;
                $totalGross += $gross;
                $totalInsEmp  += $ins_emp;
                $totalInsEmpr += $ins_empr;
                $totalPit   += $pit;
                $totalNet   += $net;
            }

            DB::table('payrolls')->where('id', $payrollId)->update([
                'total_base_salary'          => $totalBase,
                'total_allowance'            => $totalAllow,
                'total_gross'                => $totalGross,
                'total_insurance_employee'   => $totalInsEmp,
                'total_insurance_employer'   => $totalInsEmpr,
                'total_pit'                  => $totalPit,
                'total_net_salary'           => $totalNet,
            ]);
        }
    }

    private function calcPit(float $taxable): float
    {
        if ($taxable <= 0) return 0;
        $brackets = [
            [5000000,   0.05],
            [10000000,  0.10],
            [18000000,  0.15],
            [32000000,  0.20],
            [52000000,  0.25],
            [80000000,  0.30],
            [PHP_INT_MAX, 0.35],
        ];
        $pit = 0; $prev = 0;
        foreach ($brackets as [$cap, $rate]) {
            if ($taxable <= $prev) break;
            $pit += min($taxable - $prev, $cap - $prev) * $rate;
            $prev = $cap;
        }
        return round($pit);
    }

    // ─── Journal entries ──────────────────────────────────────────────────────

    private function seedJournalEntries(): void
    {
        // Start from the next available sequence to avoid duplicates
        $lastCode = DB::table('journal_entries')
            ->where('code', 'like', 'BT-2026%')
            ->orderByDesc('code')->value('code');
        $seq = $lastCode ? ((int) substr($lastCode, -4)) + 1 : 1;
        // Use regular closure with reference so $seq increments correctly
        $mkCode = function () use (&$seq) {
            return sprintf('BT-2026%04d', $seq++);
        };

        $entries = [
            // ── 2026-01-01 Số dư đầu kỳ ──────────────────────────────────────
            ['date'=>'2026-01-01','desc'=>'Số dư đầu kỳ năm 2026','ref'=>null,'lines'=>[
                ['a'=>'111','dr'=>200000000,'cr'=>0,'d'=>'Tiền mặt tại quỹ đầu kỳ'],
                ['a'=>'112','dr'=>2000000000,'cr'=>0,'d'=>'TGNH đầu kỳ'],
                ['a'=>'156','dr'=>350000000,'cr'=>0,'d'=>'Hàng tồn kho đầu kỳ'],
                ['a'=>'211','dr'=>450000000,'cr'=>0,'d'=>'TSCĐ đầu kỳ'],
                ['a'=>'411','dr'=>0,'cr'=>3000000000,'d'=>'Vốn đầu tư chủ sở hữu'],
            ]],

            // ── 2026-02 ───────────────────────────────────────────────────────
            ['date'=>'2026-02-05','desc'=>'Doanh thu bán hàng & DV tháng 2/2026 (HĐ-0001, HĐ-0002)','ref'=>'invoice','lines'=>[
                ['a'=>'131','dr'=>297000000,'cr'=>0,'d'=>'Phải thu khách hàng'],
                ['a'=>'5111','dr'=>0,'cr'=>200000000,'d'=>'Doanh thu bán HH tháng 2'],
                ['a'=>'5113','dr'=>0,'cr'=>70000000,'d'=>'Doanh thu DV tháng 2'],
                ['a'=>'3331','dr'=>0,'cr'=>27000000,'d'=>'VAT đầu ra tháng 2'],
            ]],
            ['date'=>'2026-02-05','desc'=>'Giá vốn hàng bán tháng 2/2026','ref'=>null,'lines'=>[
                ['a'=>'632','dr'=>180000000,'cr'=>0,'d'=>'Giá vốn HH xuất kho'],
                ['a'=>'156','dr'=>0,'cr'=>180000000,'d'=>'Hàng tồn kho xuất bán'],
            ]],
            ['date'=>'2026-02-10','desc'=>'Mua hàng từ NCC tháng 2/2026 (HD-NCC-0001)','ref'=>null,'lines'=>[
                ['a'=>'156','dr'=>220000000,'cr'=>0,'d'=>'Nhập kho hàng hóa (incl. VAT)'],
                ['a'=>'331','dr'=>0,'cr'=>220000000,'d'=>'Phải trả NCC-0001'],
            ]],
            ['date'=>'2026-02-15','desc'=>'Thu tiền khách hàng tháng 2','ref'=>null,'lines'=>[
                ['a'=>'112','dr'=>297000000,'cr'=>0,'d'=>'Nhận CK từ BPVN & PFVN'],
                ['a'=>'131','dr'=>0,'cr'=>297000000,'d'=>'Ghi nhận thanh toán AR'],
            ]],
            ['date'=>'2026-02-25','desc'=>'Trả tiền nhà cung cấp tháng 2','ref'=>null,'lines'=>[
                ['a'=>'331','dr'=>220000000,'cr'=>0,'d'=>'Thanh toán NCC-0001'],
                ['a'=>'112','dr'=>0,'cr'=>220000000,'d'=>'Xuất khoản TGNH'],
            ]],
            ['date'=>'2026-02-28','desc'=>'Chi phí lương & BHXH tháng 2/2026','ref'=>null,'lines'=>[
                ['a'=>'641','dr'=>25000000,'cr'=>0,'d'=>'CP bán hàng (lương sales)'],
                ['a'=>'642','dr'=>45000000,'cr'=>0,'d'=>'CP QLDN (lương admin, kỹ thuật)'],
                ['a'=>'334','dr'=>0,'cr'=>63000000,'d'=>'Lương phải trả NLĐ'],
                ['a'=>'3338','dr'=>0,'cr'=>7000000,'d'=>'BHXH/BHYT phải nộp'],
            ]],
            ['date'=>'2026-02-28','desc'=>'Khấu hao TSCĐ tháng 2/2026','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>21166667,'cr'=>0,'d'=>'CP KH TSCĐ tháng 2 (3 tài sản)'],
                ['a'=>'214','dr'=>0,'cr'=>21166667,'d'=>'Hao mòn TSCĐ lũy kế'],
            ]],
            ['date'=>'2026-02-28','desc'=>'Chi phí thuê văn phòng tháng 2','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>15000000,'cr'=>0,'d'=>'CP thuê VP tháng 2'],
                ['a'=>'111','dr'=>0,'cr'=>15000000,'d'=>'Xuất quỹ tiền mặt'],
            ]],

            // ── 2026-03 ───────────────────────────────────────────────────────
            ['date'=>'2026-03-05','desc'=>'Doanh thu bán hàng & DV tháng 3/2026 (HĐ-0003, HĐ-0004)','ref'=>'invoice','lines'=>[
                ['a'=>'131','dr'=>440000000,'cr'=>0,'d'=>'Phải thu khách hàng'],
                ['a'=>'5111','dr'=>0,'cr'=>300000000,'d'=>'Doanh thu bán HH tháng 3'],
                ['a'=>'5113','dr'=>0,'cr'=>100000000,'d'=>'Doanh thu DV tháng 3'],
                ['a'=>'3331','dr'=>0,'cr'=>40000000,'d'=>'VAT đầu ra tháng 3'],
            ]],
            ['date'=>'2026-03-05','desc'=>'Giá vốn hàng bán tháng 3/2026','ref'=>null,'lines'=>[
                ['a'=>'632','dr'=>270000000,'cr'=>0,'d'=>'Giá vốn HH xuất kho'],
                ['a'=>'156','dr'=>0,'cr'=>270000000,'d'=>'Hàng tồn kho xuất bán'],
            ]],
            ['date'=>'2026-03-10','desc'=>'Mua hàng từ NCC tháng 3/2026 (HD-NCC-0002)','ref'=>null,'lines'=>[
                ['a'=>'156','dr'=>297000000,'cr'=>0,'d'=>'Nhập kho hàng hóa (incl. VAT)'],
                ['a'=>'331','dr'=>0,'cr'=>297000000,'d'=>'Phải trả NCC-0001'],
            ]],
            ['date'=>'2026-03-20','desc'=>'Thu tiền khách hàng tháng 3','ref'=>null,'lines'=>[
                ['a'=>'112','dr'=>440000000,'cr'=>0,'d'=>'Nhận CK từ BPVN & OCB'],
                ['a'=>'131','dr'=>0,'cr'=>440000000,'d'=>'Ghi nhận thanh toán AR'],
            ]],
            ['date'=>'2026-03-25','desc'=>'Trả tiền nhà cung cấp tháng 3','ref'=>null,'lines'=>[
                ['a'=>'331','dr'=>250000000,'cr'=>0,'d'=>'Thanh toán NCC-0001 (một phần)'],
                ['a'=>'112','dr'=>0,'cr'=>250000000,'d'=>'Xuất khoản TGNH'],
            ]],
            ['date'=>'2026-03-31','desc'=>'Chi phí lương & BHXH tháng 3/2026','ref'=>'payroll','lines'=>[
                ['a'=>'641','dr'=>30000000,'cr'=>0,'d'=>'CP bán hàng (lương sales)'],
                ['a'=>'642','dr'=>55000000,'cr'=>0,'d'=>'CP QLDN (lương admin, kỹ thuật)'],
                ['a'=>'334','dr'=>0,'cr'=>76500000,'d'=>'Lương phải trả NLĐ'],
                ['a'=>'3338','dr'=>0,'cr'=>8500000,'d'=>'BHXH/BHYT phải nộp'],
            ]],
            ['date'=>'2026-03-31','desc'=>'Khấu hao TSCĐ tháng 3/2026','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>21166667,'cr'=>0,'d'=>'CP KH TSCĐ tháng 3'],
                ['a'=>'214','dr'=>0,'cr'=>21166667,'d'=>'Hao mòn TSCĐ lũy kế'],
            ]],
            ['date'=>'2026-03-31','desc'=>'Chi phí thuê văn phòng tháng 3','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>15000000,'cr'=>0,'d'=>'CP thuê VP tháng 3'],
                ['a'=>'111','dr'=>0,'cr'=>15000000,'d'=>'Xuất quỹ tiền mặt'],
            ]],

            // ── 2026-04 ───────────────────────────────────────────────────────
            ['date'=>'2026-04-05','desc'=>'Doanh thu bán hàng & DV tháng 4/2026 (HĐ-0005, HĐ-0006)','ref'=>'invoice','lines'=>[
                ['a'=>'131','dr'=>385000000,'cr'=>0,'d'=>'Phải thu khách hàng'],
                ['a'=>'5111','dr'=>0,'cr'=>250000000,'d'=>'Doanh thu bán HH tháng 4'],
                ['a'=>'5113','dr'=>0,'cr'=>100000000,'d'=>'Doanh thu DV tháng 4'],
                ['a'=>'3331','dr'=>0,'cr'=>35000000,'d'=>'VAT đầu ra tháng 4'],
            ]],
            ['date'=>'2026-04-05','desc'=>'Giá vốn hàng bán tháng 4/2026','ref'=>null,'lines'=>[
                ['a'=>'632','dr'=>235000000,'cr'=>0,'d'=>'Giá vốn HH xuất kho'],
                ['a'=>'156','dr'=>0,'cr'=>235000000,'d'=>'Hàng tồn kho xuất bán'],
            ]],
            ['date'=>'2026-04-10','desc'=>'Mua hàng từ NCC tháng 4/2026 (HD-NCC-0003)','ref'=>null,'lines'=>[
                ['a'=>'156','dr'=>253000000,'cr'=>0,'d'=>'Nhập kho hàng hóa (incl. VAT)'],
                ['a'=>'331','dr'=>0,'cr'=>253000000,'d'=>'Phải trả NCC-0003 (Dell)'],
            ]],
            ['date'=>'2026-04-20','desc'=>'Thu tiền khách hàng tháng 4 (HĐ-0005 một phần)','ref'=>null,'lines'=>[
                ['a'=>'112','dr'=>150000000,'cr'=>0,'d'=>'Nhận CK từ BPVN'],
                ['a'=>'131','dr'=>0,'cr'=>150000000,'d'=>'Ghi nhận thanh toán AR'],
            ]],
            ['date'=>'2026-04-30','desc'=>'Chi phí lương & BHXH tháng 4/2026','ref'=>'payroll','lines'=>[
                ['a'=>'641','dr'=>25000000,'cr'=>0,'d'=>'CP bán hàng (lương sales)'],
                ['a'=>'642','dr'=>50000000,'cr'=>0,'d'=>'CP QLDN (lương admin, kỹ thuật)'],
                ['a'=>'334','dr'=>0,'cr'=>67500000,'d'=>'Lương phải trả NLĐ'],
                ['a'=>'3338','dr'=>0,'cr'=>7500000,'d'=>'BHXH/BHYT phải nộp'],
            ]],
            ['date'=>'2026-04-30','desc'=>'Khấu hao TSCĐ tháng 4/2026','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>21166667,'cr'=>0,'d'=>'CP KH TSCĐ tháng 4'],
                ['a'=>'214','dr'=>0,'cr'=>21166667,'d'=>'Hao mòn TSCĐ lũy kế'],
            ]],
            ['date'=>'2026-04-30','desc'=>'Chi phí thuê văn phòng tháng 4','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>15000000,'cr'=>0,'d'=>'CP thuê VP tháng 4'],
                ['a'=>'111','dr'=>0,'cr'=>15000000,'d'=>'Xuất quỹ tiền mặt'],
            ]],

            // ── 2026-05 ───────────────────────────────────────────────────────
            ['date'=>'2026-05-25','desc'=>'Doanh thu bán hàng tháng 5/2026 (HĐ-0007 đến HĐ-0010)','ref'=>'invoice','lines'=>[
                ['a'=>'131','dr'=>196609600,'cr'=>0,'d'=>'Phải thu khách hàng tháng 5'],
                ['a'=>'5111','dr'=>0,'cr'=>178736000,'d'=>'Doanh thu bán HH tháng 5'],
                ['a'=>'3331','dr'=>0,'cr'=>17873600,'d'=>'VAT đầu ra tháng 5'],
            ]],
            ['date'=>'2026-05-25','desc'=>'Giá vốn hàng bán tháng 5/2026','ref'=>null,'lines'=>[
                ['a'=>'632','dr'=>120000000,'cr'=>0,'d'=>'Giá vốn HH xuất kho tháng 5'],
                ['a'=>'156','dr'=>0,'cr'=>120000000,'d'=>'Hàng tồn kho xuất bán'],
            ]],
            ['date'=>'2026-05-28','desc'=>'Chi phí lương & BHXH tháng 5/2026','ref'=>null,'lines'=>[
                ['a'=>'641','dr'=>18000000,'cr'=>0,'d'=>'CP bán hàng tháng 5'],
                ['a'=>'642','dr'=>37000000,'cr'=>0,'d'=>'CP QLDN tháng 5'],
                ['a'=>'334','dr'=>0,'cr'=>49950000,'d'=>'Lương phải trả NLĐ'],
                ['a'=>'3338','dr'=>0,'cr'=>5050000,'d'=>'BHXH/BHYT phải nộp'],
            ]],
            ['date'=>'2026-05-28','desc'=>'Khấu hao TSCĐ tháng 5/2026','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>21166667,'cr'=>0,'d'=>'CP KH TSCĐ tháng 5'],
                ['a'=>'214','dr'=>0,'cr'=>21166667,'d'=>'Hao mòn TSCĐ lũy kế'],
            ]],
            ['date'=>'2026-05-05','desc'=>'Chi phí thuê văn phòng tháng 5','ref'=>null,'lines'=>[
                ['a'=>'642','dr'=>15000000,'cr'=>0,'d'=>'CP thuê VP tháng 5'],
                ['a'=>'111','dr'=>0,'cr'=>15000000,'d'=>'Xuất quỹ tiền mặt'],
            ]],
        ];

        $now = now();
        foreach ($entries as $entry) {
            $code = $mkCode();
            $entryId = DB::table('journal_entries')->insertGetId([
                'code'           => $code,
                'entry_date'     => $entry['date'],
                'description'    => $entry['desc'],
                'reference_type' => $entry['ref'],
                'reference_id'   => null,
                'status'         => 'posted',
                'is_auto'        => false,
                'created_by'     => $this->adminId,
                'posted_at'      => $now,
                'notes'          => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            foreach ($entry['lines'] as $i => $line) {
                DB::table('journal_entry_lines')->insert([
                    'journal_entry_id' => $entryId,
                    'account_code'     => $line['a'],
                    'description'      => $line['d'],
                    'debit'            => $line['dr'],
                    'credit'           => $line['cr'],
                    'sort_order'       => $i,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        }
    }

    // ─── Ensure extended customers (KH-0003..5) exist ────────────────────────

    private function ensureExtendedCustomers(): void
    {
        $now = now();
        $customers = [
            ['code'=>'KH-0003','name'=>'Ngân hàng CP Phương Đông','company'=>'OCB','tax_code'=>'0300456789','phone'=>'028.3822.8899','address'=>'45 Lê Duẩn, Quận 1, TP.HCM','credit_limit'=>1500000000],
            ['code'=>'KH-0004','name'=>'Trường ĐH Bách Khoa Hà Nội','company'=>'HUST','tax_code'=>'0100101459','phone'=>'024.3869.3939','address'=>'1 Đại Cồ Việt, Hai Bà Trưng, Hà Nội','credit_limit'=>500000000],
            ['code'=>'KH-0005','name'=>'Tập đoàn VietTech Corp','company'=>'VTC','tax_code'=>'0102030405','phone'=>'024.3944.5566','address'=>'55 Giải Phóng, Đống Đa, Hà Nội','credit_limit'=>2000000000],
        ];
        foreach ($customers as $c) {
            if (!DB::table('customers')->where('code', $c['code'])->exists()) {
                DB::table('customers')->insert(array_merge($c, ['created_at'=>$now,'updated_at'=>$now]));
            }
        }
    }

    // ─── Projects ─────────────────────────────────────────────────────────────

    private function seedProjects(): void
    {
        $now = now();
        $ktId = (int) DB::table('users')->where('email', 'kt@minierp.local')->value('id');

        $cIds = DB::table('customers')->pluck('id', 'code');

        $projects = [
            ['code'=>'DA-0001','name'=>'Triển khai hạ tầng mạng OCB Quận 1','customer_id'=>$cIds['KH-0003']??null,'manager_id'=>$ktId,'status'=>'completed','start_date'=>'2026-02-01','expected_end_date'=>'2026-03-31','budget'=>280000000,'notes'=>'Dự án hoàn thành đúng tiến độ'],
            ['code'=>'DA-0002','name'=>'Hệ thống camera an ninh Bách Khoa HN','customer_id'=>$cIds['KH-0004']??null,'manager_id'=>$ktId,'status'=>'active','start_date'=>'2026-04-01','expected_end_date'=>'2026-06-30','budget'=>150000000,'notes'=>'Đang thi công giai đoạn 2'],
            ['code'=>'DA-0003','name'=>'Nâng cấp DC VietTech Corp','customer_id'=>$cIds['KH-0005']??null,'manager_id'=>$ktId,'status'=>'planning','start_date'=>'2026-06-01','expected_end_date'=>'2026-09-30','budget'=>500000000,'notes'=>'Đang khảo sát thực địa'],
        ];

        foreach ($projects as $p) {
            $pid = DB::table('projects')->insertGetId(array_merge($p, [
                'created_by'=>$this->adminId,'created_at'=>$now,'updated_at'=>$now,
            ]));

            // Project members
            DB::table('project_members')->insert([
                ['project_id'=>$pid,'user_id'=>$ktId,'role'=>'lead','created_at'=>$now,'updated_at'=>$now],
                ['project_id'=>$pid,'user_id'=>$this->salesId,'role'=>'member','created_at'=>$now,'updated_at'=>$now],
            ]);

            // Project tasks
            $tasks = match($p['code']) {
                'DA-0001' => [
                    ['name'=>'Khảo sát hiện trạng mạng','status'=>'completed','priority'=>'high'],
                    ['name'=>'Lắp đặt switch & router','status'=>'completed','priority'=>'high'],
                    ['name'=>'Cấu hình VLAN & ACL','status'=>'completed','priority'=>'medium'],
                    ['name'=>'Kiểm thử & bàn giao','status'=>'completed','priority'=>'low'],
                ],
                'DA-0002' => [
                    ['name'=>'Khảo sát vị trí lắp camera','status'=>'completed','priority'=>'high'],
                    ['name'=>'Kéo cáp & đi dây','status'=>'in_progress','priority'=>'high'],
                    ['name'=>'Lắp đặt camera & NVR','status'=>'pending','priority'=>'medium'],
                    ['name'=>'Cấu hình ghi hình & alert','status'=>'pending','priority'=>'low'],
                ],
                default => [
                    ['name'=>'Họp khởi động dự án','status'=>'completed','priority'=>'high'],
                    ['name'=>'Thiết kế kiến trúc DC','status'=>'in_progress','priority'=>'high'],
                    ['name'=>'Lập kế hoạch migration','status'=>'pending','priority'=>'medium'],
                ],
            };
            foreach ($tasks as $t) {
                DB::table('project_tasks')->insert([
                    'project_id'  => $pid,
                    'title'       => $t['name'],
                    'status'      => $t['status'],
                    'priority'    => $t['priority'],
                    'assigned_to' => $ktId,
                    'due_date'    => null,
                    'description' => null,
                    'created_by'  => $this->adminId,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    // ─── Tickets ──────────────────────────────────────────────────────────────

    private function seedTickets(): void
    {
        $now = now();
        $ktId = (int) DB::table('users')->where('email', 'kt@minierp.local')->value('id');

        $cIds = DB::table('customers')->pluck('id', 'code');

        $tickets = [
            ['code'=>'TK-0001','customer_id'=>$cIds['KH-0001'],'title'=>'Mất kết nối internet tầng 3','status'=>'resolved','priority'=>'high','assigned_to'=>$ktId,'created_by'=>$this->adminId,'description'=>'Khách báo mất kết nối toàn bộ tầng 3 từ 8h sáng'],
            ['code'=>'TK-0002','customer_id'=>$cIds['KH-0003']??$cIds['KH-0001'],'title'=>'Camera số 5 báo lỗi tín hiệu','status'=>'in_progress','priority'=>'medium','assigned_to'=>$ktId,'created_by'=>$this->adminId,'description'=>'Camera IP tầng 1 khu A không lên hình'],
            ['code'=>'TK-0003','customer_id'=>$cIds['KH-0002'],'title'=>'Yêu cầu cấu hình thêm SSID WiFi','status'=>'new','priority'=>'low','assigned_to'=>null,'created_by'=>$this->adminId,'description'=>'Cần thêm SSID cho nhân viên mới'],
            ['code'=>'TK-0004','customer_id'=>$cIds['KH-0004']??$cIds['KH-0001'],'title'=>'Switch báo đèn amber port 12','status'=>'assigned','priority'=>'medium','assigned_to'=>$ktId,'created_by'=>$this->adminId,'description'=>'Port 12 trên switch tầng 2 đang nhấp nháy amber'],
            ['code'=>'TK-0005','customer_id'=>$cIds['KH-0001'],'title'=>'Báo cáo hiệu suất mạng tháng 5','status'=>'closed','priority'=>'low','assigned_to'=>$ktId,'created_by'=>$this->adminId,'description'=>'Yêu cầu report thống kê băng thông tháng 5'],
        ];

        foreach ($tickets as $t) {
            DB::table('tickets')->insert(array_merge($t, [
                'created_at'=>$now,'updated_at'=>$now,
            ]));
        }
    }
}
