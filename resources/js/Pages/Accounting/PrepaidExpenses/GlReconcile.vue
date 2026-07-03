<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Đối soát GL — Chi phí trả trước (142/242)</h1>
        <div class="flex gap-2 items-center">
          <input v-model="asOf" type="date" class="erp-input w-44" />
          <button @click="apply" class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm">Đối soát</button>
        </div>
      </div>

      <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-5">
        Số dư được cộng theo đại số (có dấu) — không dùng giá trị tuyệt đối, để phản ánh đúng các
        khoản chi phí trả trước bị điều chỉnh âm (đã phân bổ vượt ở hệ thống cũ).
      </p>

      <div v-for="row in byAccount" :key="row.account" class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Số dư TK {{ row.account }} (GL)</div>
          <div class="text-xl font-bold font-mono">{{ formatVnd(row.gl_balance) }}</div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Sổ chi phí trả trước còn lại</div>
          <div class="text-xl font-bold font-mono">{{ formatVnd(row.book_remaining) }}</div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center"
          :class="row.diff !== 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'">
          <div class="text-xs text-gray-500 mb-1">Chênh lệch TK {{ row.account }}</div>
          <div class="text-xl font-bold font-mono" :class="row.diff !== 0 ? 'text-red-700' : 'text-green-700'">
            {{ formatVnd(row.diff) }}
          </div>
        </div>
      </div>

      <div v-if="!byAccount.length" class="bg-white rounded-xl border p-8 text-center text-gray-400 mb-5">
        Chưa có chi phí trả trước nào đang theo dõi.
      </div>

      <!-- Detail -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-3 border-b font-semibold text-sm">Chi tiết từng khoản</div>
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 border-b text-gray-500">
            <tr>
              <th class="px-3 py-2 text-left">Mã</th>
              <th class="px-3 py-2 text-left">Diễn giải</th>
              <th class="px-3 py-2 text-center">TK</th>
              <th class="px-3 py-2 text-right">Ban đầu</th>
              <th class="px-3 py-2 text-right">Đã PB</th>
              <th class="px-3 py-2 text-right">Còn lại</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="e in expenses" :key="e.code" class="hover:bg-gray-50">
              <td class="px-3 py-2 font-mono font-semibold text-primary-700">{{ e.code }}</td>
              <td class="px-3 py-2">{{ e.description }}</td>
              <td class="px-3 py-2 text-center font-mono">{{ e.account_code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatVnd(e.total_amount) }}</td>
              <td class="px-3 py-2 text-right font-mono text-green-700">{{ formatVnd(e.amortized_amount) }}</td>
              <td class="px-3 py-2 text-right font-mono" :class="e.remaining_amount < 0 ? 'text-orange-600' : 'text-blue-700'">
                {{ formatVnd(e.remaining_amount) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();
const props = defineProps({ byAccount: Array, expenses: Array, asOf: String });

const asOf = ref(props.asOf || new Date().toISOString().slice(0, 10));

function apply() {
  router.get(route('accounting.prepaid-expenses.reports.gl-reconcile'), { as_of: asOf.value }, { preserveState: true });
}
</script>
