<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Cán bộ công nhân viên</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('admin.employees.export.excel')" :filters="exportFilters" label="Xuất Excel" />
          <a :href="route('admin.employees.import.template')" class="erp-btn-secondary">Tải mẫu Excel</a>
          <button @click="showImportModal = true" class="erp-btn-secondary">Upload Excel</button>
          <Link :href="route('admin.employees.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm cán bộ
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text" placeholder="Tìm tên, mã, phòng ban, chức vụ..."
          class="form-input w-64 text-sm" />
        <select v-model="statusFilter" @change="doSearch" class="form-input text-sm w-44">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã NV</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Họ và tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Phòng ban</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Chức vụ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Điện thoại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày vào làm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="e in employees.data" :key="e.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ e.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ e.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ e.department ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ e.position ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ e.phone ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600 text-xs">{{ e.hire_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600 text-xs">{{ e.employment_type }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="e.status_color">{{ e.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link :href="route('admin.employees.show', e.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3">Xem</Link>
                <Link :href="route('admin.employees.edit', e.id)"
                  class="text-gray-500 hover:text-gray-700 text-xs font-medium mr-3">Sửa</Link>
                <a :href="route('admin.employees.export.pdf', e.id)" target="_blank"
                  class="text-gray-500 hover:text-gray-700 text-xs font-medium mr-3">Xuất PDF</a>
                <a :href="route('admin.employees.print', e.id)" target="_blank"
                  class="text-gray-500 hover:text-gray-700 text-xs font-medium">In hồ sơ</a>
              </td>
            </tr>
            <tr v-if="!employees.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có cán bộ nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="employees.links" :meta="employees.meta" />
    </div>

    <EmployeeImportModal v-if="showImportModal" @close="showImportModal = false" @imported="showImportModal = false" />
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import EmployeeImportModal from './EmployeeImportModal.vue';

const props = defineProps({
  employees: Object,
  filters: Object,
  statuses: Array,
});

const search = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const showImportModal = ref(false);

const exportFilters = computed(() => ({
  q: search.value || undefined,
  status: statusFilter.value || undefined,
}));

let timer;
const doSearch = () => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    router.get(route('admin.employees.index'), {
      q: search.value || undefined,
      status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
  }, 300);
};
</script>
