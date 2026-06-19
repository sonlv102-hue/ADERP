<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Khách hàng</h1>
        <div class="flex gap-2">
          <button @click="showImport = true"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            Import Excel
          </button>
          <Link :href="route('crm.customers.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm khách hàng
          </Link>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã KH</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Công ty</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số điện thoại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Phụ trách</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="customer in customers.data" :key="customer.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ customer.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">
                {{ customer.name }}
                <span v-if="customer.is_fdi"
                  class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                  FDI
                </span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ customer.company ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ customer.phone ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="customer.lead_status_color">{{ customer.lead_status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ customer.assigned_user?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('crm.customers.show', customer.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Chi tiết</Link>
                <Link :href="route('crm.customers.edit', customer.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(customer)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!customers.data?.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Chưa có khách hàng nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="customers.links" :meta="customers.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa khách hàng <strong>{{ deleteTarget?.name }}</strong> không?</p>
      <template #footer>
        <button @click="deleteTarget = null" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
      </template>
    </Modal>

    <Modal :show="showImport" @close="showImport = false">
      <template #title>Import khách hàng từ Excel</template>
      <div class="space-y-4">
        <a :href="route('crm.customers.import-template')"
          class="inline-flex items-center gap-2 text-sm text-primary-600 hover:text-primary-800">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Tải file mẫu (template)
        </a>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chọn file Excel (.xlsx, .xls, .csv)</label>
          <input ref="importFileInput" type="file" accept=".xlsx,.xls,.csv"
            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
        </div>
      </div>
      <template #footer>
        <button @click="showImport = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doImport" class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">Import</button>
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

defineProps({ customers: Object, lead_statuses: Array });

const deleteTarget = ref(null);
const showImport = ref(false);
const importFileInput = ref(null);

const confirmDelete = (customer) => { deleteTarget.value = customer; };

const doDelete = () => {
  router.delete(route('crm.customers.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};

const doImport = () => {
  const file = importFileInput.value?.files?.[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('file', file);
  router.post(route('crm.customers.import'), formData, {
    forceFormData: true,
    onSuccess: () => { showImport.value = false; },
  });
};
</script>
