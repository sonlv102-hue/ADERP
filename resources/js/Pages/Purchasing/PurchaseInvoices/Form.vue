<template>
  <AppLayout>
    <div class="max-w-2xl space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('purchasing.purchase-invoices.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ invoice ? 'Sửa hóa đơn đầu vào' : 'Thêm hóa đơn đầu vào' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">

        <!-- Đơn mua hàng liên kết -->
        <div v-if="!invoice">
          <label class="block text-sm font-medium text-gray-700 mb-1">Đơn mua hàng</label>
          <select v-model="form.purchase_order_id" @change="onOrderChange"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">-- Chọn đơn mua hàng --</option>
            <option v-for="po in purchaseOrders" :key="po.id" :value="po.id">
              {{ po.code }} — {{ po.supplier }}
            </option>
          </select>
          <p v-if="form.errors.purchase_order_id" class="text-red-500 text-xs mt-1">{{ form.errors.purchase_order_id }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Mã nội bộ -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã nội bộ <span class="text-red-500">*</span></label>
            <input v-model="form.code" :disabled="!!invoice" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50" />
            <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
          </div>

          <!-- Nhà cung cấp -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp <span class="text-red-500">*</span></label>
            <RemoteSearchSelect
              v-model="form.supplier_id"
              :search-url="route('search.suppliers')"
              :display-text="supplierDisplay"
              placeholder="-- Tìm nhà cung cấp --"
              :has-error="!!form.errors.supplier_id"
            />
            <p v-if="form.errors.supplier_id" class="text-red-500 text-xs mt-1">{{ form.errors.supplier_id }}</p>
          </div>
        </div>

        <!-- Loại hóa đơn đầu vào -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Loại hóa đơn đầu vào</label>
          <select v-model="form.invoice_type" @change="onTypeChange"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">-- Chọn loại (để trống = tự động theo PO) --</option>
            <option v-for="t in invoiceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
          <p v-if="form.errors.invoice_type" class="text-red-500 text-xs mt-1">{{ form.errors.invoice_type }}</p>
          <p v-if="journalPreview" class="mt-1.5 text-xs text-indigo-700 bg-indigo-50 rounded px-2 py-1.5">
            Bút toán: {{ journalPreview }}
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Số hóa đơn NCC -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số hóa đơn NCC</label>
            <input v-model="form.invoice_number" type="text" placeholder="VD: 0001234/2026"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <!-- MST nhà cung cấp -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">MST nhà cung cấp</label>
            <input v-model="form.supplier_tax_code" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <!-- Ngày hóa đơn -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày hóa đơn</label>
            <input v-model="form.invoice_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <!-- Hạn thanh toán -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hạn thanh toán</label>
            <input v-model="form.due_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <!-- Dự án (header) — dùng làm mặc định khi thêm dòng chi phí -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Dự án</label>
          <select v-model="form.project_id"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">-- Không gắn dự án --</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">Chọn dự án để tự điền vào các dòng chi phí TK 154.</p>
        </div>

        <!-- Bảng chi tiết dòng chi phí -->
        <div class="border border-gray-200 rounded-lg overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 bg-gray-50 border-b border-gray-200">
            <span class="text-sm font-medium text-gray-700">Dòng chi phí</span>
            <button type="button" @click="addItem"
              class="text-xs bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium">
              + Thêm dòng
            </button>
          </div>

          <div v-if="form.items.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
            Chưa có dòng chi phí. Nhấn "+ Thêm dòng" hoặc nhập thủ công tổng tiền bên dưới.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-100">
              <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                  <th class="px-3 py-2 text-left w-44">Diễn giải</th>
                  <th class="px-3 py-2 text-left w-28">TK Nợ <span class="text-red-500">*</span></th>
                  <th class="px-3 py-2 text-left w-28">TK Có</th>
                  <th class="px-3 py-2 text-left w-36">Dự án</th>
                  <th class="px-3 py-2 text-right w-28">Trước thuế <span class="text-red-500">*</span></th>
                  <th class="px-3 py-2 text-right w-20">VAT %</th>
                  <th class="px-3 py-2 text-right w-28">Tiền thuế</th>
                  <th class="px-3 py-2 w-10"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(item, idx) in form.items" :key="idx">
                  <td class="px-3 py-2">
                    <input v-model="item.description" type="text" placeholder="Diễn giải"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="item.account_code" @change="onItemAccountChange(idx)"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-500"
                      :class="{ 'border-red-400': needs154Project(idx) }">
                      <option value="">-- TK --</option>
                      <option v-for="a in expenseAccountOptions" :key="a.value" :value="a.value">{{ a.value }}</option>
                    </select>
                    <p v-if="needs154Project(idx)" class="text-red-500 text-xs mt-0.5">Phải chọn dự án</p>
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="item.credit_account_code"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-500">
                      <option value="">-- Tự động --</option>
                      <option v-for="a in creditAccountOptions" :key="a.value" :value="a.value">{{ a.value }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="item.project_id"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-500"
                      :class="{ 'border-red-400': needs154Project(idx) }">
                      <option value="">-- Dự án --</option>
                      <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="item.amount" type="number" min="0" step="any" @input="onItemAmountChange(idx)"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="item.vat_rate" type="number" min="0" max="100" step="any" @input="onItemAmountChange(idx)"
                      class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2 text-right text-gray-700">
                    {{ formatVnd(item.tax_amount) }}
                  </td>
                  <td class="px-3 py-2 text-center">
                    <button type="button" @click="removeItem(idx)"
                      class="text-red-400 hover:text-red-600 text-lg leading-none font-bold">×</button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-gray-50 text-sm font-medium">
                <tr>
                  <td colspan="4" class="px-3 py-2 text-right text-gray-500">Tổng</td>
                  <td class="px-3 py-2 text-right">{{ formatVnd(itemsSubtotal) }}</td>
                  <td class="px-3 py-2"></td>
                  <td class="px-3 py-2 text-right">{{ formatVnd(itemsTax) }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Giá trị: readonly nếu đang dùng items -->
        <div class="grid grid-cols-3 gap-4 pt-2 border-t border-gray-100">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Trước thuế <span class="text-red-500">*</span></label>
            <input v-model.number="form.subtotal" type="number" min="0" step="any" @input="updateTotal"
              :readonly="hasItems"
              :class="hasItems ? 'bg-gray-50 cursor-not-allowed' : ''"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.subtotal" class="text-red-500 text-xs mt-1">{{ form.errors.subtotal }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thuế VAT</label>
            <input v-model.number="form.tax_amount" type="number" min="0" step="any" @input="updateTotal"
              :readonly="hasItems"
              :class="hasItems ? 'bg-gray-50 cursor-not-allowed' : ''"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng cộng <span class="text-red-500">*</span></label>
            <input v-model.number="form.total" type="number" min="0" step="any"
              :readonly="hasItems"
              :class="hasItems ? 'bg-gray-50 cursor-not-allowed font-bold' : 'font-bold'"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.total" class="text-red-500 text-xs mt-1">{{ form.errors.total }}</p>
          </div>
        </div>

        <!-- TK chi phí: ẩn khi hàng hóa/TSCĐ/chi phí trả trước (kế toán xử lý bởi service khác); hoặc khi đang dùng items -->
        <div v-if="showExpenseAccount && !hasItems">
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ expenseAccountLabel }}</label>
          <SearchableSelect
            v-model="form.expense_account_code"
            :options="expenseAccountOptions"
            :placeholder="`-- ${expenseAccountPlaceholder} --`"
          />
        </div>

        <!-- Ghi chú -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('purchasing.purchase-invoices.index')"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            {{ invoice ? 'Cập nhật' : 'Tạo hóa đơn' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

const props = defineProps({
  invoice:              Object,
  nextCode:             String,
  purchaseOrders:       Array,
  projects:             { type: Array, default: () => [] },
  expenseAccounts:      Array,
  creditAccounts:       { type: Array, default: () => [] },
  invoiceTypes:         Array,
  selectedOrderId:      [Number, String],
  initialSupplierName:  String,
  initialSupplierCode:  String,
});

const today = new Date().toISOString().split('T')[0];

const supplierDisplay = ref(
  props.initialSupplierCode && props.initialSupplierName
    ? `${props.initialSupplierCode} - ${props.initialSupplierName}`
    : (props.initialSupplierName ?? '')
);

const expenseAccountOptions = computed(() =>
  (props.expenseAccounts ?? []).map(a => ({ value: a.code, code: a.code, label: a.name }))
);

const creditAccountOptions = computed(() =>
  (props.creditAccounts ?? []).map(a => ({ value: a.code, label: a.name }))
);

const form = useForm({
  code:                 props.invoice?.code              ?? props.nextCode,
  purchase_order_id:    props.invoice?.purchase_order_id ?? props.selectedOrderId ?? '',
  project_id:           props.invoice?.project_id        ?? '',
  supplier_id:          props.invoice?.supplier_id       ?? '',
  invoice_number:       props.invoice?.invoice_number    ?? '',
  invoice_date:         props.invoice?.invoice_date      ?? today,
  supplier_tax_code:    props.invoice?.supplier_tax_code ?? '',
  subtotal:             props.invoice?.subtotal          ?? 0,
  tax_amount:           props.invoice?.tax_amount        ?? 0,
  total:                props.invoice?.total             ?? 0,
  due_date:             props.invoice?.due_date          ?? '',
  notes:                props.invoice?.notes             ?? '',
  expense_account_code: props.invoice?.expense_account_code ?? '',
  invoice_type:         props.invoice?.invoice_type      ?? '',
  items: (props.invoice?.items ?? []).map(it => ({
    description:         it.description         ?? '',
    account_code:        it.account_code        ?? '',
    credit_account_code: it.credit_account_code ?? '',
    project_id:          it.project_id          ?? '',
    amount:              it.amount              ?? 0,
    vat_rate:            it.vat_rate            ?? 0,
    tax_amount:          it.tax_amount          ?? 0,
  })),
});

// Loại không tạo JE từ invoice (xử lý bởi StockService hoặc FixedAssetService)
const NO_EXPENSE_ACCOUNT_TYPES = ['resale_goods', 'raw_material', 'tools_equipment', 'fixed_asset', 'prepaid_expense'];

const showExpenseAccount = computed(() => {
  if (!form.invoice_type) return true; // legacy: hiển thị để không mất dữ liệu cũ
  return !NO_EXPENSE_ACCOUNT_TYPES.includes(form.invoice_type);
});

const expenseAccountLabel = computed(() => {
  switch (form.invoice_type) {
    case 'project_construction': return 'TK tập hợp chi phí dự án';
    case 'selling_expense':      return 'TK chi phí bán hàng';
    case 'management_expense':   return 'TK chi phí quản lý';
    case 'external_service':     return 'TK chi phí dịch vụ';
    default:                     return 'TK chi phí';
  }
});

const expenseAccountPlaceholder = computed(() => {
  switch (form.invoice_type) {
    case 'project_construction': return 'Mặc định 154';
    case 'selling_expense':      return 'Mặc định 6421';
    case 'management_expense':   return 'Mặc định 6422';
    case 'external_service':     return 'Chọn TK chi phí dịch vụ';
    default:                     return 'Tự động theo loại (mặc định 6422)';
  }
});

const journalPreview = computed(() => {
  switch (form.invoice_type) {
    case 'resale_goods':         return 'Nợ 1561 + Nợ 1331 / Có 3311 — tạo khi xác nhận phiếu nhập kho';
    case 'raw_material':         return 'Nợ 1521 + Nợ 1331 / Có 3311 — tạo khi xác nhận phiếu nhập kho';
    case 'tools_equipment':      return 'Nợ 1531 + Nợ 1331 / Có 3311 — tạo khi xác nhận phiếu nhập kho';
    case 'project_construction': return 'Nợ 154 + Nợ 1331 / Có 3312 — tạo khi hóa đơn hợp lệ';
    case 'external_service':     return `Nợ ${form.expense_account_code || '6422'} + Nợ 1331 / Có 3312`;
    case 'selling_expense':      return 'Nợ 6421 + Nợ 1331 / Có 3312';
    case 'management_expense':   return 'Nợ 6422 + Nợ 1331 / Có 3312';
    case 'fixed_asset':          return 'Nợ 2111/2113 + Nợ 1332 / Có 3312 — tạo khi ghi nhận TSCĐ';
    case 'prepaid_expense':      return 'Nợ 242 + Nợ 1331 / Có 3312 — tạo khi hóa đơn hợp lệ';
    default:                     return '';
  }
});

function onOrderChange() {
  const po = props.purchaseOrders?.find(p => p.id === form.purchase_order_id);
  if (!po) return;

  form.supplier_id = po.supplier_id;
  supplierDisplay.value = po.supplier ?? '';

  form.subtotal   = po.subtotal   ?? 0;
  form.tax_amount = po.tax_amount ?? 0;
  form.total      = (po.subtotal ?? 0) + (po.tax_amount ?? 0);

  // Auto-detect loại hóa đơn từ PO nếu chưa chọn
  if (!form.invoice_type && po.default_invoice_type) {
    form.invoice_type = po.default_invoice_type;
  }
}

function onTypeChange() {
  // Khi đổi sang loại không dùng TK chi phí, clear để không gửi giá trị sai lên server
  if (!showExpenseAccount.value) {
    form.expense_account_code = '';
  }
}

// ─── Items helpers ─────────────────────────────────────────────────────────

const hasItems = computed(() => form.items.length > 0);

const itemsSubtotal = computed(() =>
  form.items.reduce((s, it) => s + (Number(it.amount) || 0), 0)
);
const itemsTax = computed(() =>
  form.items.reduce((s, it) => s + (Number(it.tax_amount) || 0), 0)
);

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(val || 0));
}

