<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Công nợ phải trả (AP)</h1>
          <p class="text-sm text-gray-500 mt-0.5">Hóa đơn mua + công nợ đầu kỳ — tổng tiền, đã trả, còn lại theo hạn thanh toán</p>
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
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Mã HĐ, nhà cung cấp..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-primary-500" />
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
        <select v-model="bucket"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả tình trạng</option>
          <option value="Chưa đến hạn">Chưa đến hạn</option>
          <option value="1–30 ngày">1–30 ngày</option>
          <option value="31–60 ngày">31–60 ngày</option>
          <option value="61–90 ngày">61–90 ngày</option>
          <option value=">90 ngày">&gt;90 ngày</option>
          <option value="Đã thanh toán">Đã thanh toán</option>
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

      <!-- Aging buckets summary -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-xs text-gray-500 mb-1">Tổng còn lại</p>
          <p class="text-base font-bold text-gray-900">{{ fmt(summary.total_remaining) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-xs text-gray-500 mb-1">Chưa đến hạn</p>
          <p class="text-base font-bold text-blue-700">{{ fmt(summary.bucket_0) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-center">
          <p class="text-xs text-yellow-600 mb-1">1–30 ngày</p>
          <p class="text-base font-bold text-yellow-700">{{ fmt(summary.bucket_1_30) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-orange-200 bg-orange-50 p-4 text-center">
          <p class="text-xs text-orange-600 mb-1">31–60 ngày</p>
          <p class="text-base font-bold text-orange-700">{{ fmt(summary.bucket_31_60) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4 text-center">
          <p class="text-xs text-red-600 mb-1">61–90 ngày</p>
          <p class="text-base font-bold text-red-700">{{ fmt(summary.bucket_61_90) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-300 bg-red-100 p-4 text-center">
          <p class="text-xs text-red-700 mb-1">&gt;90 ngày</p>
          <p class="text-base font-bold text-red-800">{{ fmt(summary.bucket_90_plus) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto transition-opacity" :class="{ 'opacity-60': isLoading }">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Số HĐ/CT</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày CT</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Hạn TT</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Đã trả (TM)</th>
              <th class="text-right px-4 py-3 font-semibold text-blue-600">Trả trước đối trừ</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Tình trạng</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows.data" :key="row.source_type + '-' + row.id" class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <template v-if="row.source_type === 'purchase_invoice'">
                  <Link :href="route('purchasing.purchase-invoices.show', row.id)"
                    class="font-mono font-medium text-primary-700 hover:underline">{{ row.code }}</Link>
                </template>
                <template v-else>
                  <span class="font-mono font-medium text-gray-700">{{ row.code }}</span>
                  <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                    Đầu kỳ
                  </span>
                </template>
              </td>
              <td class="px-4 py-3 text-gray-800">{{ row.partner_name }}</td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ fmtDate(row.doc_date) }}</td>
              <td class="px-4 py-3 text-xs" :class="row.remaining > 0 && row.days_overdue > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                {{ row.due_date ? fmtDate(row.due_date) : '—' }}
              </td>
              <td class="px-4 py-3 text-right text-gray-800">{{ fmt(row.total) }}</td>
              <td class="px-4 py-3 text-right text-green-700">{{ fmt(row.paid) }}</td>
              <td class="px-4 py-3 text-right text-blue-600">{{ row.advance_offset > 0 ? fmt(row.advance_offset) : '—' }}</td>
              <td class="px-4 py-3 text-right font-semibold" :class="row.remaining > 0 ? 'text-red-700' : 'text-gray-400'">
                {{ fmt(row.remaining) }}
              </td>
              <td class="px-4 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="bucketClass(row.bucket)">
                  {{ row.bucket }}
                </span>
              </td>
            </tr>
            <tr v-if="!rows.data?.length">
              <td colspan="9" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.data?.length" class="bg-gray-50 border-t-2 border-gray-300">
            <tr>
              <td colspan="4" class="px-4 py-3 font-semibold text-gray-700">Tổng cộng</td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ fmt(summary.total_invoiced) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-green-700">{{ fmt(summary.total_paid) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-blue-600">{{ fmt(summary.total_advance_offset) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-red-700">{{ fmt(summary.total_remaining) }}</td>
              <td></td>
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
const bucket   = ref(props.filters?.bucket    ?? '');

const hasFilters = computed(() => search.value || dateFrom.value || dateTo.value || bucket.value);

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  if (search.value)   params.set('search',    search.value);
  if (dateFrom.value) params.set('date_from', dateFrom.value);
  if (dateTo.value)   params.set('date_to',   dateTo.value);
  if (bucket.value)   params.set('bucket',    bucket.value);
  const qs = params.toString();
  return route('reports.ap.aging.export') + (qs ? '?' + qs : '');
});

function applyFilters() {
  router.get(route('reports.ap.aging'), {
    search:    search.value    || undefined,
    date_from: dateFrom.value  || undefined,
    date_to:   dateTo.value    || undefined,
    bucket:    bucket.value    || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = dateFrom.value = dateTo.value = bucket.value = '';
  applyFilters();
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('vi-VN');
}

function bucketClass(b) {
  const map = {
    'Đã thanh toán': 'bg-green-100 text-green-700',
    'Chưa đến hạn':  'bg-blue-100 text-blue-700',
    '1–30 ngày':     'bg-yellow-100 text-yellow-700',
    '31–60 ngày':    'bg-orange-100 text-orange-700',
    '61–90 ngày':    'bg-red-100 text-red-700',
    '>90 ngày':      'bg-red-200 text-red-800',
  };
  return map[b] ?? 'bg-gray-100 text-gray-600';
}
</script>
