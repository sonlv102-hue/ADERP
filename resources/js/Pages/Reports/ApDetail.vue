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
        <div class="flex items-end">
          <button @click="applyFilters" class="btn-primary text-sm">Xem</button>
        </div>
      </div>

      <!-- No supplier selected -->
      <div v-if="!filters.supplier_id" class="bg-white rounded-xl shadow-sm p-12 text-center text-gray-400">
        Chọn nhà cung cấp để xem sổ chi tiết công nợ phải trả
      </div>

      <template v-else>
        <!-- Summary cards -->
        <div class="grid grid-cols-3 gap-4 mb-5">
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 mb-1">Dư đầu kỳ (Có)</p>
            <p class="text-lg font-bold" :class="opening_bal >= 0 ? 'text-green-600' : 'text-red-600'">
              {{ formatVnd(Math.abs(opening_bal)) }}
              <span class="text-xs font-normal text-gray-400 ml-1">{{ opening_bal >= 0 ? 'Có' : 'Nợ' }}</span>
            </p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 mb-1">Phát sinh trong kỳ</p>
            <p class="text-sm font-medium text-gray-700">
              Nợ: <span class="text-blue-600">{{ formatVnd(total_debit) }}</span>
            </p>
            <p class="text-sm font-medium text-gray-700">
              Có: <span class="text-green-600">{{ formatVnd(total_credit) }}</span>
            </p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 mb-1">Dư cuối kỳ (Có)</p>
            <p class="text-lg font-bold" :class="closing_bal >= 0 ? 'text-green-600' : 'text-red-600'">
              {{ formatVnd(Math.abs(closing_bal)) }}
              <span class="text-xs font-normal text-gray-400 ml-1">{{ closing_bal >= 0 ? 'Có' : 'Nợ' }}</span>
            </p>
          </div>
        </div>

        <!-- Detail table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
              <tr>
                <th class="px-4 py-3 text-left w-28">Ngày</th>
                <th class="px-4 py-3 text-left w-28">Số CT</th>
                <th class="px-4 py-3 text-left">Diễn giải</th>
                <th class="px-4 py-3 text-right w-32">Nợ</th>
                <th class="px-4 py-3 text-right w-32">Có</th>
                <th class="px-4 py-3 text-right w-32">Dư Có</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr class="bg-green-50 font-medium">
                <td class="px-4 py-2 text-gray-500 text-xs">—</td>
                <td class="px-4 py-2"></td>
                <td class="px-4 py-2 text-green-700">Số dư đầu kỳ</td>
                <td class="px-4 py-2 text-right">{{ opening_bal < 0 ? formatVnd(-opening_bal) : '' }}</td>
                <td class="px-4 py-2 text-right">{{ opening_bal > 0 ? formatVnd(opening_bal) : '' }}</td>
                <td class="px-4 py-2 text-right font-bold">{{ formatVnd(opening_bal) }}</td>
              </tr>
              <tr v-for="(row, i) in rows" :key="i" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-500 text-xs">{{ row.date }}</td>
                <td class="px-4 py-2 font-mono text-xs text-primary-600">{{ row.ref }}</td>
                <td class="px-4 py-2 text-gray-700">{{ row.description || '—' }}</td>
                <td class="px-4 py-2 text-right text-blue-700">{{ row.debit > 0 ? formatVnd(row.debit) : '' }}</td>
                <td class="px-4 py-2 text-right text-green-700">{{ row.credit > 0 ? formatVnd(row.credit) : '' }}</td>
                <td class="px-4 py-2 text-right font-medium" :class="row.balance >= 0 ? 'text-green-700' : 'text-red-600'">
                  {{ formatVnd(row.balance) }}
                </td>
              </tr>
              <tr class="bg-gray-50 font-semibold border-t-2 border-gray-300">
                <td colspan="3" class="px-4 py-2 text-gray-700">Cộng phát sinh</td>
                <td class="px-4 py-2 text-right text-blue-700">{{ formatVnd(total_debit) }}</td>
                <td class="px-4 py-2 text-right text-green-700">{{ formatVnd(total_credit) }}</td>
                <td class="px-4 py-2 text-right text-green-700">{{ formatVnd(closing_bal) }}</td>
              </tr>
              <tr v-if="rows.length === 0">
                <td colspan="6" class="px-4 py-8 text-center text-gray-400">Không có phát sinh trong kỳ</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  suppliers:    Array,
  rows:         Array,
  opening_bal:  Number,
  closing_bal:  Number,
  total_debit:  Number,
  total_credit: Number,
  filters:      Object,
});

const filters = ref({
  supplier_id: props.filters.supplier_id ?? '',
  date_from:   props.filters.date_from   ?? '',
  date_to:     props.filters.date_to     ?? '',
});

function applyFilters() {
  router.get(route('reports.ap.detail'), filters.value, { preserveState: true });
}

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
