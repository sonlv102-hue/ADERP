<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ tool ? 'Sửa hồ sơ CCDC' : 'Tạo CCDC mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Thông tin CCDC</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Mã CCDC</label>
              <input :value="tool?.code || nextCode" disabled
                class="erp-input bg-gray-50 text-gray-500" />
            </div>
            <div>
              <label class="erp-label">Đơn vị tính</label>
              <input v-model="form.unit" type="text" class="erp-input" placeholder="cái, bộ, chiếc..." />
            </div>
          </div>

          <div>
            <label class="erp-label">Tên CCDC <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="erp-input" :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="erp-error">{{ form.errors.name }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Nhóm CCDC</label>
              <SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="-- Chọn nhóm --" />
            </div>
            <div>
              <label class="erp-label">Số lượng <span class="text-red-500">*</span></label>
              <input v-model.number="form.quantity" type="number" min="1" class="erp-input"
                :class="{ 'border-red-500': form.errors.quantity }" />
              <p v-if="form.errors.quantity" class="erp-error">{{ form.errors.quantity }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <label class="erp-label">Nguyên giá (chưa VAT) <span class="text-red-500">*</span></label>
              <input v-model.number="form.original_cost" type="number" min="0" step="1" class="erp-input"
                :class="{ 'border-red-500': form.errors.original_cost }" @input="calcTotal" />
            </div>
            <div>
              <label class="erp-label">Tiền VAT</label>
              <input v-model.number="form.vat_amount" type="number" min="0" step="1" class="erp-input"
                @input="calcTotal" />
            </div>
            <div>
              <label class="erp-label">Tổng tiền (có VAT)</label>
              <input :value="form.total_cost" disabled class="erp-input bg-gray-50 text-gray-600 font-mono" />
            </div>
          </div>
        </div>

        <!-- Luồng nghiệp vụ -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Phương thức ghi nhận</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Luồng nhập <span class="text-red-500">*</span></label>
              <select v-model="form.acquisition_type" class="erp-input">
                <option value="stock">Nhập kho trước (1531)</option>
                <option value="direct">Dùng ngay không qua kho</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Ghi nhận chi phí <span class="text-red-500">*</span></label>
              <select v-model="form.recognition_method" class="erp-input">
                <option value="immediate">Một lần ({{ expenseAccountCode }})</option>
                <option value="allocation">Phân bổ nhiều kỳ (qua 2422)</option>
              </select>
            </div>
          </div>

          <div v-if="form.recognition_method === 'allocation'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Số kỳ phân bổ</label>
              <input v-model.number="form.allocation_periods" type="number" min="1" class="erp-input" />
            </div>
            <div>
              <label class="erp-label">Ngày bắt đầu phân bổ</label>
              <input v-model="form.allocation_start_date" type="date" class="erp-input" />
            </div>
          </div>

          <!-- JE preview box -->
          <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 space-y-1">
            <p class="font-semibold">Bút toán sẽ sinh:</p>
            <template v-if="form.acquisition_type === 'stock'">
              <p>Nợ 1531 — Nhập kho CCDC</p>
              <p v-if="form.vat_amount > 0">Nợ 1331 — VAT đầu vào</p>
              <p>Có {{ form.payable_account_code || payableDisplay }} — {{ paymentLabel }}</p>
            </template>
            <template v-else>
              <p v-if="form.recognition_method === 'allocation'">Nợ {{ form.pending_account_code || '2422' }} — CCDC chờ phân bổ</p>
              <p v-else>Nợ {{ expenseAccountCode }} — Chi phí CCDC</p>
              <p v-if="form.vat_amount > 0">Nợ 1331 — VAT đầu vào</p>
              <p>Có {{ form.payable_account_code || payableDisplay }} — {{ paymentLabel }}</p>
            </template>
          </div>
        </div>

        <!-- Ngày tháng & bộ phận -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Thông tin sử dụng</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Ngày mua</label>
              <input v-model="form.purchase_date" type="date" class="erp-input" />
            </div>
            <div>
              <label class="erp-label">Ngày đưa vào sử dụng</label>
              <input v-model="form.in_service_date" type="date" class="erp-input" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Bộ phận sử dụng</label>
              <input v-model="form.department" type="text" class="erp-input" placeholder="Kế toán, Kỹ thuật..." />
            </div>
            <div>
              <label class="erp-label">Nhân viên chịu trách nhiệm</label>
              <SearchableSelect v-model="form.responsible_employee_id" :options="employeeOptions"
                placeholder="-- Chọn nhân viên --" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Kho lưu giữ</label>
              <SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions"
                placeholder="-- Chọn kho --" />
            </div>
            <div>
              <label class="erp-label">Dự án liên kết</label>
              <SearchableSelect v-model="form.project_id" :options="projectOptions"
                placeholder="-- Chọn dự án --" />
            </div>
          </div>
        </div>

        <!-- Nhà cung cấp & thanh toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Nhà cung cấp & thanh toán</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Nhà cung cấp</label>
              <SearchableSelect v-model="form.supplier_id" :options="supplierOptions"
                placeholder="-- Chọn NCC --" />
            </div>
            <div>
              <label class="erp-label">Hình thức thanh toán <span class="text-red-500">*</span></label>
              <select v-model="form.payment_type" class="erp-input">
                <option value="payable">Chưa thanh toán (Có 331)</option>
                <option value="cash">Tiền mặt (Có 1111)</option>
                <option value="bank">Ngân hàng (Có 1121)</option>
              </select>
            </div>
          </div>

          <div v-if="form.payment_type !== 'payable'">
            <label class="erp-label">Quỹ / Tài khoản ngân hàng</label>
            <SearchableSelect v-model="form.fund_id" :options="fundOptions" placeholder="-- Chọn quỹ --" />
          </div>
        </div>

        <!-- Tài khoản kế toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Tài khoản kế toán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">TK kho CCDC</label>
              <input v-model="form.stock_account_code" type="text" class="erp-input" placeholder="1531" />
            </div>
            <div>
              <label class="erp-label">TK chờ phân bổ</label>
              <input v-model="form.pending_account_code" type="text" class="erp-input" placeholder="2422" />
            </div>
            <div>
              <label class="erp-label">TK chi phí</label>
              <input v-model="form.expense_account_code" type="text" class="erp-input" placeholder="6422" />
            </div>
            <div v-if="form.payment_type !== 'payable'">
              <label class="erp-label">TK công nợ/quỹ</label>
              <input v-model="form.payable_account_code" type="text" class="erp-input" placeholder="3311" />
            </div>
          </div>
        </div>

        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="erp-input" />
        </div>

        <!-- Auto-confirm toggle (direct only) -->
        <div v-if="form.acquisition_type === 'direct'" class="flex items-center gap-2">
          <input id="auto_confirm" type="checkbox" v-model="form.auto_confirm" class="accent-primary-600" />
          <label for="auto_confirm" class="text-sm text-gray-700">Xác nhận và tạo bút toán ngay</label>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (tool ? 'Cập nhật' : 'Tạo CCDC') }}
          </button>
          <Link :href="route('accounting.small-tools.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';

