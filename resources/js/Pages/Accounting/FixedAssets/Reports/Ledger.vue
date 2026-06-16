<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-slate-900">Sổ tài sản cố định</h1>
        <div class="flex gap-2">
          <select v-model="category_id" @change="applyFilters" class="erp-input w-48">
            <option value="">Tất cả nhóm</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <select v-model="status" @change="applyFilters" class="erp-input w-40">
            <option value="">Tất cả trạng thái</option>
            <option value="active">Đang sử dụng</option>
            <option value="pending_use">Chờ sử dụng</option>
            <option value="fully_depreciated">Hết KH</option>
            <option value="disposed">Đã thanh lý</option>
          </select>
        </div>
      </div>

      <!-- Totals -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold">Tổng nguyên giá</p>
          <p class="text-2xl font-bold text-slate-900 mt-1">{{ fmt(totals.original_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold">Tổng hao mòn LK</p>
          <p class="text-2xl font-bold text-red-600 mt-1">{{ fmt(totals.accumulated_dep) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold">Tổng giá trị còn lại</p>
          <p class="text-2xl font-bold text-indigo-700 mt-1">{{ fmt(totals.net_book_value) }}</p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
              <tr>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Mã</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Tên TSCĐ</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Nhóm</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Bộ phận</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Ngày sử dụng</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Nguyên giá</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Hao mòn LK</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Còn lại</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">TK NG</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="a in assets" :key="a.id">
                <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ a.code }}</td>
                <td class="px-4 py-2 font-medium text-slate-900">
                  <Link :href="route('accounting.fixed-assets.show', a.id)" class="hover:text-indigo-600">{{ a.name }}</Link>
                </td>
                <td class="px-4 py-2 text-slate-500 text-xs">{{ a.category_name }}</td>
                <td class="px-4 py-2 text-slate-500 text-xs">{{ a.department || '—' }}</td>
                <td class="px-4 py-2 text-slate-600 text-xs">{{ a.placed_in_service_date || '—' }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmt(a.acquisition_cost) }}</td>
                <td class="px-4 py-2 text-right font-mono text-slate-500">{{ fmt(a.accumulated_depreciation) }}</td>
                <td class="px-4 py-2 text-right font-mono font-semibold text-indigo-700">{{ fmt(a.net_book_value) }}</td>
                <td class="px-4 py-2 font-mono text-xs text-indigo-700">{{ a.original_cost_account_code }}</td>
                <td class="px-4 py-2">
                  <span class="erp-badge erp-badge-gray text-xs">{{ a.status_label }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ assets: Array, categories: Array, filters: Object, totals: Object });
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const category_id = ref(props.filters?.category_id || '');
const status = ref(props.filters?.status || '');

function applyFilters() {
  router.get(route('accounting.fixed-assets.reports.ledger'), { category_id: category_id.value, status: status.value }, { preserveState: true, replace: true });
}
</script>
