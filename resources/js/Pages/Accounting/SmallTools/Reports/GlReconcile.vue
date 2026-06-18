<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Đối soát GL — Tài khoản 1531 / 2422</h1>
        <div class="flex gap-2 items-center">
          <input v-model="asOf" type="date" class="erp-input w-44" />
          <button @click="apply" class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm">Đối soát</button>
        </div>
      </div>

      <!-- Summary -->
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Số dư TK 1531 (GL)</div>
          <div class="text-xl font-bold font-mono" :class="reconcile.gl_1531 < 0 ? 'text-red-600' : 'text-gray-900'">
            {{ formatVnd(reconcile.gl_1531) }}
          </div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Sổ CCDC in_stock (chờ xuất)</div>
          <div class="text-xl font-bold font-mono">{{ formatVnd(reconcile.in_stock_total) }}</div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center"
          :class="reconcile.diff_1531 !== 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'">
          <div class="text-xs text-gray-500 mb-1">Chênh lệch 1531</div>
          <div class="text-xl font-bold font-mono" :class="reconcile.diff_1531 !== 0 ? 'text-red-700' : 'text-green-700'">
            {{ formatVnd(reconcile.diff_1531) }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Số dư TK 2422 (GL)</div>
          <div class="text-xl font-bold font-mono">{{ formatVnd(reconcile.gl_2422) }}</div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center">
          <div class="text-xs text-gray-500 mb-1">Sổ CCDC chờ phân bổ (2422)</div>
          <div class="text-xl font-bold font-mono">{{ formatVnd(reconcile.allocating_remaining) }}</div>
        </div>
        <div class="bg-white rounded-xl border p-4 text-center"
          :class="reconcile.diff_2422 !== 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'">
          <div class="text-xs text-gray-500 mb-1">Chênh lệch 2422</div>
          <div class="text-xl font-bold font-mono" :class="reconcile.diff_2422 !== 0 ? 'text-red-700' : 'text-green-700'">
            {{ formatVnd(reconcile.diff_2422) }}
          </div>
        </div>
      </div>

      <!-- Warnings -->
      <div v-if="reconcile.warnings.length" class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5">
        <h2 class="text-sm font-semibold text-red-800 mb-2">Cảnh báo cần xử lý ({{ reconcile.warnings.length }})</h2>
        <ul class="space-y-1">
          <li v-for="w in reconcile.warnings" :key="w.code" class="text-sm text-red-700">
            <span class="font-mono font-semibold">{{ w.code }}</span> — {{ w.name }}:
            <span class="ml-1 text-xs">{{ w.message }}</span>
          </li>
        </ul>
      </div>

      <div v-else class="bg-green-50 border border-green-200 rounded-xl p-4 mb-5 text-green-700 text-sm">
        Không có cảnh báo. Sổ CCDC khớp với GL.
      </div>

      <!-- Detail per tool -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-sm">Chi tiết từng CCDC</div>
        <table class="w-full text-xs">
          <thead class="bg-gray-50 border-b text-gray-500">
            <tr>
              <th class="px-3 py-2 text-left">Mã CCDC</th>
              <th class="px-3 py-2 text-left">Tên</th>
              <th class="px-3 py-2 text-center">TT</th>
              <th class="px-3 py-2 text-right">Nguyên giá</th>
              <th class="px-3 py-2 text-right">Đã PB</th>
              <th class="px-3 py-2 text-right">Còn 2422</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="t in reconcile.tools" :key="t.id" class="hover:bg-gray-50">
              <td class="px-3 py-2 font-mono font-semibold text-primary-700">{{ t.code }}</td>
              <td class="px-3 py-2">{{ t.name }}</td>
              <td class="px-3 py-2 text-center">
                <span class="px-1.5 py-0.5 rounded text-xs" :class="statusClass(t.status)">{{ statusLabel(t.status) }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono">{{ formatVnd(t.original_cost) }}</td>
              <td class="px-3 py-2 text-right font-mono text-blue-700">{{ formatVnd(t.total_allocated) }}</td>
              <td class="px-3 py-2 text-right font-mono text-orange-600">
                {{ t.status === 'allocating' ? formatVnd(t.total_remaining) : '—' }}
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
const props = defineProps({ reconcile: Object, asOf: String });

const asOf = ref(props.asOf || new Date().toISOString().slice(0, 10));

function apply() {
  router.get(route('accounting.small-tools.reports.gl-reconcile'), { as_of: asOf.value }, { preserveState: true });
}

function statusLabel(s) {
  return { in_stock: 'Kho', in_use: 'Dùng', allocating: 'PB', fully_allocated: 'Xong', broken: 'Hỏng', lost: 'Mất', disposed: 'XL' }[s] ?? s;
}
function statusClass(s) {
  return { in_stock: 'bg-blue-100 text-blue-700', in_use: 'bg-green-100 text-green-700', allocating: 'bg-yellow-100 text-yellow-700', fully_allocated: 'bg-gray-100 text-gray-500', broken: 'bg-red-100 text-red-700', lost: 'bg-red-200 text-red-800', disposed: 'bg-gray-200 text-gray-600' }[s] || 'bg-gray-100 text-gray-500';
}
</script>
