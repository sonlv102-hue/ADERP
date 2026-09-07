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
          <Link :href="route('admin.employees.edit', employee.id)" class="erp-btn-secondary">Sửa</Link>
          <button
            v-if="employee.is_working && can('hr.employees.terminate')"
            class="erp-btn-danger"
            @click="showTerminate = true"
          >Thôi việc</button>
          <button
            v-if="!employee.is_working && can('hr.employees.terminate_cancel')"
            class="erp-btn-secondary"
            @click="showCancel = true"
          >Hủy thôi việc</button>
          <button @click="deleteEmployee" class="erp-btn-danger">Xóa</button>
        </div>
      </div>

      <!-- Panel: đã thôi việc -->
      <div
        v-if="!employee.is_working && employee.termination_date"
        class="bg-red-50 border border-red-200 rounded-xl px-6 py-4"
      >
        <p class="text-sm font-bold text-red-700 uppercase tracking-wide">Trạng thái: Đã thôi việc</p>
        <div class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
          <InfoRow label="Ngày thôi việc" :value="employee.termination_date" />
          <InfoRow label="Lý do" :value="employee.termination_reason" />
          <InfoRow label="Số quyết định" :value="employee.termination_decision_no" />
          <InfoRow label="Ngày quyết định" :value="employee.termination_decision_date" />
          <InfoRow label="Ghi nhận bởi" :value="employee.terminated_by_name" />
          <InfoRow label="Thời điểm ghi nhận" :value="employee.terminated_at" />
        </div>
        <div v-if="employee.termination_note" class="mt-2 text-sm">
          <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-0.5">Ghi chú</p>
          <p class="text-gray-800 whitespace-pre-wrap">{{ employee.termination_note }}</p>
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

        <div class="px-6 py-4 grid grid-cols-2 gap-x-8 gap-y-3">
          <InfoRow label="CCCD/CMND" :value="employee.national_id" />
          <InfoRow label="Ngày cấp & Nơi cấp" :value="employee.national_id_issue_date ? employee.national_id_issue_date + (employee.national_id_issue_place ? ' tại ' + employee.national_id_issue_place : '') : '—'" />
          <InfoRow label="Hợp đồng từ ngày" :value="employee.contract_start_date" />
          <InfoRow label="Hợp đồng đến ngày" :value="employee.contract_end_date" />
          <InfoRow label="Số sổ BHXH" :value="employee.social_insurance_no" />
          <InfoRow label="Mã số thuế TNCN" :value="employee.pit_tax_code" />
        </div>

        <div class="px-6 py-4 grid grid-cols-2 gap-x-8 gap-y-3">
          <InfoRow label="Số tài khoản ngân hàng" :value="employee.bank_account_no" />
          <InfoRow label="Ngân hàng" :value="employee.bank_name" />
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

    <TerminateModal v-if="showTerminate" :employee="employee" @close="showTerminate = false" />

    <Modal :show="showCancel" max-width="md" @close="showCancel = false">
      <template #title>Hủy xác nhận thôi việc</template>
      <div class="space-y-3">
        <p class="text-sm text-gray-600">
          Nhân viên <span class="font-medium">{{ employee.name }}</span> sẽ trở lại trạng thái
          <span class="font-medium">Đang làm việc</span>. Thông tin thôi việc sẽ bị xóa (vẫn lưu trong nhật ký hoạt động).
        </p>
        <FormField label="Lý do hủy thôi việc" required :error="cancelForm.errors.reason">
          <textarea v-model="cancelForm.reason" rows="3" class="erp-input" placeholder="VD: Nhập nhầm ngày thôi việc" />
        </FormField>
      </div>
      <template #footer>
        <button class="erp-btn-secondary" @click="showCancel = false">Đóng</button>
        <button class="erp-btn-primary" :disabled="cancelForm.processing" @click="submitCancel">Xác nhận hủy</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import FileAttachments from '@/Components/Shared/FileAttachments.vue';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';
import TerminateModal from './TerminateModal.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ employee: Object, attachments: Array });
const { hasPermission: can } = usePermission();

const showTerminate = ref(false);
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });

const deleteEmployee = () => {
  if (confirm(`Xóa cán bộ ${props.employee.name}? Thao tác không thể hoàn tác.`)) {
    router.delete(route('admin.employees.destroy', props.employee.id));
  }
};

function submitCancel() {
  cancelForm.post(route('admin.employees.cancel-termination', props.employee.id), {
    preserveScroll: true,
    onSuccess: () => { showCancel.value = false; cancelForm.reset(); },
  });
}

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
