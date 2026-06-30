<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo chi tiết xuất kho</h1>
          <p class="text-sm text-gray-500 mt-0.5">Xem hàng hóa xuất ngày nào, xuất ở kho nào và số lượng bao nhiêu.</p>
        </div>
        <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Xuất Excel
        </a>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">Từ ngày</label>
          <input type="date" v-model="filters.date_from" @change="applyFilters" class="form-input mt-1 w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Đến ngày</label>
          <input type="date" v-model="filters.date_to" @change="applyFilters" class="form-input mt-1 w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Kho</label>
          <select v-model="filters.warehouse_id" @change="applyFilters" class="form-input mt-1 w-full">
            <option value="">Tất cả kho</option>
            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Tìm kiếm</label>
          <input type="text" v-model="filters.search" @keyup.enter="applyFilters" placeholder="Mã SP, tên SP, mã XK..." class="form-input mt-1 w-full" />
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase tracking-wide text-gray-600">
            <tr>
              <th class="px-4 py-3 text-left">Mã phiếu</th>
              <th class="px-4 py-3 text-left">Ngày xuất</th>
              <th class="px-4 py-3 text-left">Kho</th>
              <th class="px-4 py-3 text-left">Khách hàng</th>
              <th class="px-4 py-3 text-left">Lý do</th>
              <th class="px-4 py-3 text-left">Mã SP</th>
              <th class="px-4 py-3 text-left">Tên SP</th>
              <th class="px-4 py-3 text-left">ĐVT</th>
              <th class="px-4 py-3 text-right">SL</th>
              <th class="px-4 py-3 text-right">Đơn giá</th>
              <th class="px-4 py-3 text-right">Giá trị</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows.data" :key="row.document_code + row.product_code" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ row.document_code }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.document_date }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.warehouse }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.partner }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.reason }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.product_code }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.product_name }}</td>
              <td class="px-4 py-3 text-gray-600">{{ row.unit }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ fmtQty(row.quantity) }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ fmtMoney(row.unit_price) }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ fmtMoney(row.total_cost) }}</td>
            </tr>
            <tr v-if="!rows.data.length">
              <td colspan="11" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu phù hợp</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="rows.links" :meta="rows.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ rows: Object, filters: Object, warehouses: Array });
const filters = reactive({
  search: props.filters.search || '',
  date_from: props.filters.date_from || '',
  date_to: props.filters.date_to || '',
  warehouse_id: props.filters.warehouse_id || '',
});

const { formatVnd: fmtMoney } = useCurrency();

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.warehouse_id) params.set('warehouse_id', filters.warehouse_id);
  return route('reports.stock_exit_details.export') + (params.toString() ? `?${params.toString()}` : '');
});

function applyFilters() {
  router.get(route('reports.stock_exit_details'), {
    search: filters.search || undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
    warehouse_id: filters.warehouse_id || undefined,
  }, { preserveState: true, replace: true });
}

function fmtQty(value) {
  return Number(value).toLocaleString('vi-VN');
}
</script>