// Sync totals from items when items present
watch([itemsSubtotal, itemsTax], ([sub, tax]) => {
  if (hasItems.value) {
    form.subtotal   = Math.round(sub);
    form.tax_amount = Math.round(tax);
    form.total      = Math.round(sub + tax);
  }
});

function needs154Project(idx) {
  const item = form.items[idx];
  return item && String(item.account_code).startsWith('154') && !item.project_id;
}

// TK Có mặc định theo TK Nợ: hàng hóa/vật tư → 3311, dịch vụ/chi phí → 3312
function defaultCreditFor(accountCode) {
  const code = String(accountCode || '');
  if (/^(152|153|156|211|213)/.test(code)) return '3311';
  if (/^(154|64|81|241|242)/.test(code)) return '3312';
  // Nếu đã chọn loại hóa đơn, dùng default theo type
  const typeMap = {
    resale_goods: '3311', raw_material: '3311', tools_equipment: '3311',
    project_construction: '3312', external_service: '3312',
    selling_expense: '3312', management_expense: '3312', prepaid_expense: '3312',
  };
  return typeMap[form.invoice_type] || '3312';
}

function addItem() {
  form.items.push({
    description:         '',
    account_code:        '',
    credit_account_code: defaultCreditFor(''),
    project_id:          form.project_id || '',
    amount:              0,
    vat_rate:            10,
    tax_amount:          0,
  });
}

