<template>
  <Modal :show="true" max-width="lg" @close="$emit('close')">
    <template #title>Thông tin thôi việc</template>

    <div class="space-y-4">
      <div class="rounded-lg bg-gray-50 border border-gray-200 px-4 py-3 text-sm">
        <span class="text-gray-500">Nhân viên:</span>
        <span class="font-medium text-gray-900">{{ employee.name }}</span>
        <span class="text-gray-400 mx-2">·</span>
        <span class="text-gray-500">Mã NV:</span>
        <span class="font-mono text-gray-900">{{ employee.code }}</span>
      </div>

      <FormField label="Ngày làm việc cuối cùng" required :error="form.errors.termination_date">
        <input v-model="form.termination_date" type="date" class="erp-input" />
      </FormField>

      <FormField label="Lý do thôi việc" :error="form.errors.termination_reason">
        <input v-model="form.termination_reason" class="erp-input" placeholder="VD: Nghỉ theo nguyện vọng" />
      </FormField>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <FormField label="Số quyết định" :error="form.errors.termination_decision_no">
          <input v-model="form.termination_decision_no" class="erp-input" />
        </FormField>
        <FormField label="Ngày quyết định" :error="form.errors.termination_decision_date">
          <input v-model="form.termination_decision_date" type="date" class="erp-input" />
        </FormField>
      </div>

      <FormField label="Ghi chú" :error="form.errors.termination_note">
        <textarea v-model="form.termination_note" rows="2" class="erp-input" />
      </FormField>
    </div>

    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('close')">Hủy</button>
      <button class="erp-btn-danger" :disabled="form.processing" @click="submit">Xác nhận thôi việc</button>
    </template>
  </Modal>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';

const props = defineProps({ employee: Object });
const emit = defineEmits(['close']);

const form = useForm({
  termination_date: new Date().toISOString().slice(0, 10),
  termination_reason: '',
  termination_decision_no: '',
  termination_decision_date: '',
  termination_note: '',
});

function submit() {
  form.post(route('admin.employees.terminate', props.employee.id), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  });
}
</script>
