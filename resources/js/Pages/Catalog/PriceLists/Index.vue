<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Bảng giá</h1>
        <Link :href="route('catalog.price-lists.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm bảng giá
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên bảng giá</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hiệu lực từ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đến</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mặc định</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số SP</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="pl in priceLists.data" :key="pl.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ pl.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ pl.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ pl.valid_from ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ pl.valid_to ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="pl.is_default ? 'green' : 'gray'">
                  {{ pl.is_default ? 'Mặc định' : 'Không' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ pl.items_count }}</td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link :href="route('catalog.price-lists.show', pl.id)"
                  class="text-blue-600 hover:text-blue-800 font-medium mr-3">Chi tiết</Link>
                <Link :href="route('catalog.price-lists.edit', pl.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(pl)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!priceLists.data?.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Chưa có bảng giá nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="priceLists.links" :meta="priceLists.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa bảng giá <strong>{{ deleteTarget?.name }}</strong> không?</p>
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

defineProps({ priceLists: Object });

const deleteTarget = ref(null);
const confirmDelete = (pl) => { deleteTarget.value = pl; };
const doDelete = () => {
  router.delete(route('catalog.price-lists.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};
</script>