function removeItem(idx) {
  form.items.splice(idx, 1);
}

function onItemAccountChange(idx) {
  const item = form.items[idx];
  if (!item) return;
  // Auto-fill project from header when TK 154 selected
  if (String(item.account_code).startsWith('154') && !item.project_id && form.project_id) {
    item.project_id = form.project_id;
  }
  // Auto-set credit account nếu chưa có hoặc đang là default
  const newCredit = defaultCreditFor(item.account_code);
  if (!item.credit_account_code || item.credit_account_code === defaultCreditFor('')) {
    item.credit_account_code = newCredit;
  }
}

function onItemAmountChange(idx) {
  const item = form.items[idx];
  if (!item) return;
  const amt  = Number(item.amount) || 0;
  const rate = Number(item.vat_rate) || 0;
  item.tax_amount = Math.round(amt * rate / 100);
}

// ─── Manual totals (no items) ───────────────────────────────────────────────

function updateTotal() {
  if (!hasItems.value) {
    form.total = (form.subtotal || 0) + (form.tax_amount || 0);
  }
}

function submit() {
  if (props.invoice) {
    form.put(route('purchasing.purchase-invoices.update', props.invoice.id));
  } else {
    form.post(route('purchasing.purchase-invoices.store'));
  }
}
</script>
