<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Hoa hồng & Chi phí KH</h1>
        <Link v-if="can('commissions.create')" :href="route('sales.commissions.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Tạo đề xuất
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm mã, tên người nhận..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <select v-model="typeFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả loại</option>
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <button v-if="search || statusFilter || typeFilter" @click="clearFilters"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người nhận</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Liên kết</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Dự kiến chi</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in commissions.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ c.code }}</td>
              <td class="px-5 py-3 text-gray-700 text-xs">{{ c.type_label }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ c.recipient_name }}</td>
              <td class="px-5 py-3 text-xs text-gray-500">
                <span v-if="c.order" class="mr-1 px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded">{{ c.order }}</span>
                <span v-if="c.project" class="mr-1 px-1.5 py-0.5 bg-purple-50 text-purple-700 rounded">{{ c.project }}</span>
                <span v-if="c.customer" class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">{{ c.customer }}</span>
              </td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ fmt(c.amount) }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.planned_date ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ c.creator }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('sales.commissions.show', c.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!commissions.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có khoản hoa hồng nào</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="commissions.links" :meta="commissions.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  commissions: Object,
  types:       Array,
  statuses:    Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd: fmt } = useCurrency();

const search       = ref('');
const statusFilter = ref('');
const typeFilter   = ref('');

function applyFilters() {
  router.get(route('sales.commissions.index'), {
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    type:   typeFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = statusFilter.value = typeFilter.value = '';
  applyFilters();
}

</script>
