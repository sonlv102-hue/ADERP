<template>
  <AppLayout>
    <div class="space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Số dư đầu kỳ</h1>
          <p class="text-sm text-gray-500 mt-1">
            Nhập số dư ban đầu của các tài khoản khi bắt đầu sử dụng hệ thống.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <span v-if="has_entry" class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-medium">
            ✓ Đã nhập
          </span>
          <button @click="showImport = true" class="erp-btn-secondary flex items-center gap-2 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Import từ MISA
          </button>
        </div>
      </div>

      <!-- Modal Import MISA -->
      <div v-if="showImport" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Import phát sinh từ MISA</h3>
          <p class="text-sm text-gray-500 mb-4">
            Upload file <strong>sổ chi tiết tài khoản</strong> xuất từ MISA (định dạng Excel).
            Toàn bộ phát sinh được tạo thành bút toán lịch sử — ngày tháng lấy từ file.
            Có thể import lần lượt từng tài khoản; chứng từ trùng số sẽ được cập nhật thay vì tạo mới.
          </p>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">File Excel (.xls / .xlsx)</label>
            <input ref="importFileInput" type="file" accept=".xls,.xlsx"
              class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200"
              @change="onImportFileChange" />
            <p v-if="importFile" class="text-xs text-gray-500 mt-1">{{ importFile.name }}</p>
          </div>
          <div v-if="importError" class="mb-3 text-sm text-red-600 bg-red-50 px-3 py-2 rounded-lg">{{ importError }}</div>
          <div class="flex justify-end gap-3 mt-4">
            <button @click="closeImportModal" class="erp-btn-secondary text-sm">Hủy</button>
            <button @click="submitImport" :disabled="!importFile || importing"
              class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {{ importing ? 'Đang import...' : 'Import' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Controls: date + totals + save -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap items-center gap-4">
        <div>
          <label class="form-label">Ngày số dư đầu kỳ</label>
          <input v-model="entryDate" type="date" class="form-input w-44" />
        </div>

        <div class="ml-auto flex items-center gap-6">
          <div class="text-right">
            <p class="text-xs text-gray-500">Tổng Nợ</p>
            <p class="font-semibold text-gray-800">{{ formatVnd(totalDebit) }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-gray-500">Tổng Có</p>
            <p class="font-semibold text-gray-800">{{ formatVnd(totalCredit) }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-gray-500">Chênh lệch</p>
            <p class="font-semibold" :class="isBalanced ? 'text-green-600' : 'text-red-600'">
              {{ isBalanced ? '✓ Cân bằng' : formatVnd(Math.abs(totalDebit - totalCredit)) }}
            </p>
          </div>
        </div>

        <button @click="save" :disabled="saving"
          class="px-5 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50 flex items-center gap-2">
          <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
          </svg>
          Lưu số dư đầu kỳ
        </button>
      </div>

      <!-- Cảnh báo không cân bằng -->
      <div v-if="!isBalanced && hasSomeEntry"
        class="flex items-center gap-2 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        Tổng Nợ và Tổng Có chênh lệch {{ formatVnd(Math.abs(totalDebit - totalCredit)) }}.
        Theo nguyên tắc kế toán, số dư đầu kỳ phải cân bằng (Tổng Nợ = Tổng Có).
        Bạn vẫn có thể lưu và điều chỉnh sau.
      </div>

      <!-- Bảng tài khoản theo nhóm -->
      <div v-for="group in groupedAccounts" :key="group.type" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
          <span class="font-semibold text-gray-700">{{ group.typeLabel }}</span>
          <span class="text-sm text-gray-500">
            <span class="text-blue-600 font-medium">Nợ: {{ formatVnd(group.totalDebit) }}</span>
            <span class="mx-2">|</span>
            <span class="text-green-600 font-medium">Có: {{ formatVnd(group.totalCredit) }}</span>
          </span>
        </div>
        <table class="w-full text-sm">
          <thead class="border-b border-gray-100">
            <tr class="text-gray-500 text-xs">
              <th class="text-left px-5 py-2 font-medium w-28">Mã TK</th>
              <th class="text-left px-5 py-2 font-medium">Tên tài khoản</th>
              <th class="text-right px-5 py-2 font-medium w-44">Số dư Nợ</th>
              <th class="text-right px-5 py-2 font-medium w-44">Số dư Có</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="acc in group.accounts" :key="acc.code"
              class="hover:bg-blue-50/30 transition-colors"
              :class="{ 'bg-blue-50/20': lines[acc.code]?.debit > 0 || lines[acc.code]?.credit > 0 }">
              <td class="px-5 py-2 font-mono text-xs text-gray-600">{{ acc.code }}</td>
              <td class="px-5 py-2 text-gray-800">{{ acc.name }}</td>
              <td class="px-3 py-1.5 text-right">
                <input
                  v-model.number="lines[acc.code].debit"
                  @focus="clearCredit(acc.code)"
                  type="number" min="0" step="any"
                  class="w-full text-right px-2 py-1 border border-transparent rounded focus:border-blue-400 focus:ring-1 focus:ring-blue-400 outline-none text-sm bg-transparent focus:bg-white"
                  :class="{ 'border-blue-300 bg-blue-50 font-medium': lines[acc.code]?.debit > 0 }"
                  placeholder="0" />
              </td>
              <td class="px-3 py-1.5 text-right">
                <input
                  v-model.number="lines[acc.code].credit"
                  @focus="clearDebit(acc.code)"
                  type="number" min="0" step="any"
                  class="w-full text-right px-2 py-1 border border-transparent rounded focus:border-green-400 focus:ring-1 focus:ring-green-400 outline-none text-sm bg-transparent focus:bg-white"
                  :class="{ 'border-green-300 bg-green-50 font-medium': lines[acc.code]?.credit > 0 }"
                  placeholder="0" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Bottom save -->
      <div class="flex justify-end pb-6">
        <button @click="save" :disabled="saving"
          class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
          Lưu số dư đầu kỳ
        </button>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
  accounts: Array,
  entry_date: String,
  has_entry: Boolean,
});

const { formatVnd } = useCurrency();
const page = usePage();
const { can } = usePermission();

const entryDate = ref(props.entry_date);
const saving = ref(false);

// ─── Import MISA ─────────────────────────────────────────────────────────────
const showImport     = ref(false);
const importFile     = ref(null);
const importError    = ref('');
const importing      = ref(false);
const importFileInput = ref(null);

function onImportFileChange(e) {
  importFile.value  = e.target.files[0] ?? null;
  importError.value = '';
}
function closeImportModal() {
  showImport.value  = false;
  importFile.value  = null;
  importError.value = '';
  if (importFileInput.value) importFileInput.value.value = '';
}
function submitImport() {
  if (!importFile.value) return;
  importing.value   = true;
  importError.value = '';

  const fd = new FormData();
  fd.append('excel_file', importFile.value);

  router.post(route('accounting.opening-balance.import-excel'), fd, {
    forceFormData: true,
    onSuccess: () => {
      closeImportModal();
    },
    onError: (errors) => {
      importError.value = errors.excel_file ?? errors.entry_date ?? 'Import thất bại.';
    },
    onFinish: () => { importing.value = false; },
  });
}

// Khởi tạo lines từ props
const lines = reactive({});
props.accounts.forEach(acc => {
  lines[acc.code] = { debit: acc.debit || 0, credit: acc.credit || 0 };
});

// Khi focus vào ô Nợ → xóa Có (và ngược lại)
const clearCredit = (code) => { lines[code].credit = 0; };
const clearDebit  = (code) => { lines[code].debit  = 0; };

// Tính tổng
const totalDebit  = computed(() => Object.values(lines).reduce((s, l) => s + (l.debit  || 0), 0));
const totalCredit = computed(() => Object.values(lines).reduce((s, l) => s + (l.credit || 0), 0));
const isBalanced  = computed(() => totalDebit.value === totalCredit.value);
const hasSomeEntry = computed(() => totalDebit.value > 0 || totalCredit.value > 0);

// Nhóm tài khoản theo loại
const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense', 'contra'];
const groupedAccounts = computed(() => {
  const groups = {};
  props.accounts.forEach(acc => {
    if (!groups[acc.type]) {
      groups[acc.type] = {
        type: acc.type,
        typeLabel: acc.type_label,
        accounts: [],
        get totalDebit()  { return this.accounts.reduce((s, a) => s + (lines[a.code]?.debit  || 0), 0); },
        get totalCredit() { return this.accounts.reduce((s, a) => s + (lines[a.code]?.credit || 0), 0); },
      };
    }
    groups[acc.type].accounts.push(acc);
  });
  return TYPE_ORDER.filter(t => groups[t]).map(t => groups[t]);
});

const save = () => {
  saving.value = true;
  const payload = {
    entry_date: entryDate.value,
    lines: props.accounts.map(acc => ({
      account_code: acc.code,
      debit:  lines[acc.code]?.debit  || 0,
      credit: lines[acc.code]?.credit || 0,
    })),
  };

  router.post(route('accounting.opening-balance.store'), payload, {
    preserveScroll: true,
    onFinish: () => { saving.value = false; },
    onSuccess: () => {
      // Flash message hiển thị từ layout
    },
  });
};
</script>
