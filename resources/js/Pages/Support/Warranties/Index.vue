<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Bảo hành thiết bị</h1>
        <Link v-if="can('tickets.create')" :href="route('support.warranties.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo bảo hành
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3">
        <input v-model="searchInput" @keydown.enter="applyFilters" type="text" placeholder="Tìm mã, tên SP, serial..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:ring-2 focus:ring-primary-500 focus:border-primary-500" />
        <select v-model="filterStatus" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã BH</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Thiết bị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Serial</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Bắt đầu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hết hạn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="w in warranties.data" :key="w.id"
              :class="['hover:bg-gray-50', w.is_expiring_soon ? 'bg-yellow-50' : '']">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ w.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ w.customer }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ w.product_name }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ w.serial_number || '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ w.start_date }}</td>
              <td class="px-5 py-3" :class="w.is_expiring_soon ? 'text-yellow-700 font-medium' : 'text-gray-600'">
                {{ w.end_date }}
                <span v-if="w.is_expiring_soon" class="ml-1 text-xs">(sắp hết)</span>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="w.status_color">{{ w.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('support.warranties.show', w.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!warranties.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có bảo hành nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="warranties.links" :meta="warranties.meta" />
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
  warranties: Object,
  filters: Object,
  statuses: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const searchInput = ref(props.filters?.search ?? '');
const filterStatus  = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('support.warranties.index'), {
    search: searchInput.value || undefined,
    status: filterStatus.value  || undefined,
  }, { preserveState: true, replace: true });
}
</script>
