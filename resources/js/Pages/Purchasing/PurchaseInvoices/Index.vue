<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Hóa đơn đầu vào</h1>
        <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-invoices.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Thêm hóa đơn
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm mã, số HĐ, NCC..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="pending">Chưa nhận HĐ</option>
          <option value="received">Đã nhận HĐ</option>
          <option value="reviewing">Đang kiểm tra</option>
          <option value="valid">Hợp lệ</option>
          <option value="need_supplement">Cần bổ sung</option>
          <option value="partial_paid">TT một phần</option>
          <option value="paid">Đã thanh toán</option>
          <option value="cancelled">Đã hủy</option>
        </select>
        <button v-if="search || statusFilter" @click="clearFilters"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số HĐ NCC</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn mua</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn TT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ inv.code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ inv.invoice_number ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-900">{{ inv.supplier }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ inv.purchase_order }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.invoice_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(inv.total) }}</td>
              <td class="px-5 py-3 text-right" :class="inv.remaining > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                {{ formatVnd(inv.remaining) }}
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="inv.status_color">{{ inv.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('purchasing.purchase-invoices.show', inv.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!invoices.data?.length">
              <td colspan="10" class="px-5 py-10 text-center text-gray-400">Chưa có hóa đơn đầu vào nào</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="invoices.links" :meta="invoices.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  invoices: Object,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const { formatVnd } = useCurrency();

const search       = ref('');
const statusFilter = ref('');

function applyFilters() {
  router.get(route('purchasing.purchase-invoices.index'), {
    search: search.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = '';
  statusFilter.value = '';
  applyFilters();
}

</script>
