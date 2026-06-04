<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Kiểm kê kho</h1>
        <Link
          :href="route('warehouse.inventory-counts.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo phiếu kiểm kê
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <select v-model="filterWarehouse" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả kho</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
        <select v-model="filterStatus" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="draft">Nháp</option>
          <option value="confirmed">Đã xác nhận</option>
          <option value="cancelled">Đã hủy</option>
        </select>
        <button v-if="filterWarehouse || filterStatus" @click="clearFilters"
          class="text-sm text-gray-500 hover:text-gray-700 underline">
          Xóa bộ lọc
        </button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày kiểm kê</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kho</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Số SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người kiểm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in counts.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ c.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.count_date }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.warehouse }}</td>
              <td class="px-5 py-3 text-center text-gray-600">{{ c.items_count }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.counted_by }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="flex items-center justify-end gap-3">
                  <Link :href="route('warehouse.inventory-counts.show', c.id)"
                    class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
                  <button v-if="['draft','cancelled'].includes(c.status)"
                    @click="deleteCount(c)"
                    class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
                </div>
              </td>
            </tr>
            <tr v-if="!counts.data?.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Chưa có phiếu kiểm kê nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="counts.links" :meta="counts.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const props = defineProps({ counts: Object, warehouses: Array, filters: Object });

const filterWarehouse = ref(props.filters?.warehouse_id ?? '');
const filterStatus = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('warehouse.inventory-counts.index'), {
    warehouse_id: filterWarehouse.value || undefined,
    status: filterStatus.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  filterWarehouse.value = '';
  filterStatus.value = '';
  applyFilters();
}

function deleteCount(c) {
  if (!confirm(`Xóa phiếu kiểm kê ${c.code}?`)) return;
  router.delete(route('warehouse.inventory-counts.destroy', c.id));
}
</script>
