<template>
  <AppLayout title="Sổ chi tiết công nợ phải trả">
    <div class="max-w-5xl mx-auto">
      <h1 class="text-2xl font-bold text-gray-900 mb-6">Sổ chi tiết công nợ phải trả (TK 331)</h1>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-5 flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <label class="block text-xs font-medium text-gray-600 mb-1">Nhà cung cấp</label>
          <select v-model="filters.supplier_id" class="form-input text-sm">
            <option value="">-- Chọn nhà cung cấp --</option>
            <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
              {{ s.code }} — {{ s.name }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
          <input v-model="filters.date_from" type="date" class="form-input text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
          <input v-model="filters.date_to" type="date" class="form-input text-sm" />
        </div>
        <div class="flex items-end gap-2">
          <button @click="applyFilters" class="btn-primary text-sm">Xem</button>
          <a v-if="filters.supplier_id" :href="exportUrl" target="_blank"
            class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg font-medium flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Xuất Excel
          </a>
        </div>
      </div>

      <!-- No supplier selected -->
      <div v-if="!filters.supplier_id" class="bg-white rounded-xl shadow-sm p-12 text-center text-gray-400">
        Chọn nhà cung cấp để xem sổ chi tiết công nợ phải trả
      </div>

      <template v-else>
        <!-- Summary cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
          <!-- Opening Balances Card -->
          <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Dư đầu kỳ</p>
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between text-gray-600">
                <span>Phải trả (TK 331):</span>
                <span class="font-medium text-gray-800">{{ formatVnd(opening_bal_331) }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Ứng trước (TK 331UT):</span>
                <span class="font-medium text-gray-800">{{ formatVnd(opening_bal_331ut) }}</span>
              </div>
              <div class="border-t border-dashed border-gray-200 my-1"></div>
              <div class="flex justify-between font-semibold text-primary-700">
                <span>Công nợ ròng (Có):</span>
                <span>{{ formatVnd(opening_bal_net) }}</span>
              </div>
            </div>
          </div>

          <!-- Movements Card -->
          <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Phát sinh trong kỳ</p>
            <div class="space-y-1 text-xs">
              <div class="grid grid-cols-3 gap-1 text-gray-600 font-medium">
                <span>Tài khoản</span>
                <span class="text-right">Nợ</span>
                <span class="text-right">Có</span>
              </div>
              <div class="border-b border-gray-100 my-0.5"></div>
              <div class="grid grid-cols-3 gap-1 text-gray-700">
                <span>331 thường:</span>
                <span class="text-right text-blue-600">{{ formatVnd(total_debit_331) }}</span>
                <span class="text-right text-green-600">{{ formatVnd(total_credit_331) }}</span>
              </div>
              <div class="grid grid-cols-3 gap-1 text-gray-700">
                <span>331UT ứng:</span>
                <span class="text-right text-blue-600">{{ formatVnd(total_debit_331ut) }}</span>
                <span class="text-right text-green-600">{{ formatVnd(total_credit_331ut) }}</span>
              </div>
            </div>
          </div>

          <!-- Closing Balances Card -->
          <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Dư cuối kỳ</p>
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between text-gray-600">
                <span>Phải trả (TK 331):</span>
                <span class="font-medium text-gray-800">{{ formatVnd(closing_bal_331) }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Ứng trước (TK 331UT):</span>
                <span class="font-medium text-gray-800">{{ formatVnd(closing_bal_331ut) }}</span>
              </div>
              <div class="border-t border-dashed border-gray-200 my-1"></div>
              <div class="flex justify-between font-bold text-green-600">
                <span>Công nợ ròng (Có):</span>
                <span>{{ formatVnd(closing_bal_net) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Detail table -->
        <div class="bg-white rounded-xl shadow-sm overflow-x-auto border border-gray-200">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-gray-600 uppercase text-[10px] tracking-wider border-b border-gray-200">
              <tr>
                <th class="px-4 py-3 text-left w-24">Ngày</th>
                <th class="px-4 py-3 text-left w-28">Số CT</th>
                <th class="px-4 py-3 text-left w-24">Tài khoản</th>
                <th class="px-4 py-3 text-left">Diễn giải</th>
                <th class="px-4 py-3 text-right w-28">Nợ</th>
                <th class="px-4 py-3 text-right w-28">Có</th>
                <th class="px-4 py-3 text-right w-32">Số dư (331)</th>
                <th class="px-4 py-3 text-right w-32">Ứng trước (331UT)</th>
                <th class="px-4 py-3 text-right w-32 text-primary-700">Công nợ ròng</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr class="bg-green-50 font-medium">
                <td class="px-4 py-2 text-gray-500">—</td>
                <td class="px-4 py-2"></td>
                <td class="px-4 py-2 text-gray-400">—</td>
                <td class="px-4 py-2 text-green-800">Số dư đầu kỳ</td>
                <td class="px-4 py-2"></td>
                <td class="px-4 py-2"></td>
                <td class="px-4 py-2 text-right text-gray-800">{{ formatVnd(opening_bal_331) }}</td>
                <td class="px-4 py-2 text-right text-gray-800">{{ formatVnd(opening_bal_331ut) }}</td>
                <td class="px-4 py-2 text-right font-bold text-primary-800">{{ formatVnd(opening_bal_net) }}</td>
              </tr>
              <tr v-for="(row, i) in rows" :key="i" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-500">{{ row.date }}</td>
                <td class="px-4 py-2 font-mono text-primary-600">{{ row.ref }}</td>
                <td class="px-4 py-2 font-semibold text-gray-700">{{ row.account_code }}</td>
                <td class="px-4 py-2 text-gray-700">{{ row.description || '—' }}</td>
                <td class="px-4 py-2 text-right text-blue-700 font-medium">{{ row.debit > 0 ? formatVnd(row.debit) : '' }}</td>
                <td class="px-4 py-2 text-right text-green-700 font-medium">{{ row.credit > 0 ? formatVnd(row.credit) : '' }}</td>
                <td class="px-4 py-2 text-right text-gray-600">{{ formatVnd(row.balance_331) }}</td>
                <td class="px-4 py-2 text-right text-gray-600">{{ formatVnd(row.balance_331ut) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-gray-900">{{ formatVnd(row.balance_net) }}</td>
              </tr>
              <tr class="bg-gray-50 font-bold border-t border-gray-200">
                <td colspan="4" class="px-4 py-2.5 text-gray-700 uppercase tracking-wider text-[10px]">Cộng phát sinh trong kỳ</td>
                <td class="px-4 py-2.5 text-right text-blue-700">{{ formatVnd(total_debit_331 + total_debit_331ut) }}</td>
                <td class="px-4 py-2.5 text-right text-green-700">{{ formatVnd(total_credit_331 + total_credit_331ut) }}</td>
                <td class="px-4 py-2.5 text-right text-gray-700">{{ formatVnd(closing_bal_331) }}</td>
                <td class="px-4 py-2.5 text-right text-gray-700">{{ formatVnd(closing_bal_331ut) }}</td>
                <td class="px-4 py-2.5 text-right text-green-700 font-extrabold">{{ formatVnd(closing_bal_net) }}</td>
              </tr>
              <tr v-if="rows.length === 0" class="border-t border-gray-200">
                <td colspan="9" class="px-4 py-8 text-center text-gray-400">Không có phát sinh trong kỳ</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  suppliers:          Array,
  rows:               Array,
  opening_bal_331:    Number,
  opening_bal_331ut:  Number,
  opening_bal_net:    Number,
  total_debit_331:    Number,
  total_credit_331:   Number,
  total_debit_331ut:  Number,
  total_credit_331ut: Number,
  closing_bal_331:    Number,
  closing_bal_331ut:  Number,
  closing_bal_net:    Number,
  filters:            Object,
});

const filters = ref({
  supplier_id: props.filters.supplier_id ?? '',
  date_from:   props.filters.date_from   ?? '',
  date_to:     props.filters.date_to     ?? '',
});

function applyFilters() {
  router.get(route('reports.ap.detail'), filters.value, { preserveState: true });
}

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  Object.entries(filters.value).forEach(([k, v]) => { if (v) p.set(k, v); });
  return route('reports.ap.detail.export') + '?' + p.toString();
});

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
