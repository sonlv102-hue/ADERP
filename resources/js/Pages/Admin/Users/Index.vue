<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Quản lý người dùng</h1>
        <Link :href="route('admin.users.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo tài khoản
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Họ tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Email</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số điện thoại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Vai trò</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-medium text-gray-900">{{ user.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ user.email }}</td>
              <td class="px-5 py-3 text-gray-600">{{ user.phone ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="roleColor(user.roles[0])">{{ user.roles[0] ?? '—' }}</StatusBadge>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="user.is_active ? 'green' : 'red'">
                  {{ user.is_active ? 'Hoạt động' : 'Bị khóa' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('admin.users.edit', user.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(user)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!users.data?.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Chưa có tài khoản nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="users.links" :meta="users.meta" />
    </div>

    <!-- Confirm Delete Modal -->
    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa tài khoản <strong>{{ deleteTarget?.name }}</strong> không?</p>
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

defineProps({ users: Object, roles: Array });

const deleteTarget = ref(null);

const roleColor = (role) => ({
  admin: 'red', director: 'purple', sales: 'blue',
  warehouse: 'green', technical: 'yellow', accounting: 'orange', cskh: 'gray',
}[role] ?? 'gray');

const confirmDelete = (user) => { deleteTarget.value = user; };

const doDelete = () => {
  router.delete(route('admin.users.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};
</script>
