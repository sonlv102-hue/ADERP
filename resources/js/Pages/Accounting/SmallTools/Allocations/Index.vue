<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Phân bổ CCDC hàng tháng</h1>
        <div class="flex gap-2 items-center">
          <input v-model="selectedPeriod" type="month" class="erp-input w-36"
            @change="router.get(route('accounting.small-tools.allocations.index', { period: selectedPeriod }), {}, { preserveState: true })" />
        </div>
      </div>

      <!-- Preview table -->
      <div v-if="preview.length" class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-5">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">
            Kỳ {{ period }} — {{ preview.length }} CCDC cần phân bổ
          </h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left">Mã CCDC</th>
              <th class="px-4 py-3 text-left">Tên</th>
              <th class="px-4 py-3 text-left">Bộ phận</th>
              <th class="px-4 py-3 text-left">TK Nợ</th>
              <th class="px-4 py-3 text-right">Phân bổ kỳ này</th>
              <th class="px-4 py-3 text-right">Còn lại sau</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in preview" :key="r.tool_id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs">
                <Link :href="route('accounting.small-tools.show', r.tool_id)" class="text-primary-600 hover:underline">
                  {{ r.tool_code }}
                </Link>
              </td>
              <td class="px-4 py-3">{{ r.tool_name }}</td>
              <td class="px-4 py-3 text-gray-500">{{ r.department || '—' }}</td>
              <td class="px-4 py-3 font-mono text-gray-500">{{ r.debit_account }}</td>
              <td class="px-4 py-3 text-right font-mono font-semibold">{{ formatVnd(r.amount) }}</td>
              <td class="px-4 py-3 text-right font-mono" :class="r.remaining <= 0 ? 'text-gray-400' : 'text-orange-600'">
                {{ formatVnd(r.remaining) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t font-semibold">
            <tr>
              <td colspan="4" class="px-4 py-3 text-right">Tổng phân bổ kỳ {{ period }}:</td>
              <td class="px-4 py-3 text-right font-mono text-primary-700">{{ formatVnd(totalAmount) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div v-else class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-400 mb-5">
        Không có CCDC nào cần phân bổ kỳ {{ period }}.
      </div>

      <!-- Run button -->
      <div v-if="can('ccdc.allocate') && preview.length" class="flex justify-end">
        <button @click="runAllocation"
          class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg font-medium text-sm">
          Chạy phân bổ kỳ {{ period }}
        </button>
      </div>

      <!-- Run form -->
      <form ref="runForm" :action="route('accounting.small-tools.allocations.run')" method="POST" class="hidden">
        <input type="hidden" name="_token" :value="csrf" />
        <input type="hidden" name="period" :value="period" />
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();
const props = defineProps({ period: String, preview: Array, totalAmount: Number });

const selectedPeriod = ref(props.period);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const runForm = ref(null);

function runAllocation() {
  if (confirm(`Chạy phân bổ CCDC kỳ ${props.period}? Thao tác sẽ tạo bút toán kế toán.`)) {
    router.post(route('accounting.small-tools.allocations.run'), { period: props.period });
  }
}
</script>
