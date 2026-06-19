<template>
  <AppLayout>
    <div class="max-w-6xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Sổ theo dõi CCDC</h1>
        <div class="flex gap-2">
          <select v-model="filters.category_id" class="erp-input w-40" @change="apply">
            <option value="">Tất cả danh mục</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <select v-model="filters.status" class="erp-input w-40" @change="apply">
            <option value="">Tất cả TT</option>
            <option value="in_stock">Trong kho</option>
            <option value="in_use">Đang dùng</option>
            <option value="allocating">Đang phân bổ</option>
            <option value="fully_allocated">Hết phân bổ</option>
            <option value="broken">Hỏng</option>
            <option value="lost">Mất</option>
            <option value="disposed">Đã xử lý</option>
          </select>
          <select v-model="filters.department" class="erp-input w-36" @change="apply">
            <option value="">Tất cả BP</option>
            <option v-for="d in departments" :key="d" :value="d">{{ d }}</option>
          </select>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b text-xs text-gray-500">
            <tr>
              <th class="px-3 py-3 text-left">Mã CCDC</th>
              <th class="px-3 py-3 text-left">Tên</th>
              <th class="px-3 py-3 text-left">Danh mục</th>
              <th class="px-3 py-3 text-left">Bộ phận</th>
              <th class="px-3 py-3 text-right">Nguyên giá</th>
              <th class="px-3 py-3 text-right">Đã phân bổ</th>
              <th class="px-3 py-3 text-right">Còn lại</th>
              <th class="px-3 py-3 text-center">Kỳ PB còn</th>
              <th class="px-3 py-3 text-center">Trạng thái</th>
              <th class="px-3 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in tools.data" :key="t.id" class="hover:bg-gray-50">
              <td class="px-3 py-3 font-mono text-xs font-semibold text-primary-700">{{ t.code }}</td>
              <td class="px-3 py-3">{{ t.name }}</td>
              <td class="px-3 py-3 text-gray-500 text-xs">{{ t.category_name || '—' }}</td>
              <td class="px-3 py-3 text-gray-500 text-xs">{{ t.department || '—' }}</td>
              <td class="px-3 py-3 text-right font-mono">{{ formatVnd(t.original_cost) }}</td>
              <td class="px-3 py-3 text-right font-mono text-blue-700">{{ formatVnd(t.total_allocated) }}</td>
              <td class="px-3 py-3 text-right font-mono" :class="t.total_remaining > 0 ? 'text-orange-600' : 'text-gray-400'">
                {{ formatVnd(t.total_remaining) }}
              </td>
              <td class="px-3 py-3 text-center text-xs">
                <span v-if="t.recognition_method === 'allocation'">
                  {{ t.periods_remaining }}/{{ t.allocation_periods }}
                </span>
                <span v-else class="text-gray-400">N/A</span>
              </td>
              <td class="px-3 py-3 text-center">
                <span :class="statusClass(t.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ statusLabel(t.status) }}
                </span>
              </td>
              <td class="px-3 py-3">
                <Link :href="route('accounting.small-tools.show', t.id)"
                  class="text-xs text-primary-600 hover:underline">Xem</Link>
              </td>
            </tr>
            <tr v-if="!tools.data.length">
              <td colspan="10" class="px-4 py-8 text-center text-gray-400">Không có CCDC nào.</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t text-sm font-semibold">
            <tr>
              <td colspan="4" class="px-3 py-3 text-right">Tổng:</td>
              <td class="px-3 py-3 text-right font-mono">{{ formatVnd(summary.total_cost) }}</td>
              <td class="px-3 py-3 text-right font-mono text-blue-700">{{ formatVnd(summary.total_allocated) }}</td>
              <td class="px-3 py-3 text-right font-mono text-orange-600">{{ formatVnd(summary.total_remaining) }}</td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div v-if="tools.last_page > 1" class="flex justify-end mt-4 gap-1">
        <Link v-for="link in tools.links" :key="link.label"
          :href="link.url || '#'"
          :class="['px-3 py-1 text-sm rounded border', link.active ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50']"
          v-html="link.label" />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();
const props = defineProps({ tools: Object, summary: Object, categories: Array, departments: Array, filters: Object });

const filters = reactive({
  category_id: props.filters?.category_id || '',
  status:      props.filters?.status || '',
  department:  props.filters?.department || '',
});

function apply() {
  router.get(route('accounting.small-tools.reports.ledger'), filters, { preserveState: true, replace: true });
}

function statusLabel(s) {
  return { in_stock: 'Trong kho', in_use: 'Đang dùng', allocating: 'Phân bổ', fully_allocated: 'Hết PB', broken: 'Hỏng', lost: 'Mất', disposed: 'Đã XL', draft: 'Nháp', cancelled: 'Đã hủy' }[s] ?? s;
}
function statusClass(s) {
  const map = { in_stock: 'bg-blue-100 text-blue-700', in_use: 'bg-green-100 text-green-700', allocating: 'bg-yellow-100 text-yellow-700', fully_allocated: 'bg-gray-100 text-gray-500', broken: 'bg-red-100 text-red-700', lost: 'bg-red-200 text-red-800', disposed: 'bg-gray-200 text-gray-600' };
  return map[s] || 'bg-gray-100 text-gray-500';
}
</script>
