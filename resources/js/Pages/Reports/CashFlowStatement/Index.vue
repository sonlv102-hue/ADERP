<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo Lưu chuyển Tiền tệ</h1>
          <p class="text-sm text-gray-500 mt-0.5">Mẫu B03-DNN (TT 133/2016/TT-BTC) — Phương pháp trực tiếp</p>
        </div>
        <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel B03-DNN
        </a>
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center flex-wrap">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Năm:</label>
          <select v-model="year" @change="onYearChange"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <span class="text-gray-400 text-sm">hoặc chọn khoảng:</span>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Từ</label>
          <input v-model="dateFrom" type="date" @change="year = null"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Đến</label>
          <input v-model="dateTo" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <button @click="applyFilters"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Cập nhật
        </button>
      </div>

      <!-- Note về phân loại tự động -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-700">
        Phân loại dòng tiền dựa vào TK đối ứng của các bút toán chạm TK 111/112. Giao dịch không xác định được phân loại vào "Thu/Chi khác từ HĐKD".
      </div>

      <!-- B03 Table -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
          <p class="text-sm font-semibold text-gray-700">
            Kỳ: {{ props.dateFrom }} → {{ props.dateTo }}
          </p>
        </div>

        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase w-20">Mã số</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Chỉ tiêu</th>
              <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase w-48">Số tiền (VND)</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <!-- Đầu kỳ -->
            <tr class="bg-blue-50">
              <td class="px-4 py-2 text-sm font-semibold text-gray-700">60</td>
              <td class="px-4 py-2 text-sm font-semibold text-gray-700">Tiền và tương đương tiền đầu kỳ</td>
              <td class="px-4 py-2 text-right text-sm font-semibold text-gray-700">{{ formatVnd(statement.opening_cash) }}</td>
            </tr>

            <!-- Các phần -->
            <template v-for="section in statement.sections" :key="section.code">
              <!-- Section header -->
              <tr class="bg-gray-50">
                <td class="px-4 py-2 text-xs font-bold text-gray-600 uppercase">{{ section.code }}</td>
                <td class="px-4 py-2 text-xs font-bold text-gray-600 uppercase" colspan="2">{{ section.label }}</td>
              </tr>
              <!-- Lines -->
              <tr v-for="line in section.lines" :key="line.code"
                class="hover:bg-gray-50 transition-colors"
                :class="line.amount === 0 ? 'text-gray-400' : ''">
                <td class="px-4 py-2 text-sm text-gray-500 pl-8">{{ line.code }}</td>
                <td class="px-4 py-2 text-sm pl-8">{{ line.label }}</td>
                <td class="px-4 py-2 text-right text-sm"
                  :class="line.amount < 0 ? 'text-red-600' : (line.amount > 0 ? 'text-gray-900' : 'text-gray-300')">
                  {{ formatVnd(line.amount) }}
                </td>
              </tr>
              <!-- Net subtotal -->
              <tr :class="section.net >= 0 ? 'bg-green-50' : 'bg-red-50'">
                <td class="px-4 py-2 text-sm font-semibold">{{ section.net_code }}</td>
                <td class="px-4 py-2 text-sm font-semibold">
                  Lưu chuyển tiền thuần từ {{ section.code === 'I' ? 'HĐKD' : section.code === 'II' ? 'HĐĐT' : 'HĐTC' }}
                </td>
                <td class="px-4 py-2 text-right text-sm font-semibold"
                  :class="section.net < 0 ? 'text-red-700' : 'text-green-700'">
                  {{ formatVnd(section.net) }}
                </td>
              </tr>
              <tr><td colspan="3" class="py-1"></td></tr>
            </template>

            <!-- Tổng net -->
            <tr class="bg-primary-50 border-t-2 border-primary-200">
              <td class="px-4 py-3 text-sm font-bold text-primary-800">50</td>
              <td class="px-4 py-3 text-sm font-bold text-primary-800">Lưu chuyển tiền thuần trong kỳ (50 = 20 + 30 + 40)</td>
              <td class="px-4 py-3 text-right text-sm font-bold"
                :class="statement.net_total < 0 ? 'text-red-700' : 'text-primary-800'">
                {{ formatVnd(statement.net_total) }}
              </td>
            </tr>

            <!-- Cuối kỳ -->
            <tr class="bg-blue-100 border-t-2 border-blue-300">
              <td class="px-4 py-3 text-sm font-bold text-blue-900">70</td>
              <td class="px-4 py-3 text-sm font-bold text-blue-900">Tiền và tương đương tiền cuối kỳ (70 = 50 + 60)</td>
              <td class="px-4 py-3 text-right text-sm font-bold text-blue-900">{{ formatVnd(statement.closing_cash) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  statement: Object,
  filters:   Object,
  dateFrom:  String,
  dateTo:    String,
});

const currentYear = new Date().getFullYear();
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i);

const year     = ref(props.filters?.year ? parseInt(props.filters.year) : currentYear);
const dateFrom = ref(props.dateFrom ?? `${currentYear}-01-01`);
const dateTo   = ref(props.dateTo   ?? `${currentYear}-12-31`);

const exportUrl = computed(() => {
  const p = new URLSearchParams({ date_from: dateFrom.value, date_to: dateTo.value });
  return route('reports.cash_flow_statement.export') + '?' + p.toString();
});

function onYearChange() {
  if (year.value) {
    dateFrom.value = `${year.value}-01-01`;
    dateTo.value   = `${year.value}-12-31`;
  }
}

function applyFilters() {
  router.get(route('reports.cash_flow_statement'), {
    date_from: dateFrom.value,
    date_to:   dateTo.value,
  }, { preserveState: true });
}

function formatVnd(value) {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat('vi-VN').format(Math.round(value)) + ' ₫';
}
</script>
