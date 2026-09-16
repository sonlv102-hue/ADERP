<template>
  <Modal :show="true" max-width="md" @close="$emit('close')">
    <template #title>Thông tin đối ứng ngân hàng</template>

    <div class="space-y-3 text-sm">
      <div class="rounded-lg bg-gray-50 p-3">
        <p class="text-gray-500 text-xs">{{ transaction.transaction_date }}</p>
        <p class="font-medium text-gray-900">{{ transaction.description }}</p>
      </div>

      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Loại</span>
        <span class="font-medium text-gray-900">{{ typeLabel }}</span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Ngân hàng</span>
        <span class="font-medium text-gray-900">{{ (transaction.counterpart_bank || '').trim() || '—' }}</span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Số tài khoản</span>
        <span class="font-mono text-gray-900">{{ transaction.counterpart_account || '—' }}</span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Tên tài khoản</span>
        <span class="font-medium text-gray-900">{{ transaction.counterpart_name || '—' }}</span>
      </div>
      <div class="flex justify-between">
        <span class="text-gray-500">Reference</span>
        <span class="font-mono text-gray-900">{{ transaction.reference || '—' }}</span>
      </div>
    </div>

    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('close')">Đóng</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed } from 'vue';
import Modal from '@/Components/Shared/Modal.vue';

const props = defineProps({ transaction: { type: Object, required: true } });
defineEmits(['close']);

const typeLabel = computed(() => (props.transaction.credit > 0 ? 'Người chuyển tiền' : 'Người nhận tiền'));
</script>
