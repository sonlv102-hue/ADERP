<template>
  <!-- Outer aside animates width; inner div keeps fixed w-64 so content doesn't reflow -->
  <aside class="bg-gray-900 text-white flex-shrink-0 overflow-hidden transition-[width] duration-300 ease-in-out"
    :class="open ? 'w-64' : 'w-0'">
    <div class="w-64 h-full flex flex-col">

      <!-- Logo -->
      <div class="h-16 flex items-center px-4 border-b border-gray-700 flex-shrink-0">
        <template v-if="companyLogo">
          <img :src="companyLogo" :alt="companyName" class="h-9 w-9 rounded-lg object-contain mr-3 bg-white p-0.5 flex-shrink-0" />
        </template>
        <template v-else>
          <div class="w-9 h-9 bg-primary-500 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" />
            </svg>
          </div>
        </template>
        <span class="font-bold text-sm leading-tight line-clamp-2">{{ companyName }}</span>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 py-3 overflow-y-auto space-y-0.5">
        <NavItem :href="route('dashboard')" icon="home">Dashboard</NavItem>
        <NavItem :href="route('notifications.index')" icon="bell">Thông báo</NavItem>

        <NavGroup v-if="can('customers.view') || can('leads.view')"
          label="CRM" icon="users" prefix="/crm">
          <NavItem v-if="can('customers.view')" :href="route('crm.customers.index')" icon="users" sub>Khách hàng</NavItem>
          <NavItem v-if="can('leads.view')" :href="route('crm.leads.index')" icon="user-add" sub>Khách hàng tiềm năng</NavItem>
        </NavGroup>

        <NavGroup v-if="can('quotations.view')"
          label="Bán hàng" icon="shopping-bag" prefix="/sales">
          <NavItem :href="route('sales.quotations.index')" icon="document-text" sub>Báo giá</NavItem>
          <NavItem :href="route('sales.orders.index')" icon="shopping-bag" sub>Đơn hàng</NavItem>
          <NavItem :href="route('sales.contracts.index')" icon="document" sub>Hợp đồng bán</NavItem>
          <NavItem v-if="can('commissions.view')" :href="route('sales.commissions.index')" icon="currency-dollar" sub>Hoa hồng</NavItem>
          <NavItem v-if="can('sales-returns.view')" :href="route('sales.sales-returns.index')" icon="reply" sub>Trả hàng bán</NavItem>
        </NavGroup>

        <NavGroup v-if="can('warehouse.view')"
          label="Kho hàng" icon="archive" prefix="/warehouse">
          <NavItem :href="route('warehouse.warehouses.index')" icon="office-building" sub>Kho hàng</NavItem>
          <NavItem :href="route('warehouse.stock-entries.index')" icon="inbox" sub>Nhập kho</NavItem>
          <NavItem :href="route('warehouse.stock-exits.index')" icon="arrow-circle-right" sub>Xuất kho</NavItem>
          <NavItem v-if="can('stock-transfers.view')" :href="route('warehouse.stock-transfers.index')" icon="switch-horizontal" sub>Chuyển kho</NavItem>
          <NavItem :href="route('warehouse.inventory-counts.index')" icon="clipboard-list" sub>Kiểm kê kho</NavItem>
        </NavGroup>

        <NavGroup v-if="can('products.view')"
          label="Danh mục" icon="collection" prefix="/catalog">
          <NavItem :href="route('catalog.product-categories.index')" icon="tag" sub>Danh mục SP</NavItem>
          <NavItem :href="route('catalog.products.index')" icon="cube" sub>Sản phẩm</NavItem>
          <NavItem :href="route('catalog.services.index')" icon="tag" sub>Dịch vụ</NavItem>
          <NavItem v-if="can('price-lists.view')" :href="route('catalog.price-lists.index')" icon="tag" sub>Bảng giá</NavItem>
        </NavGroup>

        <NavGroup v-if="can('projects.view')"
          label="Dự án thi công" icon="briefcase" prefix="/projects">
          <NavItem :href="route('projects.projects.index')" icon="collection" sub>Dự án</NavItem>
        </NavGroup>

        <NavGroup v-if="can('tickets.view')"
          label="Hỗ trợ kỹ thuật" icon="support" prefix="/support">
          <NavItem :href="route('support.tickets.index')" icon="ticket" sub>Ticket kỹ thuật</NavItem>
          <NavItem :href="route('support.warranties.index')" icon="shield-check" sub>Bảo hành</NavItem>
        </NavGroup>

        <NavGroup v-if="can('purchasing.view')"
          label="Mua hàng" icon="truck" prefix="/purchasing">
          <NavItem :href="route('purchasing.purchase-orders.index')" icon="document-text" sub>Đơn mua hàng</NavItem>
          <NavItem :href="route('purchasing.purchase-contracts.index')" icon="document" sub>Hợp đồng mua</NavItem>
          <NavItem :href="route('purchasing.purchase-invoices.index')" icon="receipt-tax" sub>Hóa đơn đầu vào</NavItem>
          <NavItem v-if="can('purchase-returns.view')" :href="route('purchasing.purchase-returns.index')" icon="reply" sub>Trả hàng mua</NavItem>
          <NavItem :href="route('warehouse.suppliers.index')" icon="office-building" sub>Nhà cung cấp</NavItem>
        </NavGroup>

        <NavGroup v-if="can('accounting.view')"
          label="Kế toán" icon="currency-dollar" prefix="/accounting">
          <NavItem :href="route('accounting.invoices.index')"      icon="document-text" sub>Hóa đơn</NavItem>
          <NavItem :href="route('accounting.funds.index')"         icon="library"       sub>Quản lý quỹ</NavItem>
          <NavItem :href="route('accounting.cash-vouchers.index')" icon="cash"          sub>Phiếu thu / chi</NavItem>
          <NavItem :href="route('accounting.payrolls.index')"      icon="clipboard-list" sub>Bảng lương tháng</NavItem>
          <NavItem :href="route('accounting.taxes.index')"         icon="receipt-tax"   sub>Kê khai thuế VAT</NavItem>
        </NavGroup>

        <NavGroup v-if="can('documents.view')"
          label="Chứng từ" icon="folder" prefix="/documents">
          <NavItem :href="route('documents.documents.index')" icon="document-text" sub>Tất cả chứng từ</NavItem>
          <NavItem v-if="isAdmin" :href="route('documents.types.index')" icon="tag" sub>Loại chứng từ</NavItem>
        </NavGroup>

        <NavGroup v-if="can('reports.view')"
          label="Báo cáo" icon="chart-bar" prefix="/reports">
          <NavItem :href="route('reports.profit.orders')"    icon="trending-up"     sub>Lợi nhuận đơn hàng</NavItem>
          <NavItem :href="route('reports.profit.projects')"  icon="trending-up"     sub>Lợi nhuận dự án</NavItem>
          <NavItem :href="route('reports.ar.aging')"         icon="users"           sub>Công nợ phải thu (AR)</NavItem>
          <NavItem :href="route('reports.ap.aging')"         icon="truck"           sub>Công nợ phải trả (AP)</NavItem>
          <NavItem :href="route('reports.vat')"              icon="receipt-tax"     sub>Báo cáo VAT</NavItem>
          <NavItem :href="route('reports.inventory')"        icon="cube"            sub>Tồn kho</NavItem>
          <NavItem :href="route('reports.fund-ledger.index')"  icon="banknotes"       sub>Sổ quỹ</NavItem>
          <NavItem :href="route('reports.cash_flow')"        icon="banknotes"       sub>Thu Chi</NavItem>
          <NavItem :href="route('reports.income_statement')" icon="chart-bar"       sub>Kết quả HĐKD</NavItem>
          <NavItem :href="route('reports.balance_sheet')"    icon="document"        sub>Cân đối kế toán</NavItem>
          <NavItem :href="route('reports.trial_balance')"    icon="document-text"   sub>Cân đối phát sinh</NavItem>
          <NavItem :href="route('reports.general_journal')"  icon="book-open"       sub>Sổ nhật ký chung</NavItem>
          <NavItem :href="route('reports.account_ledger')"   icon="document-text"   sub>Sổ chi tiết TK</NavItem>
          <NavItem :href="route('reports.expense_detail')"   icon="currency-dollar" sub>Chi tiết chi phí</NavItem>
          <NavItem :href="route('reports.fixed_assets')"     icon="cube"            sub>Tài sản cố định</NavItem>
        </NavGroup>

        <!-- Admin -->
        <div v-if="isAdmin" class="mt-3 pt-3 border-t border-gray-700">
          <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Quản trị</p>
          <NavItem :href="route('admin.users.index')" icon="users">Người dùng</NavItem>
          <NavItem :href="route('admin.roles.index')" icon="shield-check">Phân quyền</NavItem>
          <NavItem :href="route('admin.settings.index')" icon="cog">Cài đặt công ty</NavItem>
          <NavItem :href="route('admin.fixed-assets.index')" icon="cube">Tài sản cố định</NavItem>
          <NavItem :href="route('admin.activity-logs.index')" icon="clipboard-list">Nhật ký hoạt động</NavItem>
        </div>
      </nav>

    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/usePermission';
import NavItem from './NavItem.vue';
import NavGroup from './NavGroup.vue';

defineProps({ open: Boolean });
defineEmits(['close']);

const { hasPermission, hasRole } = usePermission();
const can = hasPermission;
const isAdmin = computed(() => hasRole('admin'));

const page = usePage();
const company = computed(() => page.props.company ?? {});
const companyName = computed(() => company.value.company_name || 'Mini ERP');
const companyLogo = computed(() => company.value.company_logo || null);
</script>
