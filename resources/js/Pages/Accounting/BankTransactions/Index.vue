<template>
  <AppLayout :title="`Giao dịch — ${bankAccount.name}`">
    <div class="max-w-6xl mx-auto">

      <!-- Header -->
      <div class="flex items-center gap-4 mb-6">
        <Link :href="route('accounting.bank-accounts.index')" class="text-gray-500 hover:text-gray-700">←</Link>
        <div class="flex-1">
          <h1 class="text-2xl font-bold text-gray-900">{{ bankAccount.name }}</h1>
          <p class="text-sm text-gray-500">{{ bankAccount.bank_name }} · {{ bankAccount.account_number }} · TK {{ bankAccount.account_code }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs text-gray-500">Số dư hiện tại</p>
          <p class="text-xl font-bold" :class="bankAccount.balance >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatVnd(bankAccount.balance) }}
          </p>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex justify-end gap-2 mb-3 flex-wrap">
        <ExportExcelButton
          :endpoint="route('accounting.bank-accounts.transactions.export-excel', props.bankAccount.id)"
          :filters="filters" />
        <button v-if="can('accounting.manage')" @click="submitMatchAll" class="erp-btn-secondary flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Tự động đối soát
        </button>
        <button v-if="can('accounting.manage')" @click="showRecategorize = true" class="erp-btn-secondary flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Phân loại lại
        </button>
        <button v-if="can('accounting.manage')" @click="showImport = true" class="erp-btn-secondary flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          Import Excel
        </button>
      </div>

      <!-- Add transaction form -->
      <div v-if="can('accounting.manage')" class="bg-white rounded-xl shadow-sm p-5 mb-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Thêm giao dịch ngân hàng</h3>
        <form @submit.prevent="submitAdd" class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
          <div class="col-span-2">
            <label class="form-label text-xs">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="addForm.description" class="form-input text-sm" placeholder="Thu tiền khách hàng..." />
          </div>
          <div>
            <label class="form-label text-xs">Ngày <span class="text-red-500">*</span></label>
            <input v-model="addForm.transaction_date" type="date" class="form-input text-sm" />
          </div>
          <div>
            <label class="form-label text-xs">Tiền vào (Có)</label>
            <input v-model.number="addForm.credit" type="number" min="0" step="any" class="form-input text-sm" placeholder="0" />
          </div>
          <div>
            <label class="form-label text-xs">Tiền ra (Nợ)</label>
            <input v-model.number="addForm.debit" type="number" min="0" step="any" class="form-input text-sm" placeholder="0" />
          </div>
          <div>
            <button type="submit" :disabled="addForm.processing" class="btn-primary text-sm w-full">
              {{ addForm.processing ? '...' : 'Thêm' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
          <label class="form-label text-xs">TK đối tác</label>
          <input v-model="filters.counterpart" @keydown.enter="applyFilters"
            class="form-input text-sm" placeholder="Số TK, tên người chuyển/nhận..." />
        </div>
        <div>
          <label class="form-label text-xs">Loại GD</label>
          <select v-model="filters.tx_type" class="form-input text-sm">
            <option value="">Tất cả</option>
            <option value="supplier_payment">Thanh toán NCC</option>
            <option value="internal_transfer">CK nội bộ</option>
            <option value="customer_receipt">Thu KH</option>
            <option value="unknown">Chưa phân loại</option>
          </select>
        </div>
        <div>
          <label class="form-label text-xs">Đối soát</label>
          <select v-model="filters.match_status" class="form-input text-sm">
            <option value="">Tất cả</option>
            <option v-for="s in matchStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label text-xs">ĐC kế toán</label>
          <select v-model="filters.status" class="form-input text-sm">
            <option value="">Tất cả</option>
            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label text-xs">Từ ngày</label>
          <input v-model="filters.date_from" type="date" class="form-input text-sm" />
        </div>
        <div>
          <label class="form-label text-xs">Đến ngày</label>
          <input v-model="filters.date_to" type="date" class="form-input text-sm" />
        </div>
        <button @click="applyFilters" class="btn-secondary text-sm">Lọc</button>
      </div>

      <!-- Alert: internal transfers -->
      <div v-if="alertCount > 0" class="mb-4 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
        <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <p class="text-sm font-semibold text-amber-800">{{ alertCount }} chuyển khoản nội bộ cần hồ sơ đối ứng</p>
      </div>

      <!-- Transactions table -->
      <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
              <th class="px-4 py-3 text-left w-24">Ngày</th>
              <th class="px-4 py-3 text-left">Diễn giải / Đối tác</th>
              <th class="px-4 py-3 text-left w-28">Loại GD</th>
              <th class="px-4 py-3 text-right w-32">Tiền vào (+)</th>
              <th class="px-4 py-3 text-right w-32">Tiền ra (−)</th>
              <th class="px-4 py-3 text-left w-44">Đối soát tự động</th>
              <th class="px-4 py-3 text-center w-24">ĐC kế toán</th>
              <th class="px-4 py-3 text-left w-24">Phiếu KT</th>
              <th v-if="can('accounting.manage')" class="px-4 py-3 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="tx in transactions.data" :key="tx.id"
              class="hover:bg-gray-50"
              :class="tx.alert_note ? 'bg-amber-50/40' : ''">
              <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ tx.transaction_date }}</td>
              <td class="px-4 py-3">
                <div v-if="tx.alert_note" class="flex items-start gap-1.5 mb-1">
                  <svg class="w-3.5 h-3.5 text-amber-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                  </svg>
                  <span class="text-xs text-amber-700">{{ tx.alert_note }}</span>
                </div>
                <div class="text-sm text-gray-800">{{ tx.description }}</div>
                <div v-if="tx.counterpart_account" class="text-xs text-gray-400 mt-0.5">
                  <span class="font-mono">{{ tx.counterpart_account }}</span>
                  <span v-if="tx.counterpart_name"> · {{ tx.counterpart_name }}</span>
                  <span v-if="tx.counterpart_bank"> ({{ tx.counterpart_bank.trim() }})</span>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border"
                  :class="{
                    'bg-orange-50 text-orange-700 border-orange-200': tx.tx_type === 'supplier_payment',
                    'bg-purple-50 text-purple-700 border-purple-200': tx.tx_type === 'internal_transfer',
                    'bg-green-50 text-green-700 border-green-200':   tx.tx_type === 'customer_receipt',
                    'bg-slate-100 text-slate-500 border-slate-200':  tx.tx_type === 'unknown' || !tx.tx_type,
                  }">
                  {{ tx.tx_type_label }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-green-600 font-medium text-sm">
                {{ tx.credit > 0 ? formatVnd(tx.credit) : '' }}
              </td>
              <td class="px-4 py-3 text-right text-red-600 font-medium text-sm">
                {{ tx.debit > 0 ? formatVnd(tx.debit) : '' }}
              </td>
              <!-- Matching column -->
              <td class="px-4 py-3">
                <StatusBadge :color="tx.match_status_color" class="text-xs">{{ tx.match_status_label }}</StatusBadge>
                <div v-if="tx.matched_party_name" class="text-xs text-gray-600 mt-0.5 truncate max-w-[160px]">
                  {{ tx.matched_party_name }}
                  <span v-if="tx.confidence_score" class="text-gray-400">({{ tx.confidence_score }}%)</span>
                </div>
                <div v-if="tx.suggested_tx_type" class="text-xs text-gray-400">{{ txTypeLabel(tx.suggested_tx_type) }}</div>
              </td>
              <td class="px-4 py-3 text-center">
                <span :class="`badge-${tx.status_color}`">{{ tx.status_label }}</span>
              </td>
              <td class="px-4 py-3">
                <span v-if="tx.journal_entry_code" class="text-xs font-mono text-primary-600">{{ tx.journal_entry_code }}</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <!-- Actions -->
              <td v-if="can('accounting.manage')" class="px-4 py-3 whitespace-nowrap text-right">
                <!-- Auto-match flow -->
                <template v-if="tx.match_status === 'suggested'">
                  <button @click="openConfirmMatch(tx)" class="text-xs text-blue-600 hover:underline mr-2">Xác nhận đề xuất</button>
                  <button @click="ignore(tx)" class="text-xs text-gray-400 hover:underline mr-2">Bỏ qua</button>
                </template>
                <template v-else-if="tx.match_status === 'confirmed'">
                  <button @click="createJe(tx)" class="text-xs text-green-600 hover:underline font-medium mr-2">Tạo BT</button>
                  <button @click="ignore(tx)" class="text-xs text-gray-400 hover:underline">Hủy</button>
                </template>
                <!-- Manual allocation flow -->
                <template v-else-if="['unmatched','ignored','cancelled'].includes(tx.match_status)">
                  <button @click="openReconcile(tx)" class="text-xs text-primary-600 hover:underline">Đối chiếu</button>
                </template>
                <template v-else-if="tx.match_status === 'partially_matched'">
                  <button @click="openReconcile(tx)" class="text-xs text-primary-600 hover:underline mr-2">Xem phân bổ</button>
                  <button @click="cancelAlloc(tx)" class="text-xs text-red-500 hover:underline">Hủy ĐC</button>
                </template>
                <template v-else-if="tx.match_status === 'posted'">
                  <button @click="cancelAlloc(tx)" class="text-xs text-red-500 hover:underline">Hủy ĐC</button>
                </template>
                <template v-else-if="tx.status === 'reconciled'">
                  <button @click="unreconcile(tx)" class="text-xs text-red-500 hover:underline">Hủy ĐC</button>
                </template>
              </td>
            </tr>
            <tr v-if="transactions.data.length === 0">
              <td :colspan="can('accounting.manage') ? 9 : 8" class="px-4 py-10 text-center text-gray-400">
                Chưa có giao dịch
              </td>
            </tr>
          </tbody>
        </table>
        <Pagination :links="transactions.links" class="px-4 py-3" />
      </div>

      <!-- Import Excel modal -->
      <div v-if="showImport" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Import sao kê Techcombank</h3>
          <p class="text-sm text-gray-500 mb-4">Tải file Excel (.xlsx) từ Internet Banking Techcombank → Tra cứu lịch sử giao dịch → Xuất Excel.</p>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Chọn file Excel <span class="text-red-500">*</span></label>
            <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" @change="onFileChange"
              class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
            <p v-if="importFile" class="text-xs text-gray-500 mt-1">{{ importFile.name }} ({{ (importFile.size / 1024).toFixed(1) }} KB)</p>
          </div>
          <div v-if="importError" class="mb-3 text-sm text-red-600 bg-red-50 px-3 py-2 rounded-lg">{{ importError }}</div>
          <div class="bg-blue-50 rounded-lg px-3 py-2 mb-4 text-xs text-blue-700">Giao dịch trùng sẽ tự động bỏ qua.</div>
          <div class="flex gap-3">
            <button @click="submitImport" :disabled="!importFile || importing" class="erp-btn-primary flex-1">
              {{ importing ? 'Đang import...' : 'Import' }}
            </button>
            <button @click="closeImport" class="erp-btn-secondary">Hủy</button>
          </div>
        </div>
      </div>

      <!-- Recategorize modal -->
      <div v-if="showRecategorize" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-2">Phân loại lại giao dịch</h3>
          <p class="text-sm text-gray-600 mb-4">Tìm giao dịch chưa phân loại và so khớp lại với TK nội bộ / NCC hiện tại.</p>
          <div class="flex gap-3">
            <button @click="submitRecategorize" class="erp-btn-primary flex-1">Chạy phân loại</button>
            <button @click="showRecategorize = false" class="erp-btn-secondary">Hủy</button>
          </div>
        </div>
      </div>

      <!-- Confirm Match modal -->
      <div v-if="confirmTarget" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-lg">
          <h3 class="text-lg font-bold text-gray-900 mb-3">Xác nhận đề xuất đối soát</h3>
          <div class="bg-gray-50 rounded-lg p-3 mb-4 text-sm">
            <p class="font-medium">{{ confirmTarget.description }}</p>
            <p class="text-gray-500 text-xs mt-1">
              {{ confirmTarget.transaction_date }} |
              <span v-if="confirmTarget.credit > 0" class="text-green-600">+{{ formatVnd(confirmTarget.credit) }}</span>
              <span v-else class="text-red-600">-{{ formatVnd(confirmTarget.debit) }}</span>
            </p>
          </div>
          <div class="space-y-3">
            <div>
              <label class="form-label text-xs">Đối tượng (hệ thống đề xuất)</label>
              <p class="text-sm font-medium text-gray-800">
                {{ confirmTarget.matched_party_name ?? '—' }}
                <span class="text-xs text-gray-400 ml-1">({{ confirmTarget.matched_party_type }})</span>
              </p>
            </div>
            <div>
              <label class="form-label text-xs">Loại nghiệp vụ</label>
              <select v-model="confirmForm.tx_type" class="form-input text-sm">
                <option value="customer_receipt">Thu từ khách hàng (N1121/C131)</option>
                <option value="customer_advance_receipt">KH trả trước (N1121/C131UT)</option>
                <option value="supplier_payment">Trả NCC (N3311/C1121)</option>
                <option value="supplier_advance_payment">Trả trước NCC (N331UT/C1121)</option>
                <option value="supplier_refund">NCC hoàn tiền (N1121/C331UT)</option>
                <option value="other">Khác</option>
              </select>
            </div>
            <div>
              <label class="form-label text-xs">Ghi chú</label>
              <input v-model="confirmForm.match_note" class="form-input text-sm" placeholder="Ghi chú tùy chọn..." />
            </div>
          </div>
          <div class="flex gap-3 mt-4">
            <button @click="submitConfirmMatch" class="erp-btn-primary flex-1">Xác nhận</button>
            <button @click="submitConfirmAndCreateJe" class="erp-btn-success flex-1">Xác nhận & Tạo BT</button>
            <button @click="confirmTarget = null" class="erp-btn-secondary">Hủy</button>
          </div>
        </div>
      </div>

      <!-- Allocation / Reconciliation modal -->
      <ReconciliationModal
        :transaction="reconcileTarget"
        :bank-account-id="bankAccount.id"
        @close="reconcileTarget = null"
      />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import ReconciliationModal from './ReconciliationModal.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();

const props = defineProps({
  bankAccount:  Object,
  transactions: Object,
  filters:      Object,
  statuses:     Array,
  matchStatuses: Array,
  alertCount:   { type: Number, default: 0 },
});

const filters = ref({
  counterpart:  props.filters.counterpart  ?? '',
  tx_type:      props.filters.tx_type      ?? '',
  status:       props.filters.status       ?? '',
  match_status: props.filters.match_status ?? '',
  date_from:    props.filters.date_from    ?? '',
  date_to:      props.filters.date_to      ?? '',
});

const addForm = useForm({
  transaction_date: new Date().toISOString().split('T')[0],
  description: '', reference: '', debit: 0, credit: 0,
});

// ─── Matching ────────────────────────────────────────────────────────────────

const confirmTarget = ref(null);
const confirmForm   = ref({ tx_type: '', match_note: '' });

function openConfirmMatch(tx) {
  confirmTarget.value = tx;
  confirmForm.value   = { tx_type: tx.suggested_tx_type ?? '', match_note: tx.match_note ?? '' };
}

function submitConfirmMatch() {
  router.post(
    route('accounting.bank-accounts.transactions.confirm-match', [props.bankAccount.id, confirmTarget.value.id]),
    confirmForm.value,
    { onSuccess: () => { confirmTarget.value = null; } }
  );
}

function submitConfirmAndCreateJe() {
  router.post(
    route('accounting.bank-accounts.transactions.confirm-match', [props.bankAccount.id, confirmTarget.value.id]),
    confirmForm.value,
    {
      onSuccess: () => {
        const txId = confirmTarget.value.id;
        confirmTarget.value = null;
        router.post(route('accounting.bank-accounts.transactions.create-journal-entry', [props.bankAccount.id, txId]));
      },
    }
  );
}

function createJe(tx) {
  if (!confirm(`Tạo bút toán cho giao dịch "${tx.description}"?`)) return;
  router.post(route('accounting.bank-accounts.transactions.create-journal-entry', [props.bankAccount.id, tx.id]));
}

function ignore(tx) {
  router.post(route('accounting.bank-accounts.transactions.ignore-match', [props.bankAccount.id, tx.id]));
}

function submitMatchAll() {
  router.post(route('accounting.bank-accounts.transactions.match-all', props.bankAccount.id));
}

function txTypeLabel(type) {
  const map = {
    customer_receipt:         'Thu KH → N1121/C131',
    customer_advance_receipt: 'KH trả trước → N1121/C131UT',
    supplier_payment:         'Trả NCC → N3311/C1121',
    supplier_advance_payment: 'Trả trước NCC → N331UT/C1121',
    supplier_refund:          'NCC hoàn → N1121/C331UT',
  };
  return map[type] ?? type;
}

// ─── Allocation (manual reconcile) ───────────────────────────────────────────

const reconcileTarget  = ref(null);
const showRecategorize = ref(false);

function openReconcile(tx) { reconcileTarget.value = tx; }

function cancelAlloc(tx) {
  if (!confirm('Hủy đối chiếu và tạo bút toán đảo?')) return;
  router.post(route('accounting.bank-accounts.transactions.cancel-allocation', [props.bankAccount.id, tx.id]));
}

function submitRecategorize() {
  router.post(
    route('accounting.bank-accounts.transactions.recategorize', props.bankAccount.id),
    {},
    { onSuccess: () => { showRecategorize.value = false; } }
  );
}

function unreconcile(tx) {
  router.post(route('accounting.bank-accounts.transactions.unreconcile', [props.bankAccount.id, tx.id]));
}

// ─── Import Excel ─────────────────────────────────────────────────────────────

const showImport  = ref(false);
const importFile  = ref(null);
const importError = ref('');
const importing   = ref(false);
const fileInput   = ref(null);

function onFileChange(e) { importFile.value = e.target.files[0] ?? null; importError.value = ''; }

function closeImport() {
  showImport.value = false; importFile.value = null; importError.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

function submitImport() {
  if (!importFile.value) return;
  importing.value = true; importError.value = '';
  const fd = new FormData();
  fd.append('excel_file', importFile.value);
  router.post(route('accounting.bank-accounts.transactions.import-excel', props.bankAccount.id), fd, {
    forceFormData: true,
    onSuccess: () => closeImport(),
    onError: (errors) => { importError.value = errors.excel_file ?? errors.message ?? 'Import thất bại.'; },
    onFinish: () => { importing.value = false; },
  });
}

// ─── Filters & helpers ────────────────────────────────────────────────────────

function applyFilters() {
  router.get(route('accounting.bank-accounts.transactions.index', props.bankAccount.id),
    { ...filters.value }, { preserveState: true });
}

function submitAdd() {
  addForm.post(route('accounting.bank-accounts.transactions.store', props.bankAccount.id), {
    onSuccess: () => addForm.reset('description', 'debit', 'credit', 'reference'),
  });
}

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
