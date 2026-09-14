<template>
  <Modal :show="true" max-width="md" @close="$emit('cancel')">
    <template #title>Lý do chọn NCC không có giá thấp nhất</template>
    <div class="space-y-4">
      <p class="text-sm text-gray-600">
        Mặt hàng <span class="font-medium">{{ item.product_code }}</span> — bạn đang chọn
        <span class="font-medium">{{ supplierName }}</span>. Vui lòng nêu lý do.
      </p>
      <FormField label="Lý do lựa chọn" required :error="err">
        <select v-model="reason" class="erp-input">
          <option value="">-- Chọn lý do --</option>
          <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
      </FormField>
      <FormField label="Ghi chú lý do">
        <textarea v-model="note" rows="2" class="erp-input" />
      </FormField>
    </div>
    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('cancel')">Hủy</button>
      <button class="erp-btn-primary" @click="confirm">Xác nhận</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref } from 'vue';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';

defineProps({ reasons: Array, item: Object, supplierName: String });
const emit = defineEmits(['cancel', 'confirm']);

const reason = ref('');
const note = ref('');
const err = ref('');

function confirm() {
  if (!reason.value) { err.value = 'Bắt buộc chọn lý do.'; return; }
  emit('confirm', { reason: reason.value, note: note.value });
}
</script>
