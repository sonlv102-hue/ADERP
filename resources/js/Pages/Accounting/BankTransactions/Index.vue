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

      <!-- Export + Import Excel + Recategorize buttons -->
      <div class="flex justify-end gap-2 mb-3 flex-wrap">
        <ExportExcelButton
          :endpoint="route('accounting.bank-accounts.transactions.export-excel', props.bankAccount.id)"
          :filters="filters" />
        <button v-if="can('accounting.manage')" @click="showRecategorize = true" class="erp-btn-secondary flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Phân loại lại
        </button>
        <button v-if="can('accounting.manage')" @click="showImport = true" class="erp-btn-secondary flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          Import Excel Techcombank
        </button>
      </div>

      <!-- Add transaction form (accounting.manage only) -->
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
            <label class="form-label text-xs">Số tiền vào (Có)</label>
            <input v-model.number="addForm.credit" type="number" min="0" step="any" class="form-input text-sm" placeholder="0" />
          </div>
          <div>
            <label class="form-label text-xs">Số tiền ra (Nợ)</label>
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
          <label class="form-label text-xs">TK đối tác (số TK hoặc tên)</label>
          <div class="relative">
            <input v-model="filters.counterpart" @keydown.enter="applyFilters"
              class="form-input text-sm pr-8" placeholder="Tìm số TK, tên người chuyển/nhận..." />
            <button v-if="filters.counterpart" @click="filters.counterpart = ''; applyFilters()"
              class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none">×</button>
          </div>
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
          <label class="form-label text-xs">Trạng thái ĐC</label>
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

      <!-- Active counterpart filter chip -->
      <div v-if="props.filters.counterpart" class="mb-3">
        <span class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 text-xs font-semibold px-3 py-1.5 rounded-full border border-blue-200">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          Đối tác: "{{ props.filters.counterpart }}"
          <button @click="filters.counterpart = ''; applyFilters()" class="ml-1 hover:text-blue-900">✕</button>
        </span>
      </div>

      <!-- Alert summary for internal transfers -->
      <div v-if="alertCount > 0" class="mb-4 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
        <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="text-sm font-semibold text-amber-800">{{ alertCount }} chuyển khoản nội bộ cần hồ sơ đối ứng</p>
          <p class="text-xs text-amber-600 mt-0.5">Lọc theo "CK nội bộ" để xem chi tiết và xác nhận từng giao dịch.</p>
        </div>
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
              <th class="px-4 py-3 text-center w-24">Đối chiếu</th>
              <th class="px-4 py-3 text-left w-24">Phiếu KT</th>
              <th v-if="can('accounting.manage')" class="px-4 py-3 w-20"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="tx in transactions.data" :key="tx.id"
              class="hover:bg-gray-50"
              :class="tx.alert_note ? 'bg-amber-50/40' : ''">
              <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ tx.transaction_date }}</td>
              <td class="px-4 py-3">
                <!-- Alert badge for internal transfers -->
                <div v-if="tx.alert_note" class="flex items-start gap-1.5 mb-1">
                  <svg class="w-3.5 h-3.5 text-amber-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                  </svg>
                  <span class="text-xs text-amber-700">{{ tx.alert_note }}</span>
                </div>
                <div class="text-sm text-gray-800">{{ tx.description }}</div>
                <!-- Counterpart info -->
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
                    'bg-slate-100 text-slate-500 border-slate-200':  tx.tx_type === 'unknown',
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
              <td class="px-4 py-3 text-center">
                <span :class="`badge-${tx.status_color}`">{{ tx.status_label }}</span>
              </td>
              <td class="px-4 py-3">
                <span v-if="tx.journal_entry_code" class="text-xs font-mono text-primary-600">{{ tx.journal_entry_code }}</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td v-if="can('accounting.manage')" class="px-4 py-3 whitespace-nowrap">
                <template v-if="tx.status === 'pending'">
                  <button @click="openReconcile(tx)" class="text-xs text-blue-600 hover:underline">Đối chiếu</button>
                </template>
                <template v-else>
                  <button @click="unreconcile(tx)" class="text-xs text-red-500 hover:underline">Hủy ĐC</button>
                </template>
              </td>
            </tr>
            <tr v-if="transactions.data.length === 0">
              <td :colspan="can('accounting.manage') ? 8 : 7" class="px-4 py-10 text-center text-gray-400">
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
            <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv"
              @change="onFileChange"
              class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
            <p v-if="importFile" class="text-xs text-gray-500 mt-1">{{ importFile.name }} ({{ (importFile.size / 1024).toFixed(1) }} KB)</p>
          </div>

          <div v-if="importError" class="mb-3 text-sm text-red-600 bg-red-50 px-3 py-2 rounded-lg">{{ importError }}</div>

          <div class="bg-blue-50 rounded-lg px-3 py-2 mb-4 text-xs text-blue-700">
            Giao dịch trùng (cùng ngày + số tiền + nội dung) sẽ tự động bỏ qua.
          </div>

          <div class="flex gap-3">
            <button @click="submitImport" :disabled="!importFile || importing"
              class="erp-btn-primary flex-1">
              {{ importing ? 'Đang import...' : 'Import' }}
            </button>
            <button @click="closeImport" class="erp-btn-secondary">Hủy</button>
          </div>
        </div>
      </div>

      <!-- Recategorize confirm modal -->
      <div v-if="showRecategorize" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-2">Phân loại lại giao dịch</h3>
          <p class="text-sm text-gray-600 mb-4">
            Tìm tất cả giao dịch <strong>chưa phân loại</strong> của tài khoản này và so khớp lại với danh sách TK nội bộ / NCC hiện tại.
            Giao dịch đã có phân loại sẽ không bị thay đổi.
          </p>
          <div class="flex gap-3">
            <button @click="submitRecategorize" class="erp-btn-primary flex-1">Chạy phân loại</button>
            <button @click="showRecategorize = false" class="erp-btn-secondary">Hủy</button>
          </div>
        </div>
      </div>

      <!-- Reconcile modal -->
      <div v-if="reconcileTarget" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-3">Đối chiếu giao dịch</h3>
          <div class="bg-gray-50 rounded-lg p-3 mb-4 text-sm">
            <p class="font-medium">{{ reconcileTarget.description }}</p>
            <p class="text-gray-500 text-xs mt-1">
              {{ reconcileTarget.transaction_date }} |
              <span v-if="reconcileTarget.credit > 0" class="text-green-600">+{{ formatVnd(reconcileTarget.credit) }}</span>
              <span v-else class="text-red-600">-{{ formatVnd(reconcileTarget.debit) }}</span>
            </p>
          </div>
          <div class="mb-4">
            <label class="form-label">Chọn Phiếu kế toán (TK 112)</label>
            <select v-model="reconcileForm.journal_entry_id" class="form-input">
              <option :value="null">-- Chọn phiếu KT --</option>
              <option v-for="je in pendingJEs" :key="je.id" :value="je.id">
                {{ je.code }} — {{ je.entry_date }} — {{ je.description?.substring(0, 50) }}
              </option>
            </select>
          </div>
          <div class="flex gap-3">
            <button @click="submitReconcile" :disabled="!reconcileForm.journal_entry_id || reconcileForm.processing"
              class="btn-primary flex-1">Xác nhận đối chiếu</button>
            <button @click="reconcileTarget = null" class="btn-secondary">Huỷ</button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();

