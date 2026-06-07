<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Báo cáo lợi nhuận — Dự án</h1>
        <p class="text-sm text-gray-500 mt-0.5">Doanh thu (chưa VAT) − Vật tư − Chi phí phát sinh − Hoa hồng</p>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap items-center">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Mã dự án, tên, khách hàng..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-primary-500" />
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
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70 disabled:cursor-not-allowed">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Lọc
        </button>
        <button v-if="hasFilters" @click="clearFilters" class="text-gray-500 hover:text-gray-700 text-sm px-2">
          Xóa lọc
        </button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Doanh thu (trang này)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_revenue) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng chi phí</p>
          <p class="text-lg font-bold text-red-600">{{ fmt(summary.total_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4"
          :class="summary.total_profit >= 0 ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
          <p class="text-xs mb-1" :class="summary.total_profit >= 0 ? 'text-green-600' : 'text-red-600'">Lợi nhuận</p>
          <p class="text-lg font-bold" :class="summary.total_profit >= 0 ? 'text-green-700' : 'text-red-700'">
            {{ fmt(summary.total_profit) }}
          </p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto transition-opacity" :class="{ 'opacity-60': isLoading }">
        <table class="w-full text-sm min-w-max">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Dự án</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày BĐ</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">TT</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Doanh thu</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Vật tư</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Chi phí PS</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Hoa hồng</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Tổng CP</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Lợi nhuận</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Tỷ suất</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows.data" :key="row.id" class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <Link :href="route('projects.projects.show', row.id)"
                  class="font-mono font-medium text-primary-700 hover:underline">{{ row.code }}</Link>
                <p class="text-xs text-gray-500 mt-0.5 max-w-[160px] truncate">{{ row.name }}</p>
              </td>
              <td class="px-4 py-3 text-gray-800">{{ row.customer }}</td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ fmtDate(row.start_date) }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusClass(row.status)">
                  {{ statusLabel(row.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-gray-800">{{ fmt(row.revenue) }}</td>
              <td class="px-4 py-3 text-right text-red-500">{{ fmt(row.material_cost) }}</td>
              <td class="px-4 py-3 text-right text-red-500">{{ fmt(row.expense_cost) }}</td>
              <td class="px-4 py-3 text-right text-orange-500">{{ fmt(row.commission) }}</td>
              <td class="px-4 py-3 text-right font-medium text-red-700">{{ fmt(row.total_cost) }}</td>
              <td class="px-4 py-3 text-right font-semibold"
                :class="row.profit >= 0 ? 'text-green-700' : 'text-red-700'">
                {{ fmt(row.profit) }}
              </td>
              <td class="px-4 py-3 text-right">
                <span v-if="row.margin !== null" class="font-medium text-xs"
                  :class="row.margin >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ row.margin }}%
                </span>
                <span v-else class="text-gray-300">—</span>
              </td>
            </tr>
            <tr v-if="!rows.data?.length">
              <td colspan="11" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="rows.links" :meta="rows.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  rows:    Object,
  summary: Object,
  filters: Object,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

const search   = ref(props.filters?.search    ?? '');
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');

const hasFilters = computed(() => search.value || dateFrom.value || dateTo.value);

function applyFilters() {
  router.get(route('reports.profit.projects'), {
    search:    search.value    || undefined,
    date_from: dateFrom.value  || undefined,
    date_to:   dateTo.value    || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = dateFrom.value = dateTo.value = '';
  applyFilters();
}


function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('vi-VN');
}

function statusLabel(s) {
  const map = {
    planning:    'Lên kế hoạch',
    in_progress: 'Đang thi công',
    on_hold:     'Tạm dừng',
    completed:   'Hoàn thành',
    cancelled:   'Đã hủy',
  };
  return map[s] ?? s;
}

function statusClass(s) {
  const map = {
    planning:    'bg-gray-100 text-gray-600',
    in_progress: 'bg-blue-100 text-blue-700',
    on_hold:     'bg-yellow-100 text-yellow-700',
    completed:   'bg-green-100 text-green-700',
    cancelled:   'bg-red-100 text-red-600',
  };
  return map[s] ?? 'bg-gray-100 text-gray-600';
}
</script>
