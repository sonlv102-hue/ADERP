<template>
  <Modal :show="!!transaction" max-width="2xl" @close="$emit('close')">
    <template #title>Đối chiếu giao dịch ngân hàng</template>

    <!-- Loading -->
    <div v-if="loading" class="py-8 text-center text-gray-400 text-sm">Đang tải dữ liệu...</div>

    <div v-else class="space-y-5">
      <!-- I. Thông tin giao dịch -->
      <div class="bg-gray-50 rounded-lg p-4 text-sm space-y-1">
        <div class="flex justify-between">
          <span class="text-gray-500">Ngày:</span>
          <span class="font-medium">{{ transaction?.transaction_date }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Diễn giải:</span>
          <span class="font-medium text-right max-w-[60%]">{{ transaction?.description }}</span>
        </div>
        <div v-if="transaction?.counterpart_account" class="flex justify-between">
          <span class="text-gray-500">TK đối ứng:</span>
          <span class="font-mono text-xs">{{ transaction?.counterpart_account }} · {{ transaction?.counterpart_name }}</span>
        </div>
        <div class="flex justify-between border-t border-gray-200 pt-2 mt-2">
          <span class="text-gray-500">{{ transaction?.credit > 0 ? 'Tiền vào' : 'Tiền ra' }}:</span>
          <span class="font-bold text-lg" :class="transaction?.credit > 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatVnd(Math.max(transaction?.credit ?? 0, transaction?.debit ?? 0)) }}
          </span>
        </div>
      </div>

      <!-- II. Đối tượng -->
      <div class="space-y-2">
        <h4 class="text-sm font-semibold text-gray-700">Đối tượng</h4>
        <div v-if="reconcileData?.party" class="flex items-center gap-3 bg-blue-50 rounded-lg px-4 py-2">
          <div class="flex-1">
            <span class="text-sm font-medium text-blue-800">{{ reconcileData.party.name }}</span>
            <span class="ml-2 text-xs text-blue-500">({{ reconcileData.party.code }})</span>
            <span v-if="reconcileData.party.confidence_score" class="ml-2 text-xs text-blue-400">
              Độ tin cậy: {{ reconcileData.party.confidence_score }}%
            </span>
          </div>
          <button @click="clearParty" class="text-xs text-blue-600 hover:underline">Đổi</button>
        </div>
        <div v-else class="grid grid-cols-2 gap-3">
          <div>
            <label class="form-label text-xs">Loại đối tượng</label>
            <select v-model="manualPartyType" class="erp-input text-sm">
              <option value="">-- Chọn loại --</option>
              <option value="customer">Khách hàng</option>
              <option value="supplier">Nhà cung cấp</option>
            </select>
          </div>
          <div v-if="manualPartyType">
            <label class="form-label text-xs">{{ manualPartyType === 'customer' ? 'Khách hàng' : 'Nhà cung cấp' }}</label>
            <RemoteSearchSelect
              v-model="manualPartyId"
              :display-text="manualPartyName"
              :search-url="manualPartyType === 'customer' ? route('search.customers') : route('search.suppliers')"
              :placeholder="manualPartyType === 'customer' ? 'Tìm khách hàng...' : 'Tìm nhà cung cấp...'"
              @change="opt => { manualPartyName = opt?.label ?? ''; loadReconcileData(); }"
            />
          </div>
        </div>
      </div>

      <!-- III. Chứng từ liên quan -->
      <div v-if="reconcileData?.party">
        <div class="flex items-center justify-between mb-2">
          <h4 class="text-sm font-semibold text-gray-700">Chứng từ còn phải {{ reconcileData.is_credit ? 'thu' : 'trả' }}</h4>
          <span v-if="reconcileData.documents.length === 0" class="text-xs text-gray-400">Không có chứng từ mở</span>
        </div>
        <div v-if="reconcileData.documents.length" class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-3 py-2 w-8"></th>
                <th class="px-3 py-2 text-left">Số CT</th>
                <th class="px-3 py-2 text-left">Ngày</th>
                <th class="px-3 py-2 text-left">Diễn giải</th>
                <th class="px-3 py-2 text-left">TK</th>
                <th class="px-3 py-2 text-right">Tổng tiền</th>
                <th class="px-3 py-2 text-right">Đã TT</th>
                <th class="px-3 py-2 text-right">Còn lại</th>
                <th class="px-3 py-2 text-right w-28">Phân bổ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="doc in reconcileData.documents" :key="`${doc.type}-${doc.id}`"
                class="hover:bg-gray-50" :class="isSelected(doc) ? 'bg-blue-50' : ''">
                <td class="px-3 py-2 text-center">
                  <input type="checkbox" :checked="isSelected(doc)" @change="toggleDoc(doc)" class="rounded" />
                </td>
                <td class="px-3 py-2 font-mono font-medium text-primary-700">{{ doc.code }}</td>
                <td class="px-3 py-2 text-gray-500 whitespace-nowrap">{{ formatDate(doc.date) }}</td>
                <td class="px-3 py-2 text-gray-700 max-w-[180px] truncate">{{ doc.description }}</td>
                <td class="px-3 py-2 text-gray-500">{{ doc.account_code }}</td>
                <td class="px-3 py-2 text-right font-medium">{{ formatVnd(doc.total) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ doc.amount_paid > 0 ? formatVnd(doc.amount_paid) : '—' }}</td>
                <td class="px-3 py-2 text-right text-orange-600 font-semibold">{{ formatVnd(doc.amount_remaining) }}</td>
                <td class="px-3 py-2">
                  <input v-if="isSelected(doc)"
                    v-model.number="getAllocAmount(doc).value"
                    type="number" min="0" :max="doc.amount_remaining"
                    class="erp-input text-right text-xs py-1 px-2 w-full"
                    @input="clampAlloc(doc)" />
                  <span v-else class="text-gray-300 text-right block">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- IV. Kết quả phân bổ -->
      <div v-if="selectedDocs.length" class="bg-gray-50 rounded-lg px-4 py-3 text-sm space-y-1">
        <div class="flex justify-between">
          <span class="text-gray-500">Tổng giao dịch:</span>
          <span class="font-medium">{{ formatVnd(reconcileData.tx_amount) }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Tổng phân bổ:</span>
          <span class="font-semibold" :class="totalAllocated > reconcileData.tx_amount ? 'text-red-600' : 'text-primary-700'">
            {{ formatVnd(totalAllocated) }}
          </span>
        </div>
        <div class="flex justify-between border-t border-gray-200 pt-1 mt-1">
          <span class="text-gray-500">Còn chưa phân bổ:</span>
          <span :class="unallocated > 0 ? 'text-yellow-600 font-medium' : 'text-green-600 font-semibold'">
            {{ formatVnd(unallocated) }}
          </span>
        </div>
        <div v-if="totalAllocated > reconcileData.tx_amount" class="text-red-600 text-xs font-medium pt-1">
          ⚠ Tổng phân bổ vượt quá số tiền giao dịch
        </div>
      </div>

      <div v-if="errors.general" class="text-red-600 text-sm bg-red-50 px-3 py-2 rounded-lg">{{ errors.general }}</div>
    </div>

    <template #footer>
      <button @click="$emit('close')" class="erp-btn-secondary">Hủy</button>
      <button v-if="selectedDocs.length" @click="submit"
        :disabled="submitting || totalAllocated > (reconcileData?.tx_amount ?? 0) || totalAllocated <= 0"
        class="erp-btn-primary">
        {{ submitting ? 'Đang xử lý...' : 'Xác nhận đối chiếu' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Shared/Modal.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

const props = defineProps({
  transaction:  Object,   // { id, bank_account_id, description, transaction_date, credit, debit, counterpart_account, counterpart_name, matched_party_type, matched_party_id }
  bankAccountId: Number,
});
const emit = defineEmits(['close']);

const loading          = ref(false);
const submitting       = ref(false);
const errors           = ref({});
const reconcileData    = ref(null);
const selectedDocs     = ref([]);   // [{ doc, amount }]
const manualPartyType  = ref('');
const manualPartyId    = ref(null);
const manualPartyName  = ref('');

watch(() => props.transaction, async (tx) => {
  if (!tx) return;
  selectedDocs.value  = [];
  manualPartyType.value = '';
  manualPartyId.value = null;
  manualPartyName.value = '';
  errors.value = {};
  await loadReconcileData();
}, { immediate: true });

async function loadReconcileData() {
  if (!props.transaction) return;
  loading.value = true;
  const params = new URLSearchParams();
  if (manualPartyType.value && manualPartyId.value) {
    params.set('party_type', manualPartyType.value);
    params.set('party_id', manualPartyId.value);
  }
  try {
    const url = route('accounting.bank-accounts.transactions.reconcile-data',
      [props.bankAccountId, props.transaction.id]) + (params.toString() ? '?' + params : '');
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    reconcileData.value = await res.json();
    selectedDocs.value = [];
  } catch {
    errors.value = { general: 'Không thể tải dữ liệu. Vui lòng thử lại.' };
  } finally {
    loading.value = false;
  }
}

function clearParty() {
  reconcileData.value = { ...reconcileData.value, party: null, documents: [] };
  manualPartyType.value = '';
  manualPartyId.value = null;
  selectedDocs.value = [];
}

function isSelected(doc) {
  return selectedDocs.value.some(s => s.doc.type === doc.type && s.doc.id === doc.id);
}

function getAllocAmount(doc) {
  const found = selectedDocs.value.find(s => s.doc.type === doc.type && s.doc.id === doc.id);
  return found ? { value: found.amount, set: v => { found.amount = v; } } : { value: 0 };
}

function toggleDoc(doc) {
  const idx = selectedDocs.value.findIndex(s => s.doc.type === doc.type && s.doc.id === doc.id);
  if (idx >= 0) {
    selectedDocs.value.splice(idx, 1);
    return;
  }
  const remaining = (reconcileData.value?.tx_amount ?? 0) - totalAllocated.value;
  const suggested = Math.min(doc.amount_remaining, Math.max(0, remaining));
  selectedDocs.value.push({ doc, amount: suggested });
}

function clampAlloc(doc) {
  const s = selectedDocs.value.find(e => e.doc.type === doc.type && e.doc.id === doc.id);
  if (!s) return;
  if (s.amount > doc.amount_remaining) s.amount = doc.amount_remaining;
  if (s.amount < 0) s.amount = 0;
}

const totalAllocated = computed(() => selectedDocs.value.reduce((sum, s) => sum + (parseFloat(s.amount) || 0), 0));
const unallocated = computed(() => Math.max(0, (reconcileData.value?.tx_amount ?? 0) - totalAllocated.value));

async function submit() {
  if (!reconcileData.value?.party) return;
  errors.value = {};
  submitting.value = true;

  const party = reconcileData.value.party;
  const allocations = selectedDocs.value.map(s => ({
    type:         s.doc.type,
    id:           s.doc.id,
    account_code: s.doc.account_code,
    amount:       Math.round(parseFloat(s.amount) || 0),
    description:  `${s.doc.code}: ${props.transaction.description}`,
  }));

  router.post(
    route('accounting.bank-accounts.transactions.allocate', [props.bankAccountId, props.transaction.id]),
    { party_type: party.type, party_id: party.id, allocations },
    {
      onSuccess: () => emit('close'),
      onError: (e) => { errors.value = e; },
      onFinish: () => { submitting.value = false; },
    }
  );
}

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}

function formatDate(d) {
  if (!d) return '—';
  const [y, m, day] = d.split('-');
  return `${day}/${m}/${y}`;
}
</script>
