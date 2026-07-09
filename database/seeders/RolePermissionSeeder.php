<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Roles
        $rolesData = [
            ['name' => 'Super Admin', 'code' => 'super_admin', 'description' => 'Toàn quyền quản trị hệ thống', 'is_system' => true],
            ['name' => 'Admin', 'code' => 'admin', 'description' => 'Quản trị viên hệ thống', 'is_system' => true],
            ['name' => 'Kế toán trưởng', 'code' => 'accounting', 'description' => 'Quản lý toàn bộ sổ sách, hạch toán, báo cáo tài chính', 'is_system' => false],
            ['name' => 'Thủ kho', 'code' => 'warehouse', 'description' => 'Quản lý xuất nhập tồn kho hàng hóa', 'is_system' => false],
            ['name' => 'Kinh doanh', 'code' => 'sales', 'description' => 'Quản lý báo giá, đơn hàng, khách hàng', 'is_system' => false],
            ['name' => 'Nhân sự', 'code' => 'hr', 'description' => 'Quản lý thông tin CBCNV, chấm công', 'is_system' => false],
            ['name' => 'Quản lý dự án', 'code' => 'project', 'description' => 'Quản lý dự án thi công và chi phí dự án', 'is_system' => false],
            ['name' => 'Chỉ xem', 'code' => 'read_only', 'description' => 'Chỉ xem dữ liệu, không được thao tác thêm sửa xóa', 'is_system' => false],
        ];

        foreach ($rolesData as $r) {
            Role::updateOrCreate(['code' => $r['code']], $r);
        }

        // 2. Seed Permissions (Both New Structured ones and Old Spatie compatibility ones)
        $permissionsData = [
            // --- NEW STRUCTURED PERMISSIONS ---
            // Dashboard
            ['module' => 'dashboard', 'menu_key' => 'dashboard', 'action' => 'view', 'code' => 'dashboard.view', 'name' => 'Xem Dashboard', 'description' => 'Xem trang Dashboard tổng quan'],

            // Bán hàng (sales.orders)
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'view', 'code' => 'sales.orders.view', 'name' => 'Xem Đơn bán hàng', 'description' => 'Xem danh sách và chi tiết đơn hàng bán'],
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'create', 'code' => 'sales.orders.create', 'name' => 'Thêm Đơn bán hàng', 'description' => 'Tạo mới đơn hàng bán'],
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'update', 'code' => 'sales.orders.update', 'name' => 'Sửa Đơn bán hàng', 'description' => 'Chỉnh sửa thông tin đơn hàng bán'],
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'delete', 'code' => 'sales.orders.delete', 'name' => 'Xóa Đơn bán hàng', 'description' => 'Xóa đơn hàng bán nháp'],
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'approve', 'code' => 'sales.orders.approve', 'name' => 'Duyệt Đơn bán hàng', 'description' => 'Phê duyệt/xác nhận đơn hàng bán'],
            ['module' => 'sales', 'menu_key' => 'sales.orders', 'action' => 'export', 'code' => 'sales.orders.export', 'name' => 'Xuất Đơn bán hàng', 'description' => 'Xuất excel đơn hàng bán'],

            // Mua hàng (purchases.orders)
            ['module' => 'purchases', 'menu_key' => 'purchases.orders', 'action' => 'view', 'code' => 'purchases.orders.view', 'name' => 'Xem Đơn mua hàng', 'description' => 'Xem danh sách và chi tiết đơn mua hàng'],
            ['module' => 'purchases', 'menu_key' => 'purchases.orders', 'action' => 'create', 'code' => 'purchases.orders.create', 'name' => 'Thêm Đơn mua hàng', 'description' => 'Tạo mới đơn mua hàng'],
            ['module' => 'purchases', 'menu_key' => 'purchases.orders', 'action' => 'update', 'code' => 'purchases.orders.update', 'name' => 'Sửa Đơn mua hàng', 'description' => 'Chỉnh sửa thông tin đơn mua hàng'],
            ['module' => 'purchases', 'menu_key' => 'purchases.orders', 'action' => 'delete', 'code' => 'purchases.orders.delete', 'name' => 'Xóa Đơn mua hàng', 'description' => 'Xóa đơn mua hàng nháp'],
            ['module' => 'purchases', 'menu_key' => 'purchases.orders', 'action' => 'approve', 'code' => 'purchases.orders.approve', 'name' => 'Duyệt Đơn mua hàng', 'description' => 'Phê duyệt đơn mua hàng'],

            // Hóa đơn đầu vào (purchases.invoices)
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'view', 'code' => 'purchases.invoices.view', 'name' => 'Xem Hóa đơn mua', 'description' => 'Xem danh sách và chi tiết hóa đơn mua'],
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'create', 'code' => 'purchases.invoices.create', 'name' => 'Thêm Hóa đơn mua', 'description' => 'Tạo mới hóa đơn mua'],
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'update', 'code' => 'purchases.invoices.update', 'name' => 'Sửa Hóa đơn mua', 'description' => 'Chỉnh sửa hóa đơn mua'],
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'delete', 'code' => 'purchases.invoices.delete', 'name' => 'Xóa Hóa đơn mua', 'description' => 'Xóa hóa đơn mua nháp'],
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'post', 'code' => 'purchases.invoices.post', 'name' => 'Ghi sổ Hóa đơn mua', 'description' => 'Hạch toán ghi sổ hóa đơn mua'],
            ['module' => 'purchases', 'menu_key' => 'purchases.invoices', 'action' => 'cancel', 'code' => 'purchases.invoices.cancel', 'name' => 'Hủy Hóa đơn mua', 'description' => 'Hủy bỏ hóa đơn mua'],

            // Nhập kho (warehouse.stock_entries)
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'view', 'code' => 'warehouse.stock_entries.view', 'name' => 'Xem Phiếu nhập kho', 'description' => 'Xem danh sách và chi tiết phiếu nhập'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'create', 'code' => 'warehouse.stock_entries.create', 'name' => 'Thêm Phiếu nhập kho', 'description' => 'Tạo mới phiếu nhập kho'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'update', 'code' => 'warehouse.stock_entries.update', 'name' => 'Sửa Phiếu nhập kho', 'description' => 'Chỉnh sửa phiếu nhập kho'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'delete', 'code' => 'warehouse.stock_entries.delete', 'name' => 'Xóa Phiếu nhập kho', 'description' => 'Xóa phiếu nhập kho nháp'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'confirm', 'code' => 'warehouse.stock_entries.confirm', 'name' => 'Xác nhận Nhập kho', 'description' => 'Xác nhận hoàn thành nhập kho'],

            // Xuất kho (warehouse.stock_exits)
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_exits', 'action' => 'view', 'code' => 'warehouse.stock_exits.view', 'name' => 'Xem Phiếu xuất kho', 'description' => 'Xem danh sách và chi tiết phiếu xuất'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_exits', 'action' => 'create', 'code' => 'warehouse.stock_exits.create', 'name' => 'Thêm Phiếu xuất kho', 'description' => 'Tạo mới phiếu xuất kho'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_exits', 'action' => 'update', 'code' => 'warehouse.stock_exits.update', 'name' => 'Sửa Phiếu xuất kho', 'description' => 'Chỉnh sửa phiếu xuất kho'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_exits', 'action' => 'delete', 'code' => 'warehouse.stock_exits.delete', 'name' => 'Xóa Phiếu xuất kho', 'description' => 'Xóa phiếu xuất kho nháp'],
            ['module' => 'warehouse', 'menu_key' => 'warehouse.stock_exits', 'action' => 'confirm', 'code' => 'warehouse.stock_exits.confirm', 'name' => 'Xác nhận Xuất kho', 'description' => 'Xác nhận hoàn thành xuất kho'],

            // Dự án
            ['module' => 'projects', 'menu_key' => 'projects', 'action' => 'view', 'code' => 'projects.view', 'name' => 'Xem Dự án', 'description' => 'Xem danh sách và tiến độ dự án'],
            ['module' => 'projects', 'menu_key' => 'projects', 'action' => 'create', 'code' => 'projects.create', 'name' => 'Thêm Dự án', 'description' => 'Tạo mới dự án thi công'],
            ['module' => 'projects', 'menu_key' => 'projects', 'action' => 'update', 'code' => 'projects.update', 'name' => 'Sửa Dự án', 'description' => 'Chỉnh sửa thông tin dự án'],
            ['module' => 'projects', 'menu_key' => 'projects', 'action' => 'delete', 'code' => 'projects.delete', 'name' => 'Xóa Dự án', 'description' => 'Xóa dự án khỏi hệ thống'],
            ['module' => 'projects', 'menu_key' => 'projects', 'action' => 'complete', 'code' => 'projects.complete', 'name' => 'Hoàn thành Dự án', 'description' => 'Nghiệm thu quyết toán hoàn thành dự án'],

            // Chi phí dự án
            ['module' => 'projects', 'menu_key' => 'projects.costs', 'action' => 'view', 'code' => 'projects.costs.view', 'name' => 'Xem Chi phí dự án', 'description' => 'Xem báo cáo chi tiết chi phí dự án'],
            ['module' => 'projects', 'menu_key' => 'projects.costs', 'action' => 'create', 'code' => 'projects.costs.create', 'name' => 'Thêm Chi phí dự án', 'description' => 'Ghi nhận chi phí nguyên vật liệu, nhân công dự án'],
            ['module' => 'projects', 'menu_key' => 'projects.costs', 'action' => 'update', 'code' => 'projects.costs.update', 'name' => 'Sửa Chi phí dự án', 'description' => 'Chỉnh sửa chi phí dự án đã ghi nhận'],
            ['module' => 'projects', 'menu_key' => 'projects.costs', 'action' => 'delete', 'code' => 'projects.costs.delete', 'name' => 'Xóa Chi phí dự án', 'description' => 'Xóa chi phí dự án'],

            // Kế toán (accounting.journals)
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'view', 'code' => 'accounting.journals.view', 'name' => 'Xem Phiếu kế toán', 'description' => 'Xem danh sách và chi tiết phiếu kế toán tổng hợp'],
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'create', 'code' => 'accounting.journals.create', 'name' => 'Thêm Phiếu kế toán', 'description' => 'Tạo mới phiếu kế toán tổng hợp'],
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'update', 'code' => 'accounting.journals.update', 'name' => 'Sửa Phiếu kế toán', 'description' => 'Chỉnh sửa thông tin phiếu kế toán nháp'],
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'delete', 'code' => 'accounting.journals.delete', 'name' => 'Xóa Phiếu kế toán', 'description' => 'Xóa phiếu kế toán nháp'],
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'post', 'code' => 'accounting.journals.post', 'name' => 'Ghi sổ Bút toán', 'description' => 'Phê duyệt ghi sổ bút toán kế toán'],
            ['module' => 'accounting', 'menu_key' => 'accounting.journals', 'action' => 'reverse', 'code' => 'accounting.journals.reverse', 'name' => 'Đảo Bút toán', 'description' => 'Tạo bút toán đảo/điều chỉnh'],

            // Nhân sự (hr.employees)
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'view', 'code' => 'hr.employees.view', 'name' => 'Xem Nhân viên', 'description' => 'Xem hồ sơ cán bộ công nhân viên'],
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'create', 'code' => 'hr.employees.create', 'name' => 'Thêm Nhân viên', 'description' => 'Tạo mới hồ sơ nhân viên'],
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'update', 'code' => 'hr.employees.update', 'name' => 'Sửa Nhân viên', 'description' => 'Chỉnh sửa hồ sơ nhân viên'],
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'delete', 'code' => 'hr.employees.delete', 'name' => 'Xóa Nhân viên', 'description' => 'Xóa hồ sơ nhân viên'],
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'export', 'code' => 'hr.employees.export', 'name' => 'Xuất dữ liệu NV', 'description' => 'Xuất danh sách nhân viên ra Excel/PDF'],
            ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => 'import', 'code' => 'hr.employees.import', 'name' => 'Import nhân viên', 'description' => 'Import danh sách nhân viên từ excel'],

            // Báo cáo tồn kho
            ['module' => 'reports', 'menu_key' => 'reports.inventory', 'action' => 'view', 'code' => 'reports.inventory.view', 'name' => 'Xem Báo cáo kho', 'description' => 'Xem báo cáo tồn kho, thẻ kho'],
            ['module' => 'reports', 'menu_key' => 'reports.inventory', 'action' => 'export', 'code' => 'reports.inventory.export', 'name' => 'Xuất Báo cáo kho', 'description' => 'Xuất excel báo cáo tồn kho'],

            // Báo cáo tài chính
            ['module' => 'reports', 'menu_key' => 'reports.financial', 'action' => 'view', 'code' => 'reports.financial.view', 'name' => 'Xem Báo cáo tài chính', 'description' => 'Xem Bảng cân đối, Kết quả kinh doanh'],
            ['module' => 'reports', 'menu_key' => 'reports.financial', 'action' => 'export', 'code' => 'reports.financial.export', 'name' => 'Xuất Báo cáo tài chính', 'description' => 'Xuất excel báo cáo tài chính'],

            // Báo cáo dòng tiền
            ['module' => 'reports', 'menu_key' => 'reports.cashflow', 'action' => 'view', 'code' => 'reports.cashflow.view', 'name' => 'Xem Báo cáo dòng tiền', 'description' => 'Xem lưu chuyển tiền tệ, thu chi thực tế'],
            ['module' => 'reports', 'menu_key' => 'reports.cashflow', 'action' => 'export', 'code' => 'reports.cashflow.export', 'name' => 'Xuất Báo cáo dòng tiền', 'description' => 'Xuất excel báo cáo dòng tiền'],


            // --- COMPATIBILITY PERMISSIONS (Old Spatie keys to prevent breaking existing code) ---
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'customers.view', 'code' => 'customers.view', 'name' => 'Compat: Xem Khách hàng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'customers.create', 'code' => 'customers.create', 'name' => 'Compat: Thêm Khách hàng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'customers.edit', 'code' => 'customers.edit', 'name' => 'Compat: Sửa Khách hàng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'customers.delete', 'code' => 'customers.delete', 'name' => 'Compat: Xóa Khách hàng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'leads.view', 'code' => 'leads.view', 'name' => 'Compat: Xem Cơ hội', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'leads.create', 'code' => 'leads.create', 'name' => 'Compat: Thêm Cơ hội', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'leads.edit', 'code' => 'leads.edit', 'name' => 'Compat: Sửa Cơ hội', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'leads.delete', 'code' => 'leads.delete', 'name' => 'Compat: Xóa Cơ hội', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'products.view', 'code' => 'products.view', 'name' => 'Compat: Xem Sản phẩm', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'products.create', 'code' => 'products.create', 'name' => 'Compat: Thêm Sản phẩm', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'products.edit', 'code' => 'products.edit', 'name' => 'Compat: Sửa Sản phẩm', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'products.delete', 'code' => 'products.delete', 'name' => 'Compat: Xóa Sản phẩm', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'services.view', 'code' => 'services.view', 'name' => 'Compat: Xem Dịch vụ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'services.create', 'code' => 'services.create', 'name' => 'Compat: Thêm Dịch vụ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'services.edit', 'code' => 'services.edit', 'name' => 'Compat: Sửa Dịch vụ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'services.delete', 'code' => 'services.delete', 'name' => 'Compat: Xóa Dịch vụ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'warehouse.view', 'code' => 'warehouse.view', 'name' => 'Compat: Xem Kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'warehouse.manage', 'code' => 'warehouse.manage', 'name' => 'Compat: Quản lý Kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'quotations.view', 'code' => 'quotations.view', 'name' => 'Compat: Xem Báo giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'quotations.create', 'code' => 'quotations.create', 'name' => 'Compat: Tạo Báo giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'quotations.edit', 'code' => 'quotations.edit', 'name' => 'Compat: Sửa Báo giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'quotations.approve', 'code' => 'quotations.approve', 'name' => 'Compat: Duyệt Báo giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'orders.view', 'code' => 'orders.view', 'name' => 'Compat: Xem Đơn hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'orders.create', 'code' => 'orders.create', 'name' => 'Compat: Tạo Đơn hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'orders.manage', 'code' => 'orders.manage', 'name' => 'Compat: Quản lý Đơn hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'project.wip.adjust', 'code' => 'project.wip.adjust', 'name' => 'Compat: Điều chỉnh WIP dự án', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'tickets.view', 'code' => 'tickets.view', 'name' => 'Compat: Xem Ticket', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'tickets.create', 'code' => 'tickets.create', 'name' => 'Compat: Tạo Ticket', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'tickets.assign', 'code' => 'tickets.assign', 'name' => 'Compat: Phân công Ticket', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'tickets.close', 'code' => 'tickets.close', 'name' => 'Compat: Đóng Ticket', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchasing.view', 'code' => 'purchasing.view', 'name' => 'Compat: Xem Mua hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchasing.create', 'code' => 'purchasing.create', 'name' => 'Compat: Tạo Mua hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchasing.approve', 'code' => 'purchasing.approve', 'name' => 'Compat: Duyệt Mua hàng cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'accounting.view', 'code' => 'accounting.view', 'name' => 'Compat: Xem Kế toán cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'accounting.manage', 'code' => 'accounting.manage', 'name' => 'Compat: Quản trị Kế toán cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'reports.view', 'code' => 'reports.view', 'name' => 'Compat: Xem Báo cáo cũ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'documents.view', 'code' => 'documents.view', 'name' => 'Compat: Xem Chứng từ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'documents.create', 'code' => 'documents.create', 'name' => 'Compat: Tạo Chứng từ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'documents.manage', 'code' => 'documents.manage', 'name' => 'Compat: Quản lý Chứng từ', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'commissions.view', 'code' => 'commissions.view', 'name' => 'Compat: Xem Hoa hồng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'commissions.create', 'code' => 'commissions.create', 'name' => 'Compat: Tạo Hoa hồng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'commissions.approve_l1', 'code' => 'commissions.approve_l1', 'name' => 'Compat: Duyệt hoa hồng cấp 1', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'commissions.approve', 'code' => 'commissions.approve', 'name' => 'Compat: Duyệt hoa hồng cấp 2', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'commissions.pay', 'code' => 'commissions.pay', 'name' => 'Compat: Chi trả hoa hồng', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'stock-transfers.view', 'code' => 'stock-transfers.view', 'name' => 'Compat: Xem chuyển kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'stock-transfers.create', 'code' => 'stock-transfers.create', 'name' => 'Compat: Tạo chuyển kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'stock-transfers.edit', 'code' => 'stock-transfers.edit', 'name' => 'Compat: Sửa chuyển kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'stock-transfers.delete', 'code' => 'stock-transfers.delete', 'name' => 'Compat: Xóa chuyển kho', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'sales-returns.view', 'code' => 'sales-returns.view', 'name' => 'Compat: Xem trả hàng bán', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'sales-returns.create', 'code' => 'sales-returns.create', 'name' => 'Compat: Tạo trả hàng bán', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'sales-returns.edit', 'code' => 'sales-returns.edit', 'name' => 'Compat: Sửa trả hàng bán', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'sales-returns.delete', 'code' => 'sales-returns.delete', 'name' => 'Compat: Xóa trả hàng bán', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchase-returns.view', 'code' => 'purchase-returns.view', 'name' => 'Compat: Xem trả hàng mua', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchase-returns.create', 'code' => 'purchase-returns.create', 'name' => 'Compat: Tạo trả hàng mua', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchase-returns.edit', 'code' => 'purchase-returns.edit', 'name' => 'Compat: Sửa trả hàng mua', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'purchase-returns.delete', 'code' => 'purchase-returns.delete', 'name' => 'Compat: Xóa trả hàng mua', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'price-lists.view', 'code' => 'price-lists.view', 'name' => 'Compat: Xem bảng giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'price-lists.create', 'code' => 'price-lists.create', 'name' => 'Compat: Tạo bảng giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'price-lists.edit', 'code' => 'price-lists.edit', 'name' => 'Compat: Sửa bảng giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'price-lists.delete', 'code' => 'price-lists.delete', 'name' => 'Compat: Xóa bảng giá', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.view', 'code' => 'ccdc.view', 'name' => 'Compat: Xem CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.manage', 'code' => 'ccdc.manage', 'name' => 'Compat: Quản lý CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.allocate', 'code' => 'ccdc.allocate', 'name' => 'Compat: Phân bổ CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.dispose', 'code' => 'ccdc.dispose', 'name' => 'Compat: Thanh lý CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.cancel', 'code' => 'ccdc.cancel', 'name' => 'Compat: Hủy CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'ccdc.delete', 'code' => 'ccdc.delete', 'name' => 'Compat: Xóa CCDC', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'system.health.view', 'code' => 'system.health.view', 'name' => 'Compat: Xem tình trạng HT', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'admin.users', 'code' => 'admin.users', 'name' => 'Compat: Quản lý Users', 'description' => 'Quyền tương thích cũ'],
            ['module' => 'old_compat', 'menu_key' => 'compatibility', 'action' => 'admin.roles', 'code' => 'admin.roles', 'name' => 'Compat: Quản lý Roles', 'description' => 'Quyền tương thích cũ'],
        ];

        foreach ($permissionsData as $p) {
            Permission::updateOrCreate(['code' => $p['code']], $p);
        }

        // 3. Map Default Role Permissions
        // Admin: all permissions
        $allPermissions = Permission::all();
        $adminRole = Role::where('code', 'admin')->first();
        $adminRole->permissions()->sync($allPermissions->pluck('id'));

        // Super Admin: all permissions
        $superAdminRole = Role::where('code', 'super_admin')->first();
        $superAdminRole->permissions()->sync($allPermissions->pluck('id'));

        // Accountant Role Permissions
        $accountingCodes = [
            'dashboard.view', 'accounting.journals.view', 'accounting.journals.create',
            'accounting.journals.update', 'accounting.journals.delete', 'accounting.journals.post', 'accounting.journals.reverse',
            'purchases.invoices.view', 'purchases.invoices.create', 'purchases.invoices.update',
            'purchases.invoices.post', 'purchases.invoices.cancel',
            'sales.orders.view', 'purchases.orders.view', 'warehouse.stock_entries.view',
            'warehouse.stock_exits.view', 'reports.financial.view', 'reports.financial.export',
            'reports.cashflow.view', 'reports.cashflow.export',
            // Spatie legacy mappings:
            'customers.view', 'products.view', 'warehouse.view', 'quotations.view', 'orders.view',
            'projects.view', 'purchasing.view', 'purchase-returns.view', 'accounting.view', 'accounting.manage',
            'commissions.view', 'commissions.pay', 'documents.view', 'documents.create', 'documents.manage',
            'reports.view', 'ccdc.view', 'ccdc.manage', 'ccdc.allocate', 'ccdc.dispose', 'ccdc.cancel', 'ccdc.delete',
            'project.wip.adjust'
        ];
        $accountingRole = Role::where('code', 'accounting')->first();
        $accountingRole->permissions()->sync(Permission::whereIn('code', $accountingCodes)->pluck('id'));

        // Warehouse Role Permissions
        $warehouseCodes = [
            'dashboard.view', 'warehouse.stock_entries.view', 'warehouse.stock_entries.create',
            'warehouse.stock_entries.update', 'warehouse.stock_entries.delete', 'warehouse.stock_entries.confirm',
            'warehouse.stock_exits.view', 'warehouse.stock_exits.create', 'warehouse.stock_exits.update',
            'warehouse.stock_exits.delete', 'warehouse.stock_exits.confirm',
            'reports.inventory.view', 'reports.inventory.export',
            // Legacy mappings:
            'products.view', 'products.create', 'products.edit', 'services.view', 'warehouse.view',
            'warehouse.manage', 'purchasing.view', 'stock-transfers.view', 'stock-transfers.create',
            'stock-transfers.edit', 'stock-transfers.delete', 'sales-returns.view', 'sales-returns.create',
            'sales-returns.edit', 'sales-returns.delete', 'purchase-returns.view', 'purchase-returns.create',
            'purchase-returns.edit', 'purchase-returns.delete', 'documents.view', 'documents.create', 'reports.view'
        ];
        $warehouseRole = Role::where('code', 'warehouse')->first();
        $warehouseRole->permissions()->sync(Permission::whereIn('code', $warehouseCodes)->pluck('id'));

        // Sales Role Permissions
        $salesCodes = [
            'dashboard.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.delete', 'sales.orders.export',
            // Legacy mappings:
            'customers.view', 'customers.create', 'customers.edit', 'leads.view', 'leads.create',
            'leads.edit', 'leads.delete', 'products.view', 'services.view', 'price-lists.view',
            'price-lists.create', 'price-lists.edit', 'price-lists.delete', 'quotations.view',
            'quotations.create', 'quotations.edit', 'orders.view', 'orders.create', 'sales-returns.view',
            'sales-returns.create', 'sales-returns.edit', 'sales-returns.delete', 'accounting.view',
            'commissions.view', 'commissions.create', 'documents.view', 'documents.create', 'reports.view'
        ];
        $salesRole = Role::where('code', 'sales')->first();
        $salesRole->permissions()->sync(Permission::whereIn('code', $salesCodes)->pluck('id'));

        // Project Role Permissions
        $projectCodes = [
            'dashboard.view', 'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.complete',
            'projects.costs.view', 'projects.costs.create', 'projects.costs.update', 'projects.costs.delete',
            // Legacy:
            'projects.view', 'projects.create', 'projects.manage', 'projects.delete', 'reports.view'
        ];
        $projectRole = Role::where('code', 'project')->first();
        $projectRole->permissions()->sync(Permission::whereIn('code', $projectCodes)->pluck('id'));

        // HR Role Permissions
        $hrCodes = [
            'dashboard.view', 'hr.employees.view', 'hr.employees.create', 'hr.employees.update',
            'hr.employees.delete', 'hr.employees.export', 'hr.employees.import',
            // Legacy:
            'reports.view', 'admin.users'
        ];
        $hrRole = Role::where('code', 'hr')->first();
        $hrRole->permissions()->sync(Permission::whereIn('code', $hrCodes)->pluck('id'));

        // Read Only Role Permissions
        $readOnlyCodes = [
            'dashboard.view', 'sales.orders.view', 'purchases.orders.view', 'purchases.invoices.view',
            'warehouse.stock_entries.view', 'warehouse.stock_exits.view', 'projects.view',
            'accounting.journals.view', 'hr.employees.view', 'reports.inventory.view',
            'reports.financial.view', 'reports.cashflow.view'
        ];
        $readOnlyRole = Role::where('code', 'read_only')->first();
        $readOnlyRole->permissions()->sync(Permission::whereIn('code', $readOnlyCodes)->pluck('id'));


        // 4. Assign role Super Admin to the existing Admin users (ID: 1, 9, 10)
        $adminUserIds = [1, 9, 10];
        foreach ($adminUserIds as $uid) {
            $user = User::find($uid);
            if ($user) {
                // Attach both super_admin and admin roles
                $user->roles()->syncWithoutDetaching([$superAdminRole->id, $adminRole->id]);
            }
        }

        $adminEmails = ['admin@adcare.vn', 'sonlv@adcare.vn'];
        foreach ($adminEmails as $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->roles()->syncWithoutDetaching([$superAdminRole->id, $adminRole->id]);
            }
        }

        // Assign Sales role to ID 8
        $salesUser = User::find(8);
        if ($salesUser) {
            $salesUser->roles()->sync([$salesRole->id]);
        }

        // Assign Director role to ID 11 (we will create director role code read_only or admin, or let's create a director role!)
        $directorRole = Role::updateOrCreate(
            ['code' => 'director'],
            ['name' => 'Giám đốc', 'code' => 'director', 'description' => 'Ban Giám đốc', 'is_system' => false]
        );
        // Director gets all view + approve permissions
        $directorCodes = Permission::where(function($q) {
            $q->where('action', 'view')
              ->orWhere('action', 'approve')
              ->orWhere('action', 'confirm')
              ->orWhere('action', 'post')
              ->orWhere('action', 'export')
              ->orWhere('action', 'like', '%.view%')
              ->orWhere('action', 'like', '%.approve%')
              ->orWhere('action', 'like', '%.manage%');
        })->pluck('id');
        $directorRole->permissions()->sync($directorCodes);

        $directorUser = User::find(11);
        if ($directorUser) {
            $directorUser->roles()->sync([$directorRole->id]);
        }


        // 5. Seed Menu Items (Dynamic Sidebar Menu)
        MenuItem::truncate();

        // Level 1 Parents (Groups)
        $catalogGroup = MenuItem::create(['key' => 'catalog', 'label' => 'Danh mục', 'icon' => 'collection', 'required_permission' => 'products.view', 'order' => 3]);
        $salesGroup = MenuItem::create(['key' => 'sales', 'label' => 'Bán hàng', 'icon' => 'shopping-bag', 'required_permission' => 'quotations.view', 'order' => 4]);
        $purchasingGroup = MenuItem::create(['key' => 'purchasing', 'label' => 'Mua hàng', 'icon' => 'truck', 'required_permission' => 'purchasing.view', 'order' => 5]);
        $projectsGroup = MenuItem::create(['key' => 'projects', 'label' => 'Dự án thi công', 'icon' => 'briefcase', 'required_permission' => 'projects.view', 'order' => 6]);
        $supportGroup = MenuItem::create(['key' => 'support', 'label' => 'Hỗ trợ kỹ thuật', 'icon' => 'support', 'required_permission' => 'tickets.view', 'order' => 7]);
        $warehouseGroup = MenuItem::create(['key' => 'warehouse', 'label' => 'Kho', 'icon' => 'archive', 'required_permission' => 'warehouse.view', 'order' => 8]);

        // Accounting Sub-groups
        $fundSub = MenuItem::create(['key' => 'accounting.fund', 'label' => 'Quỹ', 'icon' => 'library', 'required_permission' => 'accounting.view', 'order' => 91]);
        $arSub = MenuItem::create(['key' => 'accounting.ar', 'label' => 'Công nợ phải thu (AR)', 'icon' => 'inbox-in', 'required_permission' => 'accounting.view', 'order' => 92]);
        $apSub = MenuItem::create(['key' => 'accounting.ap', 'label' => 'Công nợ phải trả (AP)', 'icon' => 'arrows-expand', 'required_permission' => 'accounting.view', 'order' => 93]);
        $payrollSub = MenuItem::create(['key' => 'accounting.payroll', 'label' => 'Tiền lương', 'icon' => 'clipboard-list', 'required_permission' => 'accounting.view', 'order' => 94]);
        $taxSub = MenuItem::create(['key' => 'accounting.tax', 'label' => 'Thuế', 'icon' => 'receipt-tax', 'required_permission' => 'accounting.view', 'order' => 95]);
        $prepaidSub = MenuItem::create(['key' => 'accounting.prepaid', 'label' => 'Chi phí & Giá vốn', 'icon' => 'currency-dollar', 'required_permission' => 'accounting.view', 'order' => 96]);
        $fixedAssetSub = MenuItem::create(['key' => 'accounting.fixed_asset', 'label' => 'Tài sản cố định', 'icon' => 'cube', 'required_permission' => 'accounting.view', 'order' => 97]);
        $smallToolsSub = MenuItem::create(['key' => 'accounting.small_tools', 'label' => 'Công cụ dụng cụ', 'icon' => 'wrench', 'required_permission' => 'ccdc.view', 'order' => 98]);
        $journalSub = MenuItem::create(['key' => 'accounting.journal', 'label' => 'Kế toán tổng hợp', 'icon' => 'pencil-alt', 'required_permission' => 'accounting.view', 'order' => 99]);
        $reportsSub = MenuItem::create(['key' => 'accounting.reports', 'label' => 'Báo cáo', 'icon' => 'chart-bar', 'required_permission' => 'accounting.view', 'order' => 100]);

        // Admin Group
        $adminGroup = MenuItem::create(['key' => 'admin_group', 'label' => 'Quản trị', 'icon' => 'cog', 'required_permission' => 'admin.users', 'order' => 200]);

        // Submenus (Children)
        
        // Catalog Children
        MenuItem::create(['parent_id' => $catalogGroup->id, 'key' => 'catalog.products', 'label' => 'Sản phẩm', 'route_name' => 'catalog.products.index', 'icon' => 'cube', 'required_permission' => 'products.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $catalogGroup->id, 'key' => 'catalog.services', 'label' => 'Dịch vụ', 'route_name' => 'catalog.services.index', 'icon' => 'tag', 'required_permission' => 'services.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $catalogGroup->id, 'key' => 'catalog.customers', 'label' => 'Khách hàng', 'route_name' => 'crm.customers.index', 'icon' => 'users', 'required_permission' => 'customers.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $catalogGroup->id, 'key' => 'catalog.suppliers', 'label' => 'Nhà cung cấp', 'route_name' => 'warehouse.suppliers.index', 'icon' => 'office-building', 'required_permission' => 'purchasing.view', 'order' => 4]);

        // Sales Children
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.quotations', 'label' => 'Báo giá', 'route_name' => 'sales.quotations.index', 'icon' => 'document-text', 'required_permission' => 'quotations.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.orders', 'label' => 'Đơn hàng bán', 'route_name' => 'sales.orders.index', 'icon' => 'shopping-bag', 'required_permission' => 'sales.orders.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.contracts', 'label' => 'Hợp đồng bán', 'route_name' => 'sales.contracts.index', 'icon' => 'document', 'required_permission' => 'quotations.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.invoices', 'label' => 'Hóa đơn bán hàng', 'route_name' => 'accounting.invoices.index', 'icon' => 'document-text', 'required_permission' => 'accounting.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.commissions', 'label' => 'Hoa hồng', 'route_name' => 'sales.commissions.index', 'icon' => 'currency-dollar', 'required_permission' => 'commissions.view', 'order' => 5]);
        MenuItem::create(['parent_id' => $salesGroup->id, 'key' => 'sales.advances', 'label' => 'Ứng trước khách hàng', 'route_name' => 'sales.customer-advances.index', 'icon' => 'cash', 'required_permission' => 'accounting.view', 'order' => 6]);

        // Purchasing Children
        MenuItem::create(['parent_id' => $purchasingGroup->id, 'key' => 'purchasing.orders', 'label' => 'Đơn mua hàng', 'route_name' => 'purchasing.purchase-orders.index', 'icon' => 'document-text', 'required_permission' => 'purchasing.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $purchasingGroup->id, 'key' => 'purchasing.contracts', 'label' => 'Hợp đồng mua', 'route_name' => 'purchasing.purchase-contracts.index', 'icon' => 'document', 'required_permission' => 'purchasing.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $purchasingGroup->id, 'key' => 'purchasing.invoices', 'label' => 'Hóa đơn đầu vào', 'route_name' => 'purchasing.purchase-invoices.index', 'icon' => 'receipt-tax', 'required_permission' => 'purchases.invoices.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $purchasingGroup->id, 'key' => 'purchasing.advances', 'label' => 'Tiền trả trước NCC', 'route_name' => 'purchasing.supplier-advances.index', 'icon' => 'cash', 'required_permission' => 'purchasing.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $purchasingGroup->id, 'key' => 'purchasing.returns', 'label' => 'Trả hàng mua', 'route_name' => 'purchasing.purchase-returns.index', 'icon' => 'reply', 'required_permission' => 'purchase-returns.view', 'order' => 5]);

        // Projects Children
        MenuItem::create(['parent_id' => $projectsGroup->id, 'key' => 'projects.projects', 'label' => 'Dự án', 'route_name' => 'projects.projects.index', 'icon' => 'collection', 'required_permission' => 'projects.view', 'order' => 1]);

        // Support Children
        MenuItem::create(['parent_id' => $supportGroup->id, 'key' => 'support.tickets', 'label' => 'Ticket kỹ thuật', 'route_name' => 'support.tickets.index', 'icon' => 'ticket', 'required_permission' => 'tickets.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $supportGroup->id, 'key' => 'support.warranties', 'label' => 'Bảo hành', 'route_name' => 'support.warranties.index', 'icon' => 'shield-check', 'required_permission' => 'tickets.view', 'order' => 2]);

        // Warehouse Children
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.warehouses', 'label' => 'Kho hàng', 'route_name' => 'warehouse.warehouses.index', 'icon' => 'office-building', 'required_permission' => 'warehouse.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.entries', 'label' => 'Nhập kho', 'route_name' => 'warehouse.stock-entries.index', 'icon' => 'inbox', 'required_permission' => 'warehouse.stock_entries.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.exits', 'label' => 'Xuất kho', 'route_name' => 'warehouse.stock-exits.index', 'icon' => 'arrow-circle-right', 'required_permission' => 'warehouse.stock_exits.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.report.entry', 'label' => 'Báo cáo nhập kho', 'route_name' => 'reports.stock_entry_details', 'icon' => 'document-text', 'required_permission' => 'warehouse.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.report.exit', 'label' => 'Báo cáo xuất kho', 'route_name' => 'reports.stock_exit_details', 'icon' => 'document-text', 'required_permission' => 'warehouse.view', 'order' => 5]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.report.inventory', 'label' => 'Tồn kho', 'route_name' => 'reports.inventory', 'icon' => 'cube', 'required_permission' => 'warehouse.view', 'order' => 6]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.counts', 'label' => 'Kiểm kê kho', 'route_name' => 'warehouse.inventory-counts.index', 'icon' => 'clipboard-list', 'required_permission' => 'warehouse.view', 'order' => 7]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.project_inventory', 'label' => 'Tồn kho dự án', 'route_name' => 'warehouse.project-inventory.index', 'icon' => 'collection', 'required_permission' => 'warehouse.view', 'order' => 8]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.opening', 'label' => 'Tồn đầu kỳ', 'route_name' => 'warehouse.opening-balance.index', 'icon' => 'database', 'required_permission' => 'warehouse.manage', 'order' => 9]);
        MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.card', 'label' => 'Thẻ kho', 'route_name' => 'reports.stock_card', 'icon' => 'table', 'required_permission' => 'warehouse.view', 'order' => 10]);

        // Fund Children
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.funds', 'label' => 'Quản lý quỹ', 'route_name' => 'accounting.funds.index', 'icon' => 'library', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.vouchers', 'label' => 'Phiếu thu / chi', 'route_name' => 'accounting.cash-vouchers.index', 'icon' => 'cash', 'required_permission' => 'accounting.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.transfers', 'label' => 'Luân chuyển quỹ', 'route_name' => 'accounting.fund-transfers.index', 'icon' => 'arrows-expand', 'required_permission' => 'accounting.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.banks', 'label' => 'Tài khoản ngân hàng', 'route_name' => 'accounting.bank-accounts.index', 'icon' => 'library', 'required_permission' => 'accounting.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.terms', 'label' => 'Điều khoản TT', 'route_name' => 'accounting.payment-terms.index', 'icon' => 'tag', 'required_permission' => 'accounting.manage', 'order' => 5]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.internal_banks', 'label' => 'TK nội bộ', 'route_name' => 'accounting.internal-bank-accounts.index', 'icon' => 'office-building', 'required_permission' => 'accounting.manage', 'order' => 6]);
        MenuItem::create(['parent_id' => $fundSub->id, 'key' => 'accounting.fund.internal_transfers', 'label' => 'CK nội bộ', 'route_name' => 'accounting.internal-transfers.index', 'icon' => 'arrows-expand', 'required_permission' => 'accounting.view', 'order' => 7]);

        // AR Children
        MenuItem::create(['parent_id' => $arSub->id, 'key' => 'accounting.ar.collections', 'label' => 'Thu nợ KH (TK 131)', 'route_name' => 'accounting.ar-collections.index', 'icon' => 'inbox-in', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $arSub->id, 'key' => 'accounting.ar.opening', 'label' => 'Công nợ đầu kỳ', 'route_name' => 'accounting.ar-ap-opening-balance.index', 'icon' => 'clock', 'required_permission' => 'accounting.manage', 'order' => 2]);
        MenuItem::create(['parent_id' => $arSub->id, 'key' => 'accounting.ar.aging', 'label' => 'Công nợ phải thu (AR)', 'route_name' => 'reports.ar.aging', 'icon' => 'chart-bar', 'required_permission' => 'accounting.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $arSub->id, 'key' => 'accounting.ar.detail', 'label' => 'Sổ chi tiết CN phải thu', 'route_name' => 'reports.ar.detail', 'icon' => 'document-text', 'required_permission' => 'accounting.view', 'order' => 4]);

        // AP Children
        MenuItem::create(['parent_id' => $apSub->id, 'key' => 'accounting.ap.payments', 'label' => 'Trả NCC (TK 331)', 'route_name' => 'accounting.ap-payments.index', 'icon' => 'currency-dollar', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $apSub->id, 'key' => 'accounting.ap.aging', 'label' => 'Công nợ phải trả (AP)', 'route_name' => 'reports.ap.aging', 'icon' => 'chart-bar', 'required_permission' => 'accounting.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $apSub->id, 'key' => 'accounting.ap.detail', 'label' => 'Sổ chi tiết CN phải trả', 'route_name' => 'reports.ap.detail', 'icon' => 'document-text', 'required_permission' => 'accounting.view', 'order' => 3]);

        // Payroll Children
        MenuItem::create(['parent_id' => $payrollSub->id, 'key' => 'accounting.payroll.attendance', 'label' => 'Bảng chấm công', 'route_name' => 'admin.attendance.index', 'icon' => 'calendar', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $payrollSub->id, 'key' => 'accounting.payroll.payrolls', 'label' => 'Bảng lương', 'route_name' => 'accounting.payrolls.index', 'icon' => 'clipboard-list', 'required_permission' => 'accounting.view', 'order' => 2]);

        // Tax Children
        MenuItem::create(['parent_id' => $taxSub->id, 'key' => 'accounting.tax.declaration', 'label' => 'Kê khai thuế', 'route_name' => 'accounting.taxes.index', 'icon' => 'receipt-tax', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $taxSub->id, 'key' => 'accounting.tax.vat', 'label' => 'Báo cáo VAT', 'route_name' => 'reports.vat', 'icon' => 'receipt-tax', 'required_permission' => 'accounting.view', 'order' => 2]);

        // Prepaid Children
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.expenses', 'label' => 'Chi phí trả trước', 'route_name' => 'accounting.prepaid-expenses.index', 'icon' => 'clock', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.opening', 'label' => 'Số dư đầu kỳ CPTT', 'route_name' => 'accounting.prepaid-expenses.opening-balance.create', 'icon' => 'database', 'required_permission' => 'accounting.manage', 'order' => 2]);
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.gl_reconcile', 'label' => 'Đối soát GL (CPTT)', 'route_name' => 'accounting.prepaid-expenses.reports.gl-reconcile', 'icon' => 'check-circle', 'required_permission' => 'accounting.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.detail', 'label' => 'Chi tiết chi phí', 'route_name' => 'reports.expense_detail', 'icon' => 'currency-dollar', 'required_permission' => 'accounting.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.profit_orders', 'label' => 'Lợi nhuận đơn hàng', 'route_name' => 'reports.profit.orders', 'icon' => 'trending-up', 'required_permission' => 'accounting.view', 'order' => 5]);
        MenuItem::create(['parent_id' => $prepaidSub->id, 'key' => 'accounting.prepaid.profit_projects', 'label' => 'Lợi nhuận dự án', 'route_name' => 'reports.profit.projects', 'icon' => 'trending-up', 'required_permission' => 'accounting.view', 'order' => 6]);

        // Fixed Assets Children
        MenuItem::create(['parent_id' => $fixedAssetSub->id, 'key' => 'accounting.fixed_asset.list', 'label' => 'Tài sản cố định', 'route_name' => 'accounting.fixed-assets.index', 'icon' => 'cube', 'required_permission' => 'accounting.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $fixedAssetSub->id, 'key' => 'accounting.fixed_asset.depreciation', 'label' => 'Tính khấu hao', 'route_name' => 'accounting.fixed-assets.depreciation.run-page', 'icon' => 'refresh', 'required_permission' => 'accounting.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $fixedAssetSub->id, 'key' => 'accounting.fixed_asset.ledger', 'label' => 'Sổ TSCĐ', 'route_name' => 'accounting.fixed-assets.reports.ledger', 'icon' => 'document-text', 'required_permission' => 'accounting.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $fixedAssetSub->id, 'key' => 'accounting.fixed_asset.reconciliation', 'label' => 'Báo cáo TSCĐ', 'route_name' => 'accounting.fixed-assets.reports.reconciliation', 'icon' => 'check-circle', 'required_permission' => 'accounting.view', 'order' => 4]);

        // Small Tools Children
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.list', 'label' => 'Danh sách CCDC', 'route_name' => 'accounting.small-tools.index', 'icon' => 'view-list', 'required_permission' => 'ccdc.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.opening', 'label' => 'Số dư đầu kỳ CCDC', 'route_name' => 'accounting.small-tools.opening-balance.create', 'icon' => 'database', 'required_permission' => 'ccdc.manage', 'order' => 2]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.receipts', 'label' => 'Phiếu nhập CCDC', 'route_name' => 'accounting.small-tools.receipts.index', 'icon' => 'inbox', 'required_permission' => 'ccdc.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.issues', 'label' => 'Phiếu xuất dùng', 'route_name' => 'accounting.small-tools.issues.index', 'icon' => 'share', 'required_permission' => 'ccdc.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.allocations', 'label' => 'Phân bổ hàng tháng', 'route_name' => 'accounting.small-tools.allocations.index', 'icon' => 'refresh', 'required_permission' => 'ccdc.view', 'order' => 5]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.ledger', 'label' => 'Sổ theo dõi CCDC', 'route_name' => 'accounting.small-tools.reports.ledger', 'icon' => 'document-text', 'required_permission' => 'ccdc.view', 'order' => 6]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.schedule', 'label' => 'Bảng phân bổ', 'route_name' => 'accounting.small-tools.reports.allocation-schedule', 'icon' => 'calendar', 'required_permission' => 'ccdc.view', 'order' => 7]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.gl_reconcile', 'label' => 'Đối soát GL', 'route_name' => 'accounting.small-tools.reports.gl-reconcile', 'icon' => 'check-circle', 'required_permission' => 'ccdc.view', 'order' => 8]);
        MenuItem::create(['parent_id' => $smallToolsSub->id, 'key' => 'accounting.small_tools.categories', 'label' => 'Danh mục CCDC', 'route_name' => 'accounting.small-tools.categories.index', 'icon' => 'tag', 'required_permission' => 'ccdc.manage', 'order' => 9]);

        // Journal Children
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.opening', 'label' => 'Số dư đầu kỳ (TK)', 'route_name' => 'accounting.opening-balance.index', 'icon' => 'database', 'required_permission' => 'accounting.manage', 'order' => 1]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.journals', 'label' => 'Phiếu kế toán', 'route_name' => 'accounting.journal-entries.index', 'icon' => 'pencil-alt', 'required_permission' => 'accounting.journals.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.codes', 'label' => 'Hệ thống tài khoản', 'route_name' => 'accounting.account-codes.index', 'icon' => 'view-list', 'required_permission' => 'accounting.manage', 'order' => 3]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.periods', 'label' => 'Kỳ kế toán', 'route_name' => 'accounting.accounting-periods.index', 'icon' => 'calendar', 'required_permission' => 'accounting.manage', 'order' => 4]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.close', 'label' => 'Kết chuyển cuối kỳ', 'route_name' => 'accounting.period-close.index', 'icon' => 'check-circle', 'required_permission' => 'accounting.manage', 'order' => 5]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.audit', 'label' => 'Kiểm toán bút toán', 'route_name' => 'accounting.journal-audit.index', 'icon' => 'magnifying-glass', 'required_permission' => 'accounting.manage', 'order' => 6]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.general', 'label' => 'Sổ nhật ký chung', 'route_name' => 'reports.general_journal', 'icon' => 'book-open', 'required_permission' => 'accounting.view', 'order' => 7]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.general_detail', 'label' => 'Sổ nhật ký chung chi tiết', 'route_name' => 'reports.general_journal_detail', 'icon' => 'book-open', 'required_permission' => 'accounting.view', 'order' => 8]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.ledger', 'label' => 'Sổ chi tiết TK', 'route_name' => 'reports.account_ledger', 'icon' => 'document-text', 'required_permission' => 'accounting.view', 'order' => 9]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.checklist', 'label' => 'Bảng kê chứng từ', 'route_name' => 'reports.document_checklist', 'icon' => 'clipboard-check', 'required_permission' => 'accounting.view', 'order' => 10]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.checklist_detail', 'label' => 'Bảng kê chứng từ chi tiết', 'route_name' => 'reports.document_checklist_detail', 'icon' => 'clipboard-check', 'required_permission' => 'accounting.view', 'order' => 11]);
        MenuItem::create(['parent_id' => $journalSub->id, 'key' => 'accounting.journal.all_docs', 'label' => 'Tất cả chứng từ', 'route_name' => 'documents.documents.index', 'icon' => 'document-text', 'required_permission' => 'documents.view', 'order' => 12]);

        // Reports Children
        MenuItem::create(['parent_id' => $reportsSub->id, 'key' => 'accounting.reports.statement', 'label' => 'LCTT B03-DNN', 'route_name' => 'reports.cash_flow_statement', 'icon' => 'banknotes', 'required_permission' => 'reports.cashflow.view', 'order' => 1]);
        MenuItem::create(['parent_id' => $reportsSub->id, 'key' => 'accounting.reports.flow', 'label' => 'Sổ thu chi theo ngày', 'route_name' => 'reports.cash_flow', 'icon' => 'banknotes', 'required_permission' => 'reports.cashflow.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $reportsSub->id, 'key' => 'accounting.reports.income', 'label' => 'Kết quả HĐKD', 'route_name' => 'reports.income_statement', 'icon' => 'chart-bar', 'required_permission' => 'reports.financial.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $reportsSub->id, 'key' => 'accounting.reports.sheet', 'label' => 'Cân đối kế toán', 'route_name' => 'reports.balance_sheet', 'icon' => 'document', 'required_permission' => 'reports.financial.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $reportsSub->id, 'key' => 'accounting.reports.trial', 'label' => 'Cân đối phát sinh', 'route_name' => 'reports.trial_balance', 'icon' => 'document-text', 'required_permission' => 'reports.financial.view', 'order' => 5]);

        // Admin Children
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.shareholders', 'label' => 'Cổ đông / Thành viên', 'route_name' => 'admin.shareholders.index', 'icon' => 'library', 'required_permission' => 'admin.users', 'order' => 1]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.employees', 'label' => 'Cán bộ CNV', 'route_name' => 'admin.employees.index', 'icon' => 'identification', 'required_permission' => 'hr.employees.view', 'order' => 2]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.departments', 'label' => 'Quản lý bộ phận', 'route_name' => 'admin.departments.index', 'icon' => 'office-building', 'required_permission' => 'hr.employees.view', 'order' => 3]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.positions', 'label' => 'Quản lý chức vụ', 'route_name' => 'admin.positions.index', 'icon' => 'briefcase', 'required_permission' => 'hr.employees.view', 'order' => 4]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.users', 'label' => 'Người dùng', 'route_name' => 'admin.users.index', 'icon' => 'users', 'required_permission' => 'admin.users', 'order' => 5]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.roles', 'label' => 'Phân quyền', 'route_name' => 'admin.roles.index', 'icon' => 'shield-check', 'required_permission' => 'admin.roles', 'order' => 6]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.settings', 'label' => 'Cài đặt công ty', 'route_name' => 'admin.settings.index', 'icon' => 'cog', 'required_permission' => 'admin.users', 'order' => 7]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.activity_logs', 'label' => 'Nhật ký hoạt động', 'route_name' => 'admin.activity-logs.index', 'icon' => 'clipboard-list', 'required_permission' => 'admin.users', 'order' => 8]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.backups', 'label' => 'Sao lưu dữ liệu', 'route_name' => 'admin.backups.index', 'icon' => 'server', 'required_permission' => 'admin.users', 'order' => 9]);
        MenuItem::create(['parent_id' => $adminGroup->id, 'key' => 'admin.system_health', 'label' => 'Tình trạng hệ thống', 'route_name' => 'admin.system-health.index', 'icon' => 'chip', 'required_permission' => 'system.health.view', 'order' => 10]);
    }
}
