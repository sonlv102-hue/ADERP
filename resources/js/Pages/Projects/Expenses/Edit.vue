<template>
  <AppLayout>
    <div class="max-w-4xl space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('projects.projects.show', project.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sửa chi phí phát sinh</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} — {{ project.name }}</p>
        </div>
      </div>

      <!-- Banner: đã có kết chuyển → chỉ đọc -->
      <div v-if="expense.has_posted_transfers" class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
        Chi phí này đã có kết chuyển sang TK 154. <strong>Không thể sửa.</strong>
        Hủy kết chuyển trước rồi mới được sửa.
      </div>

      <!-- Banner: đã có bút toán → locked mode -->
      <div v-else-if="isLocked" class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700">
        Chi phí này đã có bút toán kế toán ({{ expense.journal_entry_id ? 'BT-#' + expense.journal_entry_id : 'đã ghi nhận' }}).
        Chỉ có thể sửa <strong>mô tả, số hóa đơn, thông tin nhà thầu</strong>. Để sửa số tiền/tài khoản, hãy xóa chi phí và nhập lại.
      </div>

      <!-- Banner: draft mode -->
      <div v-else class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-700">
        Chi phí này <strong>chưa được ghi nhận</strong> (chưa có bút toán). Bạn có thể sửa đầy đủ.
      </div>

      <form @submit.prevent>
        <!-- Section 1: Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Thông tin cơ bản</h2>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Danh mục</label>
              <select v-if="!isLocked && !expense.has_posted_transfers" v-model="form.category"
                @change="onCategoryChange"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ expenseCategories.find(c => c.value === form.category)?.label ?? form.category }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày chứng từ</label>
              <input v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.expense_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ form.expense_date }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số hóa đơn / chứng từ</label>
              <input v-if="!expense.has_posted_transfers"
                v-model="form.invoice_number" type="text"
                placeholder="Số HĐ, biên lai..."
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ form.invoice_number || '—' }}
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả <span class="text-red-500">*</span></label>
            <input v-if="!expense.has_posted_transfers"
              v-model="form.description" type="text"
              :class="['w-full border rounded-lg px-3 py-2 text-sm', form.errors?.description ? 'border-red-400' : 'border-gray-300']"
              placeholder="Diễn giải chi phí..." />
            <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">{{ form.description }}</div>
            <p v-if="form.errors?.description" class="text-red-500 text-xs mt-1">{{ form.errors.description }}</p>
          </div>
        </div>

        <!-- Section 2: Hình thức ghi nhận + Số tiền (chỉ edit khi draft) -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 mt-4 space-y-4">
          <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Hình thức ghi nhận &amp; Số tiền</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức ghi nhận</label>
              <select v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.payment_method" @change="onPaymentMethodChange"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option v-for="(mode, key) in PAYMENT_MODES" :key="key" :value="key">{{ mode.label }}</option>
              </select>
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono">
                {{ PAYMENT_MODES[form.payment_method]?.label ?? form.payment_method }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền (chưa VAT) <span v-if="!isLocked" class="text-red-500">*</span></label>
              <input v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.amount" type="number" min="0" step="1"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono">
                {{ formatVnd(form.amount) }}
              </div>
            </div>
          </div>

          <!-- NCC khi payable -->
          <div v-if="form.payment_method === 'payable'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp</label>
              <RemoteSearchSelect v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.supplier_id"
                :search-url="route('search.suppliers')"
                :display-text="form.supplier_name"
                placeholder="Tìm NCC..."
                @change="(opt) => { form.supplier_name = opt ? opt.label : '' }"
              />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ form.supplier_name || '—' }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">3311 — Phải trả NCC</div>
            </div>
          </div>

          <!-- Quỹ khi cash -->
          <div v-if="form.payment_method === 'cash'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ tiền mặt</label>
              <select v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.fund_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">-- Chọn quỹ --</option>
                <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }} ({{ f.account_code }})</option>
              </select>
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ funds.find(f => f.id == form.fund_id)?.name || '—' }}
              </div>
            </div>
          </div>

          <!-- NH khi bank -->
          <div v-if="form.payment_method === 'bank'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản ngân hàng</label>
              <select v-if="!isLocked && !expense.has_posted_transfers"
                v-model="form.bank_account_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">-- Chọn tài khoản --</option>
                <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} {{ b.account_number }} ({{ b.account_code }})</option>
              </select>
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                {{ bankAccounts.find(b => b.id == form.bank_account_id)?.bank_name || '—' }}
              </div>
            </div>
          </div>

          <!-- VAT -->
          <div v-if="!isLocked && !expense.has_posted_transfers" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">VAT (%)</label>
              <input v-model="form.vat_rate" type="number" min="0" max="100" step="1"
                @input="computeVat"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="0, 5, 8, 10" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tiền VAT</label>
              <input v-model="form.vat_amount" type="number" min="0" step="1"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div class="self-end pb-2">
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" v-model="form.has_vat_invoice" class="rounded border-gray-300" />
                <span class="text-sm text-gray-700">Có hóa đơn VAT</span>
              </label>
            </div>
          </div>
          <div v-else-if="form.vat_amount > 0" class="text-sm text-gray-500">
            VAT: {{ formatVnd(form.vat_amount) }}
          </div>

          <!-- TK Nợ / Có (advanced) -->
          <div v-if="!isLocked && !expense.has_posted_transfers" class="border-t border-gray-100 pt-3">
            <button type="button" @click="showAdvanced = !showAdvanced"
              class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1">
              <svg class="w-3.5 h-3.5 transition-transform" :class="showAdvanced ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
              Tùy chọn nâng cao (TK Nợ / TK Có)
            </button>
            <div v-if="showAdvanced" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">TK Nợ</label>
                <input v-model="form.debit_account" type="text" maxlength="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono"
                  :placeholder="categoryDefaultDebit" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
                <input v-model="form.credit_account" type="text" maxlength="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono"
                  :placeholder="PAYMENT_MODES[form.payment_method]?.credit ?? ''" />
              </div>
            </div>
          </div>
          <div v-else-if="isLocked || expense.has_posted_transfers" class="flex gap-4 text-sm mt-1">
            <span class="font-mono text-blue-700">Nợ {{ expense.debit_account || '(theo danh mục)' }}</span>
            <span class="font-mono text-red-600">Có {{ expense.credit_account || '—' }}</span>
          </div>
        </div>

        <!-- Section 3: Thông tin nhà thầu (luôn editable trừ khi has_posted_transfers) -->
        <div v-if="showContractorSection" class="bg-white rounded-xl border border-gray-200 p-6 mt-4 space-y-4">
          <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Thông tin nhà thầu / khoán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên người/đội nhóm</label>
              <input v-if="!expense.has_posted_transfers"
                v-model="form.contractor_name" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">{{ form.contractor_name || '—' }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
              <input v-if="!expense.has_posted_transfers"
                v-model="form.contractor_phone" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">{{ form.contractor_phone || '—' }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">CCCD / Mã số thuế</label>
              <input v-if="!expense.has_posted_transfers"
                v-model="form.contractor_id_number" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">{{ form.contractor_id_number || '—' }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số hợp đồng</label>
              <input v-if="!expense.has_posted_transfers"
                v-model="form.contract_number" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div v-else class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">{{ form.contract_number || '—' }}</div>
            </div>
          </div>
        </div>

        <!-- Nút hành động -->
        <div v-if="!expense.has_posted_transfers" class="bg-white rounded-xl border border-gray-200 p-6 mt-4">
          <p v-if="submitError" class="text-red-600 text-xs mb-3">{{ submitError }}</p>
          <div class="flex flex-wrap justify-end gap-3">
            <Link :href="route('projects.projects.show', project.id)"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
              Hủy
            </Link>
            <template v-if="!isLocked">
              <button type="button" @click="submit(false)"
                :disabled="form.processing"
                class="px-4 py-2 border border-gray-400 rounded-lg text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                {{ form.processing ? 'Đang xử lý...' : 'Lưu nháp' }}
              </button>
              <button type="button" @click="submit(true)"
                :disabled="form.processing"
                class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white rounded-lg text-sm font-medium">
                {{ form.processing ? 'Đang xử lý...' : 'Lưu và ghi nhận' }}
              </button>
            </template>
            <template v-else>
              <button type="button" @click="submitLocked"
                :disabled="form.processing"
                class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white rounded-lg text-sm font-medium">
                {{ form.processing ? 'Đang xử lý...' : 'Cập nhật mô tả' }}
              </button>
            </template>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  project:           Object,
  expense:           Object,
  expenseCategories: Array,
  funds:             Array,
  bankAccounts:      Array,
  employees:         Array,
});

