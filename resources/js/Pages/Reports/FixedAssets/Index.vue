<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo Tài sản cố định</h1>
          <p class="text-sm text-gray-500 mt-0.5">Danh sách TSCĐ, khấu hao và giá trị còn lại (TK 211/214)</p>
        </div>
        <div class="flex gap-2">
          <a :href="route('admin.fixed-assets.create')" class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            + Thêm TSCĐ
          </a>
          <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Xuất Excel
          </a>
        </div>
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center flex-wrap">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Nhóm:</label>
          <select v-model="filterCategory" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">Tất cả</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Trạng thái:</label>
          <select v-model="filterStatus" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">Tất cả</option>
            <option value="active">Đang sử dụng</option>
            <option value="fully_depreciated">Đã KH hết</option>
            <option value="disposed">Đã thanh lý</option>
          </select>
        </div>
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Số lượng TSCĐ</p>
          <p class="text-lg font-bold text-gray-900">{{ summary.count }} <span class="text-sm font-normal text-gray-400">({{ summary.count_active }} đang dùng)</span></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Nguyên giá (TK 211)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600 mb-1">Hao mòn lũy kế (TK 214)</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_accumulated_dep) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Giá trị còn lại</p>
          <p class="text-lg font-bold text-green-700">{{ fmt(summary.total_net_book_value) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Mã</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Tên tài sản</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Nhóm</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Ngày mua</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Nguyên giá</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">TL KH<br/><span class="font-normal text-gray-400">%/năm</span></th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">KH năm</th>
                <th class="text-right px-4 py-2 font-semibold text-red-600 text-xs">Hao mòn LK</th>
                <th class="text-right px-4 py-2 font-semibold text-green-600 text-xs">Còn lại</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Vị trí</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Trạng thái</th>
                <th class="px-4 py-2 text-xs"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="fa in assets" :key="fa.id" class="hover:bg-gray-50">
                <td class="px-4 py-2 font-mono font-semibold text-gray-800 text-xs">{{ fa.code }}</td>
                <td class="px-4 py-2 text-gray-800 text-xs font-medium">{{ fa.name }}</td>
                <td class="px-4 py-2 text-gray-500 text-xs">{{ fa.category || '—' }}</td>
                <td class="px-4 py-2 text-gray-600 text-xs">{{ fa.acquisition_date }}</td>
                <td class="px-4 py-2 text-right text-gray-800 text-xs">{{ fmt(fa.acquisition_cost) }}</td>
                <td class="px-4 py-2 text-right text-gray-600 text-xs">{{ fa.depreciation_rate }}%</td>
                <td class="px-4 py-2 text-right text-gray-600 text-xs">{{ fmt(fa.annual_depreciation) }}</td>
                <td class="px-4 py-2 text-right text-red-700 text-xs">{{ fmt(fa.accumulated_depreciation) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-green-700 text-xs">{{ fmt(fa.net_book_value) }}</td>
                <td class="px-4 py-2 text-gray-500 text-xs">{{ fa.location || '—' }}</td>
                <td class="px-4 py-2 text-xs">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="{
                      'bg-green-100 text-green-800': fa.status === 'active',
                      'bg-gray-100 text-gray-600': fa.status === 'fully_depreciated',
                      'bg-red-100 text-red-600': fa.status === 'disposed',
                    }">
                    {{ statusLabel(fa.status) }}
                  </span>
                </td>
                <td class="px-4 py-2 text-xs">
                  <a :href="route('admin.fixed-assets.edit', fa.id)" class="text-primary-600 hover:underline">Sửa</a>
                </td>
              </tr>
              <tr v-if="assets.length === 0">
                <td colspan="12" class="px-4 py-8 text-center text-gray-400 text-sm">
                  Chưa có tài sản cố định. <a :href="route('admin.fixed-assets.create')" class="text-primary-600 underline">Thêm mới</a>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="assets.length > 0" class="bg-gray-50 border-t-2 border-gray-300">
              <tr>
                <td colspan="4" class="px-4 py-2 font-bold text-gray-700 text-xs">Tổng cộng</td>
                <td class="px-4 py-2 text-right font-bold text-gray-800 text-xs">{{ fmt(summary.total_cost) }}</td>
                <td class="px-4 py-2"></td>
                <td class="px-4 py-2 text-right font-bold text-gray-600 text-xs">{{ fmt(summary.total_annual_dep) }}</td>
                <td class="px-4 py-2 text-right font-bold text-red-700 text-xs">{{ fmt(summary.total_accumulated_dep) }}</td>
                <td class="px-4 py-2 text-right font-bold text-green-700 text-xs">{{ fmt(summary.total_net_book_value) }}</td>
                <td colspan="3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  assets:     Array,
  categories: Array,
  summary:    Object,
  filters:    Object,
});

const { formatVnd: fmt } = useCurrency();

const filterStatus   = ref(props.filters?.status   ?? '');
const filterCategory = ref(props.filters?.category ?? '');

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  if (filterStatus.value)   p.set('status', filterStatus.value);
  if (filterCategory.value) p.set('category', filterCategory.value);
  return route('reports.fixed_assets.export') + (p.toString() ? '?' + p.toString() : '');
});

function statusLabel(s) {
  return { active: 'Đang dùng', fully_depreciated: 'Đã KH hết', disposed: 'Đã thanh lý' }[s] ?? s;
}

function applyFilters() {
  router.get(route('reports.fixed_assets'), {
    status:   filterStatus.value   || undefined,
    category: filterCategory.value || undefined,
  }, { preserveState: true, replace: true });
}
</script>
