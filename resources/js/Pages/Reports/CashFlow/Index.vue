<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thu Chi (Cash Flow)</h1>
          <p class="text-sm text-gray-500 mt-0.5">Thu từ thanh toán hóa đơn bán · Chi từ thanh toán hóa đơn mua</p>
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
        <select v-model="method"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả phương thức</option>
          <option value="cash">Tiền mặt</option>
          <option value="bank_transfer">Chuyển khoản</option>
          <option value="other">Khác</option>
        </select>
        <select v-model="type"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Thu + Chi</option>
          <option value="in">Chỉ Thu</option>
          <option value="out">Chỉ Chi</option>
        </select>
        <button @click="applyFilters"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Lọc</button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Tổng Thu</p>
          <p class="text-lg font-bold text-green-700">{{ fmt(summary.total_in) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600 mb-1">Tổng Chi</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_out) }}</p>
        </div>
        <div class="bg-white rounded-xl border p-4"
          :class="summary.net_cash_flow >= 0 ? 'border-green-300 bg-green-100' : 'border-red-300 bg-red-100'">
          <p class="text-xs mb-1" :class="summary.net_cash_flow >= 0 ? 'text-green-700' : 'text-red-700'">Dòng tiền ròng</p>
          <p class="text-lg font-bold" :class="summary.net_cash_flow >= 0 ? 'text-green-800' : 'text-red-800'">
            {{ summary.net_cash_flow >= 0 ? '+' : '' }}{{ fmt(summary.net_cash_flow) }}
          </p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Nội dung</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Phương thức</th>
              <th class="text-right px-4 py-3 font-semibold text-green-700">Thu</th>
              <th class="text-right px-4 py-3 font-semibold text-red-700">Chi</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Số dư</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(row, idx) in rows.data" :key="idx" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-500 text-xs">{{ fmtDate(row.date) }}</td>
              <td class="px-4 py-3 text-gray-800">{{ row.description }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-xs" :class="methodClass(row.method)">
                  {{ methodLabel(row.method) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-medium text-green-700">
                {{ row.type === 'in' ? fmt(row.amount) : '—' }}
              </td>
              <td class="px-4 py-3 text-right font-medium text-red-700">
                {{ row.type === 'out' ? fmt(row.amount) : '—' }}
              </td>
              <td class="px-4 py-3 text-right font-semibold" :class="row.balance >= 0 ? 'text-gray-800' : 'text-red-700'">
                {{ fmt(row.balance) }}
              </td>
            </tr>
            <tr v-if="!rows.data?.length">
              <td colspan="6" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Simple pagination -->
      <div v-if="rows.last_page > 1" class="flex justify-center gap-2">
        <button v-for="p in rows.last_page" :key="p"
          @click="goPage(p)"
          class="px-3 py-1 rounded text-sm border"
          :class="p === rows.current_page ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 hover:bg-gray-50'">
          {{ p }}
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  rows:    Object,
  summary: Object,
  filters: Object,
});

const { formatVnd: fmt } = useCurrency();

const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');
const method   = ref(props.filters?.method    ?? '');
const type     = ref(props.filters?.type      ?? '');

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  if (dateFrom.value) params.set('date_from', dateFrom.value);
  if (dateTo.value)   params.set('date_to',   dateTo.value);
  if (method.value)   params.set('method',    method.value);
  if (type.value)     params.set('type',      type.value);
  const qs = params.toString();
  return route('reports.cash_flow.export') + (qs ? '?' + qs : '');
});

function applyFilters(page = 1) {
  router.get(route('reports.cash_flow'), {
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
    method:    method.value   || undefined,
    type:      type.value     || undefined,
    page,
  }, { preserveState: true, replace: true });
}

function goPage(p) { applyFilters(p); }

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('vi-VN');
}

function methodLabel(m) {
  return { cash: 'Tiền mặt', bank_transfer: 'Chuyển khoản', other: 'Khác' }[m] ?? m;
}

function methodClass(m) {
  return { cash: 'bg-yellow-100 text-yellow-700', bank_transfer: 'bg-blue-100 text-blue-700', other: 'bg-gray-100 text-gray-600' }[m] ?? 'bg-gray-100 text-gray-600';
}
</script>
