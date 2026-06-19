<template>
  <AppLayout>
    <div class="max-w-6xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu xuất dùng CCDC</h1>
        <Link v-if="can('ccdc.manage')" :href="route('accounting.small-tools.issues.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Tạo phiếu xuất dùng
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 mb-5">
        <input v-model="filters.search" type="text" placeholder="Tìm mã phiếu..." class="erp-input w-48"
          @keyup.enter="applyFilters" />
        <select v-model="filters.status" class="erp-input w-36" @change="applyFilters">
          <option value="">Tất cả trạng thái</option>
          <option value="draft">Nháp</option>
          <option value="confirmed">Đã xác nhận</option>
          <option value="cancelled">Đã hủy</option>
        </select>
        <button @click="applyFilters" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left">Mã phiếu</th>
              <th class="px-4 py-3 text-left">Ngày xuất</th>
              <th class="px-4 py-3 text-left">Bộ phận</th>
              <th class="px-4 py-3 text-left">Ghi nhận</th>
              <th class="px-4 py-3 text-right">Số CCDC</th>
              <th class="px-4 py-3 text-right">Tổng giá trị</th>
              <th class="px-4 py-3 text-center">Trạng thái</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="issue in issues.data" :key="issue.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs font-semibold text-primary-600">
                <Link :href="route('accounting.small-tools.issues.show', issue.id)" class="hover:underline">
                  {{ issue.code }}
                </Link>
              </td>
              <td class="px-4 py-3">{{ issue.issue_date }}</td>
              <td class="px-4 py-3 text-gray-600">{{ issue.department || '—' }}</td>
              <td class="px-4 py-3 text-xs">
                <span :class="issue.recognition_method === 'allocation' ? 'text-blue-600' : 'text-gray-500'">
                  {{ issue.recognition_method === 'allocation' ? 'Phân bổ' : 'Một lần' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">{{ issue.items_count }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ formatVnd(issue.total_amount) }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="statusClass(issue.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ statusLabel(issue.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <Link :href="route('accounting.small-tools.issues.show', issue.id)"
                  class="text-xs text-primary-600 hover:underline">Xem</Link>
              </td>
            </tr>
            <tr v-if="!issues.data.length">
              <td colspan="8" class="px-4 py-8 text-center text-gray-400">Chưa có phiếu xuất dùng nào.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="issues.last_page > 1" class="flex justify-end mt-4 gap-1">
        <Link v-for="link in issues.links" :key="link.label"
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
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();
const props = defineProps({ issues: Object, filters: Object });

const filters = reactive({ search: props.filters?.search || '', status: props.filters?.status || '' });

function applyFilters() {
  router.get(route('accounting.small-tools.issues.index'), filters, { preserveState: true, replace: true });
}
function statusLabel(s) {
  return { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' }[s] ?? s;
}
function statusClass(s) {
  return { draft: 'bg-yellow-100 text-yellow-700', confirmed: 'bg-green-100 text-green-700', cancelled: 'bg-gray-100 text-gray-500' }[s];
}
</script>