const { formatVnd } = useCurrency();

const PAYMENT_MODES = {
  payable: { label: 'Ghi công nợ NCC (Có 3311)',      credit: '3311' },
  cash:    { label: 'Chi tiền mặt (Có 1111)',          credit: '1111' },
  bank:    { label: 'Chi ngân hàng (Có 1121)',         credit: '1121' },
  advance: { label: 'Quyết toán tạm ứng (Có 141)',     credit: '141'  },
  salary:  { label: 'Ghi nhận nhân công (Có 3341)',    credit: '3341' },
  misc:    { label: 'Ghi nhận khác (Có 3388)',         credit: '3388' },
};

// Locked nếu đã có bút toán (nhưng chưa có transfer — transfer block riêng)
const isLocked = computed(() => !!props.expense.journal_entry_id);

const showAdvanced = ref(!!(props.expense.debit_account || props.expense.credit_account));
const submitError  = ref('');

const form = useForm({
  category:                  props.expense.category,
  labor_type:                props.expense.labor_type ?? '',
  description:               props.expense.description,
  amount:                    props.expense.amount,
  expense_date:              props.expense.expense_date,
  debit_account:             props.expense.debit_account ?? '',
  credit_account:            props.expense.credit_account ?? '',
  payment_method:            props.expense.payment_method ?? 'payable',
  supplier_id:               props.expense.supplier_id ?? null,
  supplier_name:             props.expense.supplier_name ?? '',
  fund_id:                   props.expense.fund_id ?? '',
  bank_account_id:           props.expense.bank_account_id ?? '',
  employee_id:               props.expense.employee_id ?? '',
  invoice_number:            props.expense.invoice_number ?? '',
  vat_rate:                  props.expense.vat_rate ?? '',
  vat_amount:                props.expense.vat_amount ?? 0,
  has_vat_invoice:           props.expense.has_vat_invoice ?? false,
  pit_withholding_enabled:   props.expense.pit_withholding_enabled ?? false,
  pit_rate:                  props.expense.pit_rate ?? 10,
  contractor_name:           props.expense.contractor_name ?? '',
  contractor_representative: props.expense.contractor_representative ?? '',
  contractor_phone:          props.expense.contractor_phone ?? '',
  contractor_id_number:      props.expense.contractor_id_number ?? '',
  contract_number:           props.expense.contract_number ?? '',
  post_immediately:          false,
});