const props = defineProps({
  bankAccount:  Object,
  transactions: Object,
  pendingJEs:   Array,
  filters:      Object,
  statuses:     Array,
  alertCount:   { type: Number, default: 0 },
});

const filters = ref({
  counterpart: props.filters.counterpart ?? '',
  tx_type:     props.filters.tx_type     ?? '',
  status:      props.filters.status      ?? '',
  date_from:   props.filters.date_from   ?? '',
  date_to:     props.filters.date_to     ?? '',
});

const addForm = useForm({
  transaction_date: new Date().toISOString().split('T')[0],
  description:      '',
  reference:        '',
  debit:            0,
  credit:           0,
});

const reconcileTarget   = ref(null);
const reconcileForm     = useForm({ journal_entry_id: null });
const showRecategorize  = ref(false);

function submitRecategorize() {
  router.post(
    route('accounting.bank-accounts.transactions.recategorize', props.bankAccount.id),
    {},
    { onSuccess: () => { showRecategorize.value = false; } }
  );
}

// Import Excel
const showImport  = ref(false);
const importFile  = ref(null);
const importError = ref('');
const importing   = ref(false);
const fileInput   = ref(null);

function onFileChange(e) {
  importFile.value  = e.target.files[0] ?? null;
  importError.value = '';
}

function closeImport() {
  showImport.value  = false;
  importFile.value  = null;
  importError.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

function submitImport() {
  if (!importFile.value) return;
  importing.value = true;
  importError.value = '';

  const fd = new FormData();
  fd.append('excel_file', importFile.value);

  router.post(
    route('accounting.bank-accounts.transactions.import-excel', props.bankAccount.id),
    fd,
    {
      forceFormData: true,
      onSuccess: () => closeImport(),
      onError: (errors) => {
        importError.value = errors.excel_file ?? errors.message ?? 'Import thất bại.';
      },
      onFinish: () => { importing.value = false; },
    }
  );
}

function applyFilters() {
  router.get(route('accounting.bank-accounts.transactions.index', props.bankAccount.id),
    { ...filters.value }, { preserveState: true });
}

function submitAdd() {
  addForm.post(route('accounting.bank-accounts.transactions.store', props.bankAccount.id), {
    onSuccess: () => addForm.reset('description', 'debit', 'credit', 'reference'),
  });
}

function openReconcile(tx) {
  reconcileTarget.value = tx;
  reconcileForm.journal_entry_id = null;
}

function submitReconcile() {
  reconcileForm.post(
    route('accounting.bank-accounts.transactions.reconcile', [props.bankAccount.id, reconcileTarget.value.id]),
    { onSuccess: () => { reconcileTarget.value = null; } }
  );
}

function unreconcile(tx) {
  router.post(route('accounting.bank-accounts.transactions.unreconcile', [props.bankAccount.id, tx.id]));
}

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
