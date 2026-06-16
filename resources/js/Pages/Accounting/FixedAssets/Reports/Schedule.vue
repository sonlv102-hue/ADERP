<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-slate-900">Bảng tính và phân bổ khấu hao</h1>
        <div class="flex gap-2 items-center">
          <input v-model="period" type="month" @change="applyFilters" class="erp-input w-40" />
        </div>
      </div>

      <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 flex gap-6 text-sm">
        <div><span class="text-indigo-600 font-semibold">Kỳ:</span> {{ period }}</div>
        <div><span class="text-indigo-600 font-semibold">Số tài sản:</span> {{ rows.length }}</div>
        <div><span class="text-indigo-600 font-semibold">Tổng KH:</span> <strong>{{ fmt(total) }}</strong></div>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Mã TSCĐ</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Tên TSCĐ</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Bộ phận</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">TK CP</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">TK HM</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">KH tháng</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">HM LK sau KH</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Còn lại</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.asset_code">
              <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ r.asset_code }}</td>
              <td class="px-4 py-2 font-medium text-slate-900">{{ r.asset_name }}</td>
              <td class="px-4 py-2 text-slate-500 text-xs">{{ r.department || '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-indigo-700">{{ r.expense_account }}</td>
              <td class="px-4 py-2 font-mono text-xs text-indigo-700">{{ r.dep_account }}</td>
              <td class="px-4 py-2 text-right font-mono font-semibold">{{ fmt(r.amount) }}</td>
              <td class="px-4 py-2 text-right font-mono text-slate-500">{{ fmt(r.accumulated_after) }}</td>
              <td class="px-4 py-2 text-right font-mono text-indigo-700">{{ fmt(r.net_book_value) }}</td>
              <td class="px-4 py-2">
                <span class="erp-badge" :class="r.status === 'posted' ? 'erp-badge-green' : 'erp-badge-yellow'">
                  {{ r.status === 'posted' ? 'Đã GS' : 'Nháp' }}
                </span>
              </td>
            </tr>
            <tr v-if="rows.length === 0">
              <td colspan="9" class="px-4 py-10 text-center text-slate-400">Không có dữ liệu khấu hao kỳ {{ period }}.</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.length > 0" class="bg-slate-50 border-t border-slate-200">
            <tr>
              <td colspan="5" class="px-4 py-3 font-semibold text-slate-700">Tổng cộng</td>
              <td class="px-4 py-3 text-right font-mono font-bold text-slate-900">{{ fmt(total) }}</td>
              <td colspan="3" />
            </tr>
          </tfoot>
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

const props = defineProps({ period: String, rows: Array, total: Number });
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const period = ref(props.period);

function applyFilters() {
  router.get(route('accounting.fixed-assets.reports.schedule'), { period: period.value }, { preserveState: true, replace: true });
}
</script>
