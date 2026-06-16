<template>
  <aside
    :class="[
      'bg-slate-900 text-white overflow-hidden transition-[width] duration-300 ease-in-out',
      isMobile ? 'fixed inset-y-0 left-0 z-30' : 'flex-shrink-0',
      open ? 'w-64' : 'w-0',
    ]">
    <div class="w-64 h-full flex flex-col">

      <!-- Logo -->
      <div class="h-16 flex items-center px-4 border-b border-slate-800 flex-shrink-0">
        <template v-if="companyLogo">
          <img :src="companyLogo" :alt="companyName"
            class="h-9 w-9 rounded-lg object-contain mr-3 bg-white p-0.5 flex-shrink-0" />
        </template>
        <template v-else>
          <div class="w-9 h-9 bg-primary-500 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" />
            </svg>
          </div>
        </template>
        <span class="font-bold text-sm leading-tight line-clamp-2">{{ companyName }}</span>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 py-3 overflow-y-auto space-y-0.5">
        <NavItem :href="route('dashboard')" icon="home">Dashboard</NavItem>
        <NavItem :href="route('notifications.index')" icon="bell">Thông báo</NavItem>

        <!-- Danh mục -->
        <NavGroup v-if="can('products.view')"
          label="Danh mục" icon="collection" prefix="/catalog">
          <NavItem :href="route('catalog.products.index')" icon="cube" sub>Sản phẩm</NavItem>
          <NavItem :href="route('catalog.services.index')" icon="tag" sub>Dịch vụ</NavItem>
          <NavItem v-if="can('customers.view')" :href="route('crm.customers.index')" icon="users" sub>Khách hàng</NavItem>
          <NavItem v-if="can('purchasing.view')" :href="route('warehouse.suppliers.index')" icon="office-building" sub>Nhà cung cấp</NavItem>
        </NavGroup>

        <!-- Bán hàng -->
        <NavGroup v-if="can('quotations.view')"
          label="Bán hàng" icon="shopping-bag" prefix="/sales">
          <NavItem :href="route('sales.quotations.index')" icon="document-text" sub>Báo giá</NavItem>
          <NavItem :href="route('sales.orders.index')" icon="shopping-bag" sub>Đơn hàng bán</NavItem>
          <NavItem :href="route('sales.contracts.index')" icon="document" sub>Hợp đồng bán</NavItem>
          <NavItem :href="route('accounting.invoices.index')" icon="document-text" sub>Hóa đơn bán hàng</NavItem>
          <NavItem v-if="can('commissions.view')" :href="route('sales.commissions.index')" icon="currency-dollar" sub>Hoa hồng</NavItem>
        </NavGroup>

        <!-- Mua hàng -->
        <NavGroup v-if="can('purchasing.view')"
          label="Mua hàng" icon="truck" prefix="/purchasing">
          <NavItem :href="route('purchasing.purchase-orders.index')" icon="document-text" sub>Đơn mua hàng</NavItem>
          <NavItem :href="route('purchasing.purchase-contracts.index')" icon="document" sub>Hợp đồng mua</NavItem>
          <NavItem :href="route('purchasing.purchase-invoices.index')" icon="receipt-tax" sub>Hóa đơn đầu vào</NavItem>
          <NavItem v-if="can('purchase-returns.view')" :href="route('purchasing.purchase-returns.index')" icon="reply" sub>Trả hàng mua</NavItem>
        </NavGroup>

        <!-- Dự án thi công -->
        <NavGroup v-if="can('projects.view')"
          label="Dự án thi công" icon="briefcase" prefix="/projects">
          <NavItem :href="route('projects.projects.index')" icon="collection" sub>Dự án</NavItem>
        </NavGroup>

        <!-- Hỗ trợ kỹ thuật -->
        <NavGroup v-if="can('tickets.view')"
          label="Hỗ trợ kỹ thuật" icon="support" prefix="/support">
          <NavItem :href="route('support.tickets.index')" icon="ticket" sub>Ticket kỹ thuật</NavItem>
          <NavItem :href="route('support.warranties.index')" icon="shield-check" sub>Bảo hành</NavItem>
        </NavGroup>

        <!-- Kho -->
        <NavGroup v-if="can('warehouse.view')"
          label="Kho" icon="archive" prefix="/warehouse">
          <NavItem :href="route('warehouse.warehouses.index')" icon="office-building" sub>Kho hàng</NavItem>
          <NavItem :href="route('warehouse.stock-entries.index')" icon="inbox" sub>Nhập kho</NavItem>
          <NavItem :href="route('warehouse.stock-exits.index')" icon="arrow-circle-right" sub>Xuất kho</NavItem>
          <NavItem :href="route('reports.inventory')" icon="cube" sub>Tồn kho</NavItem>
          <NavItem :href="route('warehouse.inventory-counts.index')" icon="clipboard-list" sub>Kiểm kê kho</NavItem>
          <NavItem v-if="can('warehouse.manage')" :href="route('warehouse.opening-balance.index')" icon="database" sub>Tồn đầu kỳ</NavItem>
          <NavItem :href="route('reports.stock_card')" icon="table" sub>Thẻ kho</NavItem>
        </NavGroup>

        <!-- KẾ TOÁN -->
        <div v-if="can('accounting.view')" class="mt-3 pt-3 border-t border-slate-800">
          <p class="px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Kế toán</p>

          <NavGroup label="Quỹ" icon="library" prefix="/accounting/fund">
            <NavItem :href="route('accounting.funds.index')" icon="library" sub>Quản lý quỹ</NavItem>
            <NavItem :href="route('accounting.cash-vouchers.index')" icon="cash" sub>Phiếu thu / chi</NavItem>
            <NavItem :href="route('accounting.fund-transfers.index')" icon="arrows-expand" sub>Luân chuyển quỹ</NavItem>
            <NavItem :href="route('accounting.bank-accounts.index')" icon="library" sub>Tài khoản ngân hàng</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.payment-terms.index')" icon="tag" sub>Điều khoản TT</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.internal-bank-accounts.index')" icon="office-building" sub>TK nội bộ</NavItem>
            <NavItem :href="route('accounting.internal-transfers.index')" icon="arrows-expand" sub>CK nội bộ</NavItem>
          </NavGroup>

          <NavGroup label="Công nợ phải thu (AR)" icon="inbox-in" prefix="/accounting/ar">
            <NavItem :href="route('accounting.ar-collections.index')" icon="inbox-in" sub>Thu nợ KH (TK 131)</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.ar-ap-opening-balance.index')" icon="users" sub>Công nợ đầu kỳ</NavItem>
            <NavItem :href="route('reports.ar.aging')" icon="users" sub>Công nợ phải thu (AR)</NavItem>
            <NavItem :href="route('reports.ar.detail')" icon="document-text" sub>Sổ chi tiết CN phải thu</NavItem>
          </NavGroup>

          <NavGroup label="Công nợ phải trả (AP)" icon="inbox" prefix="/accounting/ap">
            <NavItem :href="route('accounting.ap-payments.index')" icon="inbox" sub>Trả NCC (TK 331)</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.ar-ap-opening-balance.index')" icon="users" sub>Công nợ đầu kỳ</NavItem>
            <NavItem :href="route('reports.ap.aging')" icon="truck" sub>Công nợ phải trả (AP)</NavItem>
            <NavItem :href="route('reports.ap.detail')" icon="document-text" sub>Sổ chi tiết CN phải trả</NavItem>
          </NavGroup>

          <NavGroup label="Tiền lương" icon="clipboard-list" prefix="/accounting/payroll">
            <NavItem :href="route('accounting.payrolls.index')" icon="clipboard-list" sub>Bảng lương</NavItem>
          </NavGroup>

          <NavGroup label="Thuế" icon="receipt-tax" prefix="/accounting/tax">
            <NavItem :href="route('accounting.taxes.index')" icon="receipt-tax" sub>Kê khai thuế</NavItem>
            <NavItem :href="route('reports.vat')" icon="receipt-tax" sub>Báo cáo VAT</NavItem>
          </NavGroup>

          <NavGroup label="Chi phí & Giá vốn" icon="currency-dollar" prefix="/accounting/prepaid">
            <NavItem :href="route('accounting.prepaid-expenses.index')" icon="clock" sub>Chi phí trả trước</NavItem>
            <NavItem :href="route('reports.expense_detail')" icon="currency-dollar" sub>Chi tiết chi phí</NavItem>
            <NavItem :href="route('reports.profit.orders')" icon="trending-up" sub>Lợi nhuận đơn hàng</NavItem>
            <NavItem :href="route('reports.profit.projects')" icon="trending-up" sub>Lợi nhuận dự án</NavItem>
          </NavGroup>

          <NavGroup label="Tài sản cố định" icon="cube" prefix="/accounting/fixed-asset">
            <NavItem :href="route('accounting.fixed-assets.index')" icon="cube" sub>Tài sản cố định</NavItem>
            <NavItem :href="route('accounting.fixed-assets.depreciation.run-page')" icon="refresh" sub>Tính khấu hao</NavItem>
            <NavItem :href="route('accounting.fixed-assets.reports.ledger')" icon="document-text" sub>Sổ TSCĐ</NavItem>
            <NavItem :href="route('accounting.fixed-assets.reports.reconciliation')" icon="check-circle" sub>Báo cáo TSCĐ</NavItem>
          </NavGroup>

          <NavGroup label="Kế toán tổng hợp" icon="pencil-alt" prefix="/accounting/journal">
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.opening-balance.index')" icon="database" sub>Số dư đầu kỳ (TK)</NavItem>
            <NavItem :href="route('accounting.journal-entries.index')" icon="pencil-alt" sub>Phiếu kế toán</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.account-codes.index')" icon="view-list" sub>Hệ thống tài khoản</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.accounting-periods.index')" icon="calendar" sub>Kỳ kế toán</NavItem>
            <NavItem v-if="can('accounting.manage')" :href="route('accounting.period-close.index')" icon="check-circle" sub>Kết chuyển cuối kỳ</NavItem>
            <NavItem :href="route('reports.general_journal')" icon="book-open" sub>Sổ nhật ký chung</NavItem>
            <NavItem :href="route('reports.account_ledger')" icon="document-text" sub>Sổ chi tiết TK</NavItem>
            <NavItem :href="route('reports.document_checklist')" icon="clipboard-check" sub>Bảng kê chứng từ</NavItem>
            <NavItem v-if="can('documents.view')" :href="route('documents.documents.index')" icon="document-text" sub>Tất cả chứng từ</NavItem>
          </NavGroup>

          <NavGroup label="Báo cáo" icon="chart-bar" prefix="acct-reports">
            <NavItem :href="route('reports.cash_flow')" icon="banknotes" sub>Lưu chuyển tiền tệ</NavItem>
            <NavItem :href="route('reports.income_statement')" icon="chart-bar" sub>Kết quả HĐKD</NavItem>
            <NavItem :href="route('reports.balance_sheet')" icon="document" sub>Cân đối kế toán</NavItem>
            <NavItem :href="route('reports.trial_balance')" icon="document-text" sub>Cân đối phát sinh</NavItem>
          </NavGroup>
        </div>

        <!-- Admin -->
        <div v-if="isAdmin" class="mt-3 pt-3 border-t border-slate-800">
          <p class="px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Quản trị</p>
          <NavItem :href="route('admin.shareholders.index')" icon="briefcase">Cổ đông / Thành viên</NavItem>
          <NavItem :href="route('admin.employees.index')" icon="identification">Cán bộ CNV</NavItem>
          <NavItem :href="route('admin.attendance.index')" icon="calendar">Bảng chấm công</NavItem>
          <NavItem :href="route('admin.users.index')" icon="users">Người dùng</NavItem>
          <NavItem :href="route('admin.roles.index')" icon="shield-check">Phân quyền</NavItem>
          <NavItem :href="route('admin.settings.index')" icon="cog">Cài đặt công ty</NavItem>
          <NavItem :href="route('admin.activity-logs.index')" icon="clipboard-list">Nhật ký hoạt động</NavItem>
          <NavItem :href="route('admin.backups.index')" icon="server">Sao lưu dữ liệu</NavItem>
        </div>
      </nav>

    </div>
  </aside>
</template>

<script setup>
import { computed, provide, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/usePermission';
import NavItem from './NavItem.vue';
import NavGroup from './NavGroup.vue';

const props = defineProps({
  open:     Boolean,
  isMobile: Boolean,
});
defineEmits(['close']);

const { hasPermission, hasRole } = usePermission();
const can     = hasPermission;
const isAdmin = computed(() => hasRole('admin'));

const page        = usePage();
const company     = computed(() => page.props.company ?? {});
const companyName = computed(() => company.value.company_name || 'Mini ERP');
const companyLogo = computed(() => company.value.company_logo || null);

// Accordion context: only one NavGroup open at a time
const openPrefix = ref(null);
provide('sidebarAccordion', {
  openPrefix,
  setOpen: (prefix) => { openPrefix.value = prefix; },
});
</script>
