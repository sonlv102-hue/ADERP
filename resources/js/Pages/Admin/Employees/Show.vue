<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('admin.employees.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="text-2xl font-bold text-gray-900">{{ employee.name }}</h1>
              <StatusBadge :color="employee.status_color">{{ employee.status_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 font-mono mt-0.5">{{ employee.code }}</p>
          </div>
        </div>
        <div class="flex gap-2 flex-wrap">
          <a :href="route('admin.employees.export.pdf', employee.id)" target="_blank" class="erp-btn-secondary">Xuất PDF</a>
          <a :href="route('admin.employees.print', employee.id)" target="_blank" class="erp-btn-secondary">In hồ sơ</a>
          <Link :href="route('admin.employees.edit', employee.id)"
            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">
            Sửa
          </Link>
          <button @click="deleteEmployee"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Xóa
          </button>
        </div>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        <div class="px-6 py-4 grid grid-cols-2 gap-x-8 gap-y-3">
          <InfoRow label="Phòng ban" :value="employee.department" />
          <InfoRow label="Chức vụ" :value="employee.position" />
          <InfoRow label="Điện thoại" :value="employee.phone" />
          <InfoRow label="Email" :value="employee.email" />
          <InfoRow label="Ngày sinh" :value="employee.birth_date" />
          <InfoRow label="Giới tính" :value="employee.gender_label" />
          <InfoRow label="Ngày vào làm" :value="employee.hire_date" />
          <InfoRow label="Loại hợp đồng" :value="employee.employment_type_label" />
        </div>
        <div v-if="employee.address" class="px-6 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Địa chỉ</p>
          <p class="text-sm text-gray-800">{{ employee.address }}</p>
        </div>
        <div v-if="employee.notes" class="px-6 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Ghi chú</p>
          <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ employee.notes }}</p>
        </div>
        <div class="px-6 py-3 bg-gray-50 flex justify-between text-xs text-gray-400">
          <span>Tạo bởi {{ employee.creator }}</span>
          <span>{{ employee.created_at }}</span>
        </div>
      </div>

      <FileAttachments :attachments="attachments ?? []"
        :upload-url="route('attachments.store', { type: 'employee', id: employee.id })" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import FileAttachments from '@/Components/Shared/FileAttachments.vue';

const props = defineProps({ employee: Object, attachments: Array });

const deleteEmployee = () => {
  if (confirm(`Xóa cán bộ ${props.employee.name}? Thao tác không thể hoàn tác.`)) {
    router.delete(route('admin.employees.destroy', props.employee.id));
  }
};

const InfoRow = {
  props: ['label', 'value'],
  template: `
    <div>
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-0.5">{{ label }}</p>
      <p class="text-sm text-gray-800">{{ value ?? '—' }}</p>
    </div>
  `,
};
</script>
