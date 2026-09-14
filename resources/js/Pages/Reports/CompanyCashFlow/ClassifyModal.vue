<template>
  <Modal :show="true" max-width="lg" @close="$emit('close')">
    <template #title>Phân loại giao dịch</template>

    <div class="space-y-4">
      <p v-if="form.errors.concurrency" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ form.errors.concurrency }}</p>
      <p v-if="form.errors.cash_flow_category_id || form.errors.party_id || form.errors.contract_id" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
        {{ form.errors.cash_flow_category_id || form.errors.party_id || form.errors.contract_id }}
      </p>

      <div class="rounded-lg bg-gray-50 p-3 text-sm">
        <p class="text-gray-500">{{ transaction.transaction_date }} · {{ transaction.bank_account_name }}</p>
        <p class="font-medium text-gray-900">{{ transaction.description }}</p>
        <p class="text-right font-semibold" :class="transaction.credit > 0 ? 'text-green-600' : 'text-red-600'">
          {{ formatVnd(transaction.credit > 0 ? transaction.credit : -transaction.debit) }}
        </p>
        <p v-if="transaction.is_paired && transaction.paired_with" class="mt-2 border-t border-gray-200 pt-2 text-xs text-purple-700">
          Đã cặp đôi nội bộ với: {{ transaction.paired_with.bank_account_name }} ·
          {{ transaction.paired_with.transaction_date }} · {{ formatVnd(transaction.paired_with.amount) }}
        </p>
        <p v-else-if="transaction.reconcile_status === 'needs_review'" class="mt-2 border-t border-gray-200 pt-2 text-xs text-red-600">
          ⚠ Chuyển nội bộ – chưa tìm thấy giao dịch đối ứng. Dùng "Gợi ý cặp chuyển khoản nội bộ" ở trang danh sách.
        </p>
      </div>

      <FormField label="Nguồn tiền / Mục đích chi">
        <select v-model="form.cash_flow_category_id" class="erp-input">
          <option :value="null">-- Chưa xác định --</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </FormField>

      <FormField label="Loại đối tượng">
        <select v-model="form.party_type" class="erp-input" @change="form.party_id = null; form.party_name = ''">
          <option :value="null">-- Chưa xác định --</option>
          <option value="customer">Khách hàng</option>
          <option value="supplier">Nhà cung cấp</option>
          <option value="employee">Nhân viên</option>
          <option value="shareholder">Cổ đông</option>
          <option value="bank">Ngân hàng</option>
          <option value="other_individual">Cá nhân khác</option>
          <option value="other_entity">Đơn vị khác</option>
        </select>
      </FormField>

      <FormField v-if="['customer','supplier','employee'].includes(form.party_type)" label="Đối tượng">
        <RemoteSearchSelect
          v-model="form.party_id"
          :display-text="form.party_name"
          :search-url="route(`search.${partySearchEntity}`)"
          @change="opt => { form.party_name = opt?.label ?? '' }"
        />
      </FormField>
      <FormField v-else-if="form.party_type" label="Tên đối tượng">
        <input v-model="form.party_name" class="erp-input" placeholder="Nhập tên..." />
      </FormField>
      <!-- spec §2 — luôn hiện rõ CẢ tên lẫn loại đối tượng đã chọn, tránh nhầm 2 đối
           tượng trùng tên khác loại (vd 1 KH và 1 NCC cùng tên "Công ty ABC"). -->
      <p v-if="form.party_type && form.party_name" class="-mt-2 text-xs text-gray-500">
        Đối tượng: <span class="font-medium text-gray-700">{{ form.party_name }}</span> —
        Loại: <span class="font-medium text-gray-700">{{ partyTypeLabel }}</span>
      </p>

      <FormField label="Dự án">
        <RemoteSearchSelect
          v-model="form.project_id"
          :display-text="transaction.project_name ?? ''"
          :search-url="route('search.projects')"
        />
      </FormField>

      <FormField label="Loại hợp đồng">
        <select v-model="form.contract_type" class="erp-input" @change="form.contract_id = null">
          <option :value="null">-- Không liên kết hợp đồng --</option>
          <option value="contract">Hợp đồng bán</option>
          <option value="purchase_contract">Hợp đồng mua</option>
        </select>
      </FormField>
      <FormField v-if="form.contract_type" label="Hợp đồng">
        <RemoteSearchSelect
          v-model="form.contract_id"
          :display-text="transaction.contract_label ?? ''"
          :search-url="route(form.contract_type === 'contract' ? 'search.contracts' : 'search.purchase-contracts')"
          placeholder="Tìm theo mã/tên hợp đồng..."
        />
      </FormField>

      <FormField label="Người phụ trách">
        <RemoteSearchSelect
          v-model="form.responsible_user_id"
          :display-text="transaction.responsible_user_name ?? ''"
          :search-url="route('search.users')"
          placeholder="Tìm theo tên/email..."
        />
      </FormField>

      <FormField label="Ghi chú">
        <textarea v-model="form.cash_flow_note" rows="2" class="erp-input"></textarea>
      </FormField>
    </div>

    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('close')">Hủy</button>
      <button class="erp-btn-primary" :disabled="form.processing" @click="submit">Lưu</button>
    </template>
  </Modal>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { formatVnd } from '@/composables/useCurrency';

const props = defineProps({
  transaction: { type: Object, required: true },
  categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved']);

const PARTY_TYPE_LABELS = {
  customer: 'Khách hàng', supplier: 'Nhà cung cấp', employee: 'Nhân viên',
  shareholder: 'Cổ đông', bank: 'Ngân hàng', other_individual: 'Cá nhân khác', other_entity: 'Đơn vị khác',
};

const form = useForm({
  cash_flow_category_id: props.transaction.category_id,
  party_type: props.transaction.party_type,
  party_id: props.transaction.party_id,
  party_name: props.transaction.party_name,
  project_id: props.transaction.project_id,
  contract_type: props.transaction.contract_type,
  contract_id: props.transaction.contract_id,
  responsible_user_id: props.transaction.responsible_user_id,
  cash_flow_note: props.transaction.cash_flow_note,
  // spec §12 — chống ghi đè âm thầm nếu người khác đã sửa giao dịch này sau khi
  // trang được tải. Backend so sánh với updated_at hiện tại của bản ghi.
  expected_updated_at: props.transaction.updated_at,
});

const partySearchEntity = computed(() => (form.party_type === 'customer' ? 'customers' : form.party_type === 'supplier' ? 'suppliers' : 'employees'));
const partyTypeLabel = computed(() => PARTY_TYPE_LABELS[form.party_type] ?? '');

function submit() {
  form.post(route('reports.company-cashflow.classify', props.transaction.id), {
    preserveScroll: true,
    onSuccess: () => emit('saved'),
  });
}
</script>
