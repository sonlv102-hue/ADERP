<template>
  <AppLayout>
    <div class="max-w-6xl space-y-5">
      <h1 class="text-2xl font-bold text-gray-900">Sổ quỹ</h1>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Quỹ</label>
          <select v-model="form.fund_id"
            class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none min-w-48">
            <option value="">-- Chọn quỹ --</option>
            <option v-for="f in funds" :key="f.id" :value="f.id">
              {{ f.name }} ({{ f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
          <input v-model="form.date_from" type="date"
            class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
          <input v-model="form.date_to" type="date"
            class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <button @click="applyFilters"
          class="px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700">
          Xem sổ
        </button>
      </div>

      <!-- Balance summary -->
      <div v-if="fund" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Số dư đầu kỳ</p>
          <p class="text-lg font-bold text-gray-800">{{ formatVnd(balances.opening) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 p-4 text-center">
          <p class="text-xs font-semibold text-green-600 uppercase tracking-wider mb-1">Tổng thu</p>
          <p class="text-lg font-bold text-green-700">{{ formatVnd(balances.total_debit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 p-4 text-center">
          <p class="text-xs font-semibold text-red-600 uppercase tracking-wider mb-1">Tổng chi</p>
          <p class="text-lg font-bold text-red-700">{{ formatVnd(balances.total_credit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Số dư cuối kỳ</p>
          <p class="text-lg font-bold" :class="balances.closing >= 0 ? 'text-gray-900' : 'text-red-600'">
            {{ formatVnd(balances.closing) }}
          </p>
        </div>
      </div>

      <!-- Ledger table -->
      <div v-if="fund" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="font-semibold text-gray-800">Sổ quỹ: {{ fund.name }}</h2>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Chứng từ</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Đối tác</th>
              <th class="text-right px-4 py-3 font-semibold text-green-600">Thu</th>
              <th class="text-right px-4 py-3 font-semibold text-red-600">Chi</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Số dư</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <!-- Opening row -->
            <tr class="bg-blue-50">
              <td colspan="6" class="px-4 py-2 text-xs font-semibold text-blue-700">Số dư đầu kỳ</td>
              <td class="px-4 py-2 text-right font-semibold text-blue-700">{{ formatVnd(balances.opening) }}</td>
            </tr>
            <tr v-for="(e, i) in entries" :key="i" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ e.date }}</td>
              <td class="px-4 py-2 font-mono text-gray-700">{{ e.ref }}</td>
              <td class="px-4 py-2 text-gray-700">{{ e.description }}</td>
              <td class="px-4 py-2 text-gray-500">{{ e.counterparty ?? '—' }}</td>
              <td class="px-4 py-2 text-right text-green-700">{{ e.debit > 0 ? formatVnd(e.debit) : '' }}</td>
              <td class="px-4 py-2 text-right text-red-700">{{ e.credit > 0 ? formatVnd(e.credit) : '' }}</td>
              <td class="px-4 py-2 text-right font-medium" :class="e.balance >= 0 ? 'text-gray-900' : 'text-red-600'">
                {{ formatVnd(e.balance) }}
              </td>
            </tr>
            <tr v-if="!entries.length">
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">Không có phát sinh trong kỳ</td>
            </tr>
            <!-- Closing row -->
            <tr v-if="entries.length" class="bg-gray-50 border-t-2 border-gray-300">
              <td colspan="4" class="px-4 py-2 text-right font-semibold text-gray-700">Cộng phát sinh:</td>
              <td class="px-4 py-2 text-right font-semibold text-green-700">{{ formatVnd(balances.total_debit) }}</td>
              <td class="px-4 py-2 text-right font-semibold text-red-700">{{ formatVnd(balances.total_credit) }}</td>
              <td class="px-4 py-2 text-right font-bold text-gray-900">{{ formatVnd(balances.closing) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="bg-gray-50 rounded-xl border border-gray-200 p-8 text-center text-gray-400 text-sm">
        Chọn quỹ và khoảng thời gian để xem sổ quỹ.
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  funds:    Array,
  fund:     Object,
  entries:  Array,
  balances: Object,
  filters:  Object,
});
const { formatVnd } = useCurrency();

const form = reactive({
  fund_id:   props.filters.fund_id   ?? '',
  date_from: props.filters.date_from ?? '',
  date_to:   props.filters.date_to   ?? '',
});

function applyFilters() {
  router.get(route('reports.fund-ledger.index'), form, { preserveState: true });
}
</script>
