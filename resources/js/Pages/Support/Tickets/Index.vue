<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Ticket kỹ thuật</h1>
        <Link v-if="can('tickets.create')" :href="route('support.tickets.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo ticket
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3">
        <input v-model="searchInput" @keydown.enter="applyFilters" type="text" placeholder="Tìm mã, tiêu đề..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:ring-2 focus:ring-primary-500 focus:border-primary-500" />
        <select v-model="filterStatus" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <select v-model="filterPriority" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả ưu tiên</option>
          <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã TK</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tiêu đề</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người xử lý</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ưu tiên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn xử lý</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in tickets.data" :key="t.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ t.code }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium max-w-xs truncate">{{ t.title }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.customer }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.assignee ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="t.priority_color">{{ t.priority_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="t.status_color">{{ t.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ t.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('support.tickets.show', t.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!tickets.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có ticket nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="tickets.links" :meta="tickets.meta" />
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

const props = defineProps({
  tickets: Object,
  filters: Object,
  statuses: Array,
  priorities: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const searchInput  = ref(props.filters?.search   ?? '');
const filterStatus   = ref(props.filters?.status   ?? '');
const filterPriority = ref(props.filters?.priority ?? '');

function applyFilters() {
  router.get(route('support.tickets.index'), {
    search:   searchInput.value  || undefined,
    status:   filterStatus.value   || undefined,
    priority: filterPriority.value || undefined,
  }, { preserveState: true, replace: true });
}
</script>
