<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Dịch vụ</h1>
        <Link :href="route('catalog.services.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm dịch vụ
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã DV</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn vị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Giá</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="service in services.data" :key="service.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ service.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ service.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ service.unit }}</td>
              <td class="px-5 py-3 text-gray-600">{{ formatVnd(service.price) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="service.is_active ? 'green' : 'red'">
                  {{ service.is_active ? 'Hoạt động' : 'Dừng' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('catalog.services.edit', service.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(service)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!services.data?.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Chưa có dịch vụ nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="services.links" :meta="services.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa dịch vụ <strong>{{ deleteTarget?.name }}</strong> không?</p>
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
import { useCurrency } from '@/composables/useCurrency';

defineProps({ services: Object });

const { formatVnd } = useCurrency();

const deleteTarget = ref(null);

const confirmDelete = (service) => { deleteTarget.value = service; };

const doDelete = () => {
  router.delete(route('catalog.services.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};
</script>
