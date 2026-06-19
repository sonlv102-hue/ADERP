<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Kho hàng</h1>
        <Link :href="route('warehouse.warehouses.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm kho
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Địa chỉ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số điện thoại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="warehouse in warehouses.data" :key="warehouse.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-medium text-gray-900">{{ warehouse.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ warehouse.address ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ warehouse.phone ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="warehouse.is_active ? 'green' : 'red'">
                  {{ warehouse.is_active ? 'Hoạt động' : 'Dừng' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('warehouse.warehouses.edit', warehouse.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(warehouse)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!warehouses.data?.length">
              <td colspan="5" class="px-5 py-10 text-center text-gray-400">Chưa có kho nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="warehouses.links" :meta="warehouses.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa kho <strong>{{ deleteTarget?.name }}</strong> không?</p>
      <template #footer>
        <button @click="deleteTarget = null" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import Modal from '@/Components/Shared/Modal.vue';

defineProps({ warehouses: Object });

const deleteTarget = ref(null);

const confirmDelete = (warehouse) => { deleteTarget.value = warehouse; };

const doDelete = () => {
  router.delete(route('warehouse.warehouses.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};
</script>