const categoryDefaultDebit = computed(() => {
  return props.expenseCategories.find(c => c.value === form.category)?.defaultDebitAccount ?? '154';
});

const showContractorSection = computed(() =>
  ['freelance_contractor', 'subcontractor_invoice'].includes(form.labor_type)
  || form.contractor_name
);

function onCategoryChange() {
  const cat = props.expenseCategories.find(c => c.value === form.category);
  if (cat && !form.debit_account) {
    form.debit_account = cat.defaultDebitAccount;
  }
}

function onPaymentMethodChange() {
  form.supplier_id   = null;
  form.supplier_name = '';
  form.fund_id       = '';
  form.bank_account_id = '';
}

function computeVat() {
  const rate   = parseFloat(form.vat_rate) || 0;
  const amount = parseFloat(form.amount) || 0;
  if (rate > 0 && amount > 0) {
    form.vat_amount = Math.round(amount * rate / 100);
  } else {
    form.vat_amount = 0;
  }
}

function submit(postImmediately) {
  submitError.value = '';
  form.post_immediately = postImmediately;
  form.patch(route('projects.projects.expenses.update', [props.project.id, props.expense.id]), {
    onError: (errors) => {
      submitError.value = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
    },
  });
}

function submitLocked() {
  submitError.value = '';
  // Chỉ gửi các non-financial fields
  const lockedForm = useForm({
    description:               form.description,
    invoice_number:            form.invoice_number,
    contractor_name:           form.contractor_name,
    contractor_representative: form.contractor_representative,
    contractor_phone:          form.contractor_phone,
    contractor_id_number:      form.contractor_id_number,
    contract_number:           form.contract_number,
  });
  lockedForm.patch(route('projects.projects.expenses.update', [props.project.id, props.expense.id]), {
    onError: (errors) => {
      submitError.value = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
    },
  });
}
</script>
