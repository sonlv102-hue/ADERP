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

        <div class="grid grid-cols-2 gap-4">
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
            <select v-model="form.supplier_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn NCC --</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
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

        <div class="grid grid-cols-2 gap-4">
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

        <!-- Giá trị -->
        <div class="grid grid-cols-3 gap-4 pt-2 border-t border-gray-100">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Trước thuế <span class="text-red-500">*</span></label>
            <input v-model.number="form.subtotal" type="number" min="0" step="any" @input="updateTotal"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.subtotal" class="text-red-500 text-xs mt-1">{{ form.errors.subtotal }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thuế VAT</label>
            <input v-model.number="form.tax_amount" type="number" min="0" step="any" @input="updateTotal"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng cộng <span class="text-red-500">*</span></label>
            <input v-model.number="form.total" type="number" min="0" step="any"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.total" class="text-red-500 text-xs mt-1">{{ form.errors.total }}</p>
          </div>
        </div>

        <!-- TK chi phí: ẩn khi hàng hóa/TSCĐ/chi phí trả trước (kế toán xử lý bởi service khác) -->
        <div v-if="showExpenseAccount">
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ expenseAccountLabel }}</label>
          <select v-model="form.expense_account_code"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">-- {{ expenseAccountPlaceholder }} --</option>
            <option v-for="acc in expenseAccounts" :key="acc.code" :value="acc.code">{{ acc.name }}</option>
          </select>
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
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  invoice:         Object,
  nextCode:        String,
  purchaseOrders:  Array,
  suppliers:       Array,
  expenseAccounts: Array,
  invoiceTypes:    Array,
  selectedOrderId: [Number, String],
});

const today = new Date().toISOString().split('T')[0];

const form = useForm({
  code:                 props.invoice?.code              ?? props.nextCode,
  purchase_order_id:    props.invoice?.purchase_order_id ?? props.selectedOrderId ?? '',
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
    case 'project_construction': return 'Nợ 154 + Nợ 1331 / Có 3311 — tạo khi hóa đơn hợp lệ';
    case 'external_service':     return `Nợ ${form.expense_account_code || '6422'} + Nợ 1331 / Có 3311`;
    case 'selling_expense':      return 'Nợ 6421 + Nợ 1331 / Có 3311';
    case 'management_expense':   return 'Nợ 6422 + Nợ 1331 / Có 3311';
    case 'fixed_asset':          return 'Nợ 2111/2113 + Nợ 1332 / Có 3311 — tạo khi ghi nhận TSCĐ';
    case 'prepaid_expense':      return 'Nợ 242 + Nợ 1331 / Có 3311 — tạo khi hóa đơn hợp lệ';
    default:                     return '';
  }
});

function onOrderChange() {
  const po = props.purchaseOrders?.find(p => p.id === form.purchase_order_id);
  if (!po) return;

  form.supplier_id = po.supplier_id;

  const supplier = props.suppliers?.find(s => s.id === po.supplier_id);
  form.supplier_tax_code = supplier?.tax_code ?? '';

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

function updateTotal() {
  form.total = (form.subtotal || 0) + (form.tax_amount || 0);
}

function submit() {
  if (props.invoice) {
    form.put(route('purchasing.purchase-invoices.update', props.invoice.id));
  } else {
    form.post(route('purchasing.purchase-invoices.store'));
  }
}
</script>
