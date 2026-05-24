<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo Tồn kho</h1>
          <p class="text-sm text-gray-500 mt-0.5">Tồn đầu kỳ + Nhập − Xuất = Tồn cuối kỳ (theo khoảng thời gian)</p>
        </div>
        <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel
        </a>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap items-center">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Mã SP, tên SP..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Từ</label>
          <input v-model="dateFrom" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Đến</label>
          <input v-model="dateTo" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <select v-model="warehouseId"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả kho</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
        <select v-model="categoryId"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả danh mục</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button @click="applyFilters"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Lọc</button>
        <button v-if="hasFilters" @click="clearFilters" class="text-gray-500 hover:text-gray-700 text-sm px-2">Xóa lọc</button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Giá trị tồn đầu kỳ</p>
          <p class="text-base font-bold text-gray-800">{{ fmt(summary.total_begin_value) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-blue-200 bg-blue-50 p-4">
          <p class="text-xs text-blue-600 mb-1">Giá trị nhập trong kỳ</p>
          <p class="text-base font-bold text-blue-700">{{ fmt(summary.total_in_value) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-orange-200 bg-orange-50 p-4">
          <p class="text-xs text-orange-600 mb-1">Giá trị xuất trong kỳ</p>
          <p class="text-base font-bold text-orange-700">{{ fmt(summary.total_out_value) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Giá trị tồn cuối kỳ</p>
          <p class="text-base font-bold text-green-700">{{ fmt(summary.total_end_value) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Danh mục</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Tồn đầu</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Nhập</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Xuất</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Tồn cuối</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Đơn giá vốn</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Giá trị tồn</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows.data" :key="row.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs text-primary-700">{{ row.code }}</td>
              <td class="px-4 py-3 text-gray-800">{{ row.name }}</td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ row.unit }}</td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ row.category ?? '—' }}</td>
              <td class="px-4 py-3 text-right text-gray-700">{{ fmtQty(row.stock_begin) }}</td>
              <td class="px-4 py-3 text-right text-blue-700">{{ fmtQty(row.stock_in) }}</td>
              <td class="px-4 py-3 text-right text-orange-700">{{ fmtQty(row.stock_out) }}</td>
              <td class="px-4 py-3 text-right font-semibold" :class="row.stock_end < 0 ? 'text-red-700' : 'text-gray-800'">
                {{ fmtQty(row.stock_end) }}
              </td>
              <td class="px-4 py-3 text-right text-gray-600 text-xs">{{ fmt(row.cost_price) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-green-700">{{ fmt(row.value_end) }}</td>
            </tr>
            <tr v-if="!rows.data?.length">
              <td colspan="10" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.data?.length" class="bg-gray-50 border-t-2 border-gray-300">
            <tr>
              <td colspan="4" class="px-4 py-3 font-semibold text-gray-700">Tổng giá trị (trang này)</td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ fmt(summary.total_begin_value) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-blue-700">{{ fmt(summary.total_in_value) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-orange-700">{{ fmt(summary.total_out_value) }}</td>
              <td colspan="2"></td>
              <td class="px-4 py-3 text-right font-bold text-green-700">{{ fmt(summary.total_end_value) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <Pagination :links="rows.links" :meta="rows.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  rows:       Object,
  summary:    Object,
  warehouses: Array,
  categories: Array,
  filters:    Object,
});

const { formatVnd: fmt } = useCurrency();

const search      = ref(props.filters?.search       ?? '');
const dateFrom    = ref(props.filters?.date_from    ?? '');
const dateTo      = ref(props.filters?.date_to      ?? '');
const warehouseId = ref(props.filters?.warehouse_id ?? '');
const categoryId  = ref(props.filters?.category_id  ?? '');

const hasFilters = computed(() => search.value || warehouseId.value || categoryId.value);

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  if (search.value)      params.set('search',       search.value);
  if (dateFrom.value)    params.set('date_from',    dateFrom.value);
  if (dateTo.value)      params.set('date_to',      dateTo.value);
  if (warehouseId.value) params.set('warehouse_id', warehouseId.value);
  if (categoryId.value)  params.set('category_id',  categoryId.value);
  const qs = params.toString();
  return route('reports.inventory.export') + (qs ? '?' + qs : '');
});

function applyFilters() {
  router.get(route('reports.inventory'), {
    search:       search.value       || undefined,
    date_from:    dateFrom.value     || undefined,
    date_to:      dateTo.value       || undefined,
    warehouse_id: warehouseId.value  || undefined,
    category_id:  categoryId.value   || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = warehouseId.value = categoryId.value = '';
  applyFilters();
}

function fmtQty(n) {
  return Number(n).toLocaleString('vi-VN');
}
</script>
