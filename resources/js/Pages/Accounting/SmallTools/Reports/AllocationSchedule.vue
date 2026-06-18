<template>
  <AppLayout>
    <div class="max-w-6xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Bảng phân bổ CCDC</h1>
        <input v-model="toolFilter" type="text" placeholder="Tìm mã/tên CCDC..."
          class="erp-input w-52" @keyup.enter="apply" />
      </div>

      <div v-for="tool in schedule" :key="tool.id" class="bg-white rounded-xl border border-gray-200 mb-5">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <span class="font-mono text-sm font-bold text-primary-700 mr-2">{{ tool.code }}</span>
            <span class="font-medium">{{ tool.name }}</span>
            <span class="ml-3 text-xs text-gray-500">{{ tool.department || '' }}</span>
          </div>
          <div class="text-sm text-gray-600 text-right">
            Nguyên giá: <span class="font-mono font-semibold">{{ formatVnd(tool.original_cost) }}</span>
            · TK Nợ: <span class="font-mono">{{ tool.expense_account_code }}</span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-gray-50 border-b text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left">Kỳ</th>
                <th class="px-3 py-2 text-right">Số tiền PB</th>
                <th class="px-3 py-2 text-right">Lũy kế</th>
                <th class="px-3 py-2 text-right">Còn lại</th>
                <th class="px-3 py-2 text-center">Trạng thái</th>
                <th class="px-3 py-2 text-center">BT</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in tool.allocations" :key="row.id"
                :class="row.period === currentPeriod ? 'bg-yellow-50' : 'hover:bg-gray-50'">
                <td class="px-3 py-2 font-mono">{{ row.period }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatVnd(row.amount) }}</td>
                <td class="px-3 py-2 text-right font-mono text-blue-700">{{ formatVnd(row.accumulated) }}</td>
                <td class="px-3 py-2 text-right font-mono text-orange-600">{{ formatVnd(row.remaining) }}</td>
                <td class="px-3 py-2 text-center">
                  <span :class="allocationStatusClass(row.status)" class="px-1.5 py-0.5 rounded text-xs">
                    {{ allocationStatusLabel(row.status) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center">
                  <Link v-if="row.journal_entry_id"
                    :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                    class="text-primary-600 hover:underline">#{{ row.journal_entry_id }}</Link>
                  <span v-else class="text-gray-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="!schedule.length" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-400">
        Không có CCDC nào đang phân bổ.
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();
const props = defineProps({ schedule: Array, currentPeriod: String, filters: Object });

const toolFilter = ref(props.filters?.tool || '');

function apply() {
  router.get(route('accounting.small-tools.reports.allocation-schedule'), { tool: toolFilter.value }, { preserveState: true });
}

function allocationStatusLabel(s) {
  return { pending: 'Chờ', posted: 'Đã hạch toán', reversed: 'Đã đảo', cancelled: 'Đã hủy' }[s] ?? s;
}
function allocationStatusClass(s) {
  return { pending: 'bg-yellow-100 text-yellow-700', posted: 'bg-green-100 text-green-700', reversed: 'bg-blue-100 text-blue-700', cancelled: 'bg-gray-100 text-gray-500' }[s];
}
</script>
