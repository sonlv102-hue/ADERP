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
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70 disabled:cursor-not-allowed">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Lọc
        </button>
        <button v-if="hasFilters" @click="clearFilters" class="text-gray-500 hover:text-gray-700 text-sm px-2">Xóa lọc</button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 transition-opacity" :class="{ 'opacity-60': isLoading }">
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
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto transition-opacity" :class="{ 'opacity-60': isLoading }">
        <table class="min-w-full text-sm min-w-max">
          <thead class="bg-gray-50">
            <tr class="border-b border-gray-200">
              <th rowspan="2" class="text-left px-4 py-2 font-semibold text-gray-600 border-r border-gray-200 align-middle">Mã SP</th>
              <th rowspan="2" class="text-left px-4 py-2 font-semibold text-gray-600 border-r border-gray-200 align-middle">Tên sản phẩm</th>
              <th rowspan="2" class="text-left px-4 py-2 font-semibold text-gray-600 border-r border-gray-200 align-middle">ĐVT</th>
              <th rowspan="2" class="text-left px-4 py-2 font-semibold text-gray-600 border-r border-gray-200 align-middle">Danh mục</th>
              <th colspan="2" class="text-center px-4 py-2 font-semibold text-gray-600 border-r border-gray-200">Tồn đầu kỳ</th>
              <th colspan="3" class="text-center px-4 py-2 font-semibold text-blue-700 border-r border-gray-200 bg-blue-50">Nhập trong kỳ</th>
              <th colspan="3" class="text-center px-4 py-2 font-semibold text-orange-700 border-r border-gray-200 bg-orange-50">Xuất trong kỳ</th>
              <th colspan="2" class="text-center px-4 py-2 font-semibold text-green-700 bg-green-50">Tồn cuối kỳ</th>
            </tr>
            <tr class="border-b-2 border-gray-200">
              <th class="text-right px-3 py-2 font-medium text-gray-500 text-xs whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-gray-500 text-xs border-r border-gray-200 whitespace-nowrap">Giá trị</th>
              <th class="text-right px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 whitespace-nowrap">SL</th>
              <th class="text-center px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 whitespace-nowrap">Ngày nhập g.nhất</th>
              <th class="text-right px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 border-r border-gray-200 whitespace-nowrap">Giá trị</th>
              <th class="text-right px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 whitespace-nowrap">SL</th>
              <th class="text-center px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 whitespace-nowrap">Ngày xuất g.nhất</th>
              <th class="text-right px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 border-r border-gray-200 whitespace-nowrap">Giá trị</th>
              <th class="text-right px-3 py-2 font-medium text-green-600 text-xs bg-green-50 whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-green-600 text-xs bg-green-50 whitespace-nowrap">Giá trị</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows.data" :key="row.id" class="hover:bg-gray-50">
              <td class="px-4 py-2.5 font-mono text-xs text-primary-700 border-r border-gray-100">{{ row.code }}</td>
              <td class="px-4 py-2.5 text-gray-800 border-r border-gray-100">{{ row.name }}</td>
              <td class="px-4 py-2.5 text-gray-500 text-xs border-r border-gray-100">{{ row.unit }}</td>
              <td class="px-4 py-2.5 text-gray-500 text-xs border-r border-gray-100">{{ row.category ?? '—' }}</td>
              <!-- Tồn đầu -->
              <td class="px-3 py-2.5 text-right text-gray-700">{{ fmtQty(row.stock_begin) }}</td>
              <td class="px-3 py-2.5 text-right text-gray-600 text-xs border-r border-gray-100">{{ fmt(row.value_begin) }}</td>
              <!-- Nhập -->
              <td class="px-3 py-2.5 text-right text-blue-700">{{ fmtQty(row.stock_in) }}</td>
              <td class="px-3 py-2.5 text-center text-gray-500 text-xs">{{ fmtDate(row.last_in_date) }}</td>
              <td class="px-3 py-2.5 text-right text-blue-700 border-r border-gray-100">{{ fmt(row.value_in) }}</td>
              <!-- Xuất -->
              <td class="px-3 py-2.5 text-right text-orange-700">{{ fmtQty(row.stock_out) }}</td>
              <td class="px-3 py-2.5 text-center text-gray-500 text-xs">{{ fmtDate(row.last_out_date) }}</td>
              <td class="px-3 py-2.5 text-right text-orange-700 border-r border-gray-100">{{ fmt(row.value_out) }}</td>
              <!-- Tồn cuối -->
              <td class="px-3 py-2.5 text-right font-semibold" :class="row.stock_end < 0 ? 'text-red-700' : 'text-gray-800'">{{ fmtQty(row.stock_end) }}</td>
              <td class="px-3 py-2.5 text-right font-semibold text-green-700">{{ fmt(row.value_end) }}</td>
            </tr>
            <tr v-if="!rows.data?.length">
              <td colspan="14" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.data?.length" class="bg-gray-50 border-t-2 border-gray-300">
            <tr>
              <td colspan="4" class="px-4 py-3 font-semibold text-gray-700 text-sm">Tổng cộng</td>
              <td></td>
              <td class="px-3 py-3 text-right font-semibold text-gray-800">{{ fmt(summary.total_begin_value) }}</td>
              <td></td><td></td>
              <td class="px-3 py-3 text-right font-semibold text-blue-700">{{ fmt(summary.total_in_value) }}</td>
              <td></td><td></td>
              <td class="px-3 py-3 text-right font-semibold text-orange-700">{{ fmt(summary.total_out_value) }}</td>
              <td></td>
              <td class="px-3 py-3 text-right font-bold text-green-700">{{ fmt(summary.total_end_value) }}</td>
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
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  rows:       Object,
  summary:    Object,
  warehouses: Array,
  categories: Array,
  filters:    Object,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

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

function fmtDate(d) {
  if (!d) return '—';
  const [y, m, day] = d.split('-');
  return `${day}/${m}/${y}`;
}
</script>
