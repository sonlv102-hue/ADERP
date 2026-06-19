<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Tài sản cố định</h1>
          <p class="text-sm text-slate-500 mt-1">Quản lý TSCĐ theo TT133 / TT45</p>
        </div>
        <div class="flex items-center gap-2">
          <Link :href="route('accounting.fixed-assets.depreciation.run-page')" class="erp-btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 11h.01M12 11h.01M15 11h.01M4 19h16a2 2 0 002-2V7a2 2 0 00-2-2H4a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            Tính khấu hao
          </Link>
          <Link v-if="can('accounting.manage')" :href="route('accounting.fixed-assets.create')" class="erp-btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm TSCĐ
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <input v-model="search" @input.stop @keydown.enter="applyFilters" placeholder="Mã, tên tài sản..."
          class="erp-input w-56" />
        <select v-model="category_id" @change="applyFilters" class="erp-input w-48">
          <option value="">Tất cả nhóm</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
        <select v-model="status" @change="applyFilters" class="erp-input w-44">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <input v-model="department" @keydown.enter="applyFilters" placeholder="Bộ phận..." class="erp-input w-44" />
        <button @click="applyFilters" class="erp-btn-secondary">Tìm</button>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Mã</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tên TSCĐ</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Nhóm</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Bộ phận</th>
              <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Nguyên giá</th>
              <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Hao mòn LK</th>
              <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Giá trị còn lại</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Trạng thái</th>
              <th class="px-4 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in assets.data" :key="a.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ a.code }}</td>
              <td class="px-4 py-3 font-medium text-slate-900">
                <Link :href="route('accounting.fixed-assets.show', a.id)" class="hover:text-indigo-600">
                  {{ a.name }}
                </Link>
              </td>
              <td class="px-4 py-3 text-slate-600 text-xs">{{ a.category_name }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">{{ a.department || '—' }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-700">{{ fmt(a.acquisition_cost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-500">{{ fmt(a.accumulated_depreciation) }}</td>
              <td class="px-4 py-3 text-right font-mono font-semibold" :class="a.net_book_value > 0 ? 'text-slate-900' : 'text-slate-400'">{{ fmt(a.net_book_value) }}</td>
              <td class="px-4 py-3">
                <span class="erp-badge" :class="badgeClass(a.status_color)">{{ a.status_label }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <Link :href="route('accounting.fixed-assets.show', a.id)" class="erp-btn-icon" title="Chi tiết">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                </Link>
              </td>
            </tr>
            <tr v-if="assets.data.length === 0">
              <td colspan="9" class="px-5 py-10 text-center text-slate-400">Không có tài sản nào.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex justify-end">
        <Pagination :links="assets.links" />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  assets: Object,
  categories: Array,
  statuses: Array,
  filters: Object,
});

const { can } = usePermission();
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const search      = ref(props.filters?.search || '');
const category_id = ref(props.filters?.category_id || '');
const status      = ref(props.filters?.status || '');
const department  = ref(props.filters?.department || '');

function applyFilters() {
  router.get(route('accounting.fixed-assets.index'), {
    search: search.value, category_id: category_id.value,
    status: status.value, department: department.value,
  }, { preserveState: true, replace: true });
}

function badgeClass(color) {
  const map = { green: 'erp-badge-green', yellow: 'erp-badge-yellow', orange: 'erp-badge-orange', red: 'erp-badge-red', gray: 'erp-badge-gray' };
  return map[color] || 'erp-badge-gray';
}
</script>
