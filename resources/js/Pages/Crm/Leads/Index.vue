<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Khách hàng tiềm năng</h1>
        <Link v-if="can('leads.create')" :href="route('crm.leads.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm Lead
        </Link>
      </div>

      <!-- Filter -->
      <div class="flex items-center gap-3">
        <select v-model="filterStatus" @change="applyFilter"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên liên hệ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Công ty</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nguồn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Phụ trách</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Follow-up</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Giá trị</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ lead.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ lead.full_name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ lead.company_name ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ sourceLabel(lead.source) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="lead.status_color">{{ lead.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ lead.assigned_to_name ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ lead.next_follow_up ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">
                {{ lead.expected_value ? Number(lead.expected_value).toLocaleString('vi-VN') : '—' }}
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link :href="route('crm.leads.show', lead.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Chi tiết</Link>
                <Link v-if="can('leads.edit')" :href="route('crm.leads.edit', lead.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button v-if="can('leads.delete')" @click="confirmDelete(lead)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!leads.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có lead nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="leads.links" :meta="leads.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa lead <strong>{{ deleteTarget?.full_name }}</strong> không?</p>
      <template #footer>
        <button @click="deleteTarget = null"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete"
          class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
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
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ leads: Object, statuses: Array, filters: Object });

const { hasPermission } = usePermission();
const can = hasPermission;

const filterStatus = ref(props.filters?.status ?? '');
const deleteTarget = ref(null);

const sourceLabels = {
  website: 'Website', referral: 'Giới thiệu', 'cold-call': 'Cold Call',
  event: 'Sự kiện', other: 'Khác',
};
const sourceLabel = (s) => sourceLabels[s] ?? s ?? '—';

const applyFilter = () => {
  router.get(route('crm.leads.index'), { status: filterStatus.value }, { preserveState: true, replace: true });
};

const confirmDelete = (lead) => { deleteTarget.value = lead; };

const doDelete = () => {
  router.delete(route('crm.leads.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};
</script>
