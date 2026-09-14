<template>
  <Modal :show="true" max-width="2xl" @close="$emit('close')">
    <template #title>Gợi ý cặp chuyển khoản nội bộ</template>

    <div class="space-y-4">
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <p v-if="loading" class="text-sm text-gray-500">Đang tải gợi ý...</p>
      <p v-if="!loading && !suggestions.length" class="text-sm text-gray-400">Không có gợi ý cặp đôi nào.</p>

      <div v-for="(s, idx) in suggestions" :key="idx" class="rounded-lg border border-gray-200 p-3">
        <p class="text-xs text-gray-500">Giao dịch chuyển đi</p>
        <p class="text-sm font-medium text-gray-900">
          {{ s.outgoing.transaction_date }} · {{ s.outgoing.bank_account_name }} · {{ formatVnd(s.outgoing.amount) }} · {{ s.outgoing.description }}
        </p>

        <p class="mt-2 text-xs text-gray-500">
          {{ s.ambiguous ? `${s.candidates.length} giao dịch đến khớp số tiền — chọn đúng 1 cái trước khi xác nhận (spec §5 Case B)` : 'Giao dịch đến khớp' }}
        </p>
        <div class="mt-1 space-y-1">
          <label v-for="c in s.candidates" :key="c.id" class="flex items-center gap-2 text-sm text-gray-900">
            <input type="radio" :name="`pair-${idx}`" :value="c.id" v-model="selected[idx]" />
            {{ c.transaction_date }} · {{ c.bank_account_name }} · {{ formatVnd(c.amount) }} · {{ c.description }}
          </label>
        </div>

        <button
          class="erp-btn-primary erp-btn-sm mt-3"
          :disabled="!selected[idx] || confirming"
          @click="confirm(s, idx)"
        >
          Xác nhận cặp đôi
        </button>
      </div>
    </div>

    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('close')">Đóng</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Shared/Modal.vue';
import { formatVnd } from '@/composables/useCurrency';

const emit = defineEmits(['close', 'paired']);

const suggestions = ref([]);
const selected = reactive({});
const loading = ref(false);
const confirming = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await axios.post(route('reports.company-cashflow.pair-suggestions'));
    suggestions.value = data.data;
    suggestions.value.forEach((s, idx) => {
      if (!s.ambiguous && s.candidates.length === 1) selected[idx] = s.candidates[0].id;
    });
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Không tải được gợi ý.';
  } finally {
    loading.value = false;
  }
}

async function confirm(s, idx) {
  confirming.value = true;
  error.value = '';
  try {
    await axios.post(route('reports.company-cashflow.confirm-pair'), {
      outgoing_id: s.outgoing.id,
      incoming_id: selected[idx],
    });
    emit('paired');
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Xác nhận cặp đôi thất bại.';
  } finally {
    confirming.value = false;
  }
}

onMounted(load);
</script>