const props = defineProps({
  tool: Object, nextCode: String,
  categories: Array, suppliers: Array, employees: Array,
  warehouses: Array, projects: Array, funds: Array,
});

const form = useForm({
  name:                    props.tool?.name                    ?? '',
  category_id:             props.tool?.category_id             ?? null,
  unit:                    props.tool?.unit                    ?? 'cái',
  quantity:                props.tool?.quantity                ?? 1,
  original_cost:           props.tool?.original_cost           ?? 0,
  vat_amount:              props.tool?.vat_amount              ?? 0,
  total_cost:              props.tool?.total_cost              ?? 0,
  acquisition_type:        props.tool?.acquisition_type        ?? 'stock',
  recognition_method:      props.tool?.recognition_method      ?? 'immediate',
  allocation_periods:      props.tool?.allocation_periods      ?? null,
  allocation_start_date:   props.tool?.allocation_start_date   ?? '',
  purchase_date:           props.tool?.purchase_date           ?? new Date().toISOString().slice(0, 10),
  in_service_date:         props.tool?.in_service_date         ?? '',
  department:              props.tool?.department              ?? '',
  responsible_employee_id: props.tool?.responsible_employee_id ?? null,
  warehouse_id:            props.tool?.warehouse_id            ?? null,
  project_id:              props.tool?.project_id              ?? null,
  supplier_id:             props.tool?.supplier_id             ?? null,
  payment_type:            props.tool?.payment_type            ?? 'payable',
  fund_id:                 props.tool?.fund_id                 ?? null,
  stock_account_code:      props.tool?.stock_account_code      ?? '1531',
  pending_account_code:    props.tool?.pending_account_code    ?? '2422',
  expense_account_code:    props.tool?.expense_account_code    ?? '6422',
  payable_account_code:    props.tool?.payable_account_code    ?? '3311',
  notes:                   props.tool?.notes                   ?? '',
  auto_confirm:            false,
});

const categoryOptions  = computed(() => (props.categories ?? []).map(c => ({ value: c.id, label: c.name })));
const supplierOptions  = computed(() => (props.suppliers  ?? []).map(s => ({ value: s.id, code: s.code, label: s.name })));
const employeeOptions  = computed(() => (props.employees  ?? []).map(e => ({ value: e.id, code: e.code, label: e.name })));
const warehouseOptions = computed(() => (props.warehouses ?? []).map(w => ({ value: w.id, label: w.name })));
const projectOptions   = computed(() => (props.projects   ?? []).map(p => ({ value: p.id, code: p.code, label: p.name })));
const fundOptions      = computed(() => (props.funds      ?? []).map(f => ({
  value: f.id, label: f.name,
  code: f.account_code || '',
  meta: f.type === 'bank' ? 'Ngân hàng' : 'Tiền mặt',
})));

const expenseAccountCode = computed(() => form.expense_account_code || '6422');
const payableDisplay = computed(() => {
  if (form.payment_type === 'payable') return '3311';
  if (form.fund_id) {
    const f = props.funds?.find(f => f.id === form.fund_id);
    return f?.account_code || (f?.type === 'bank' ? '1121' : '1111');
  }
  return form.payment_type === 'bank' ? '1121' : '1111';
});

const paymentLabel = computed(() => ({
  payable: 'Công nợ NCC',
  cash:    'Tiền mặt / Quỹ',
  bank:    'Ngân hàng',
}[form.payment_type] || ''));

function calcTotal() {
  form.total_cost = (form.original_cost || 0) + (form.vat_amount || 0);
}

function submit() {
  if (props.tool) {
    form.put(route('accounting.small-tools.update', props.tool.id));
  } else {
    form.post(route('accounting.small-tools.store'));
  }
}
</script>
