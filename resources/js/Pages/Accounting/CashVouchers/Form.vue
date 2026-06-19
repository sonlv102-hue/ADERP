<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.cash-vouchers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ voucher ? 'Sửa phiếu' : (form.type === 'receipt' ? 'Tạo phiếu thu' : 'Tạo phiếu chi') }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Thông tin phiếu -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu</label>
              <input :value="form.code" readonly
                class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại phiếu</label>
              <div class="flex gap-3 mt-1">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="radio" v-model="form.type" value="receipt" :disabled="!!voucher" class="text-green-600" />
                  <span class="text-sm font-medium text-green-700">Phiếu thu</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="radio" v-model="form.type" value="payment" :disabled="!!voucher" class="text-red-600" />
                  <span class="text-sm font-medium text-red-700">Phiếu chi</span>
                </label>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ <span class="text-red-500">*</span></label>
              <SearchableSelect
                v-model="form.fund_id"
                :options="fundOptions"
                placeholder="-- Chọn quỹ --"
                :has-error="!!form.errors.fund_id"
                @change="onFundChange"
              />
              <p v-if="form.errors.fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.fund_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày <span class="text-red-500">*</span></label>
              <input v-model="form.voucher_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.voucher_date }" />
              <p v-if="form.errors.voucher_date" class="mt-1 text-xs text-red-600">{{ form.errors.voucher_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
              <input v-model.number="form.amount" type="number" min="1" step="any" @change="onAmountChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.amount }" />
              <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
            </div>

            <!-- Nghiệp vụ -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nghiệp vụ</label>
              <select v-model="form.business_type" @change="onBusinessTypeChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">-- Chọn nghiệp vụ --</option>
                <option v-for="bt in filteredBusinessTypes" :key="bt.value" :value="bt.value">
                  {{ bt.label }}
                </option>
              </select>
            </div>

            <!-- Loại đối tác -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại đối tác</label>
              <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
                <label v-for="opt in partnerTypeOptions" :key="String(opt.value)" class="flex items-center gap-1.5 cursor-pointer">
                  <input type="radio" v-model="form.partner_type" :value="opt.value" class="text-primary-600"
                    @change="onPartnerTypeChange" />
                  <span class="text-sm">{{ opt.label }}</span>
                </label>
              </div>
            </div>

            <!-- Tên đối tác (free text) -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên đối tác</label>
              <input v-model="form.counterparty" type="text" placeholder="Tên người/tổ chức..."
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>

            <!-- Nhà cung cấp -->
            <div v-if="form.partner_type === 'supplier'" class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp</label>
              <SearchableSelect
                v-model="form.supplier_id"
                :options="supplierOptions"
                placeholder="-- Tìm nhà cung cấp --"
                @change="onSupplierChange"
              />
            </div>

            <!-- Khách hàng -->
            <div v-if="form.partner_type === 'customer'" class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng</label>
              <SearchableSelect
                v-model="form.customer_id"
                :options="customerOptions"
                placeholder="-- Tìm khách hàng --"
                @change="onCustomerChange"
              />
            </div>

            <!-- Nhân viên -->
            <div v-if="form.partner_type === 'employee'" class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Nhân viên</label>
              <SearchableSelect
                v-model="form.employee_id"
                :options="employeeOptions"
                placeholder="-- Chọn nhân viên --"
                @change="onEmployeeChange"
              />
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải <span class="text-red-500">*</span></label>
              <input v-model="form.description" type="text" @change="onDescriptionChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.description }" />
              <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
            </div>
          </div>
        </div>

        <!-- Bút toán liên kết -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
              <h2 class="text-base font-semibold text-gray-800">Bút toán liên kết</h2>
              <span v-if="form.journal_mode === 'auto'"
                class="px-2 py-0.5 text-xs rounded-full bg-blue-50 text-blue-700 font-medium">Tự động</span>
              <span v-else
                class="px-2 py-0.5 text-xs rounded-full bg-amber-50 text-amber-700 font-medium">Thủ công</span>
            </div>
            <button v-if="form.journal_mode === 'auto' && form.lines.length > 0" type="button"
              @click="switchToManual"
              class="text-sm text-primary-600 hover:text-primary-800 font-medium">
              Chỉnh sửa thủ công
            </button>
          </div>

          <!-- Bảng bút toán -->
          <div v-if="form.lines.length > 0" class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-200">
                  <th class="pb-2 pr-3 w-28">TK Nợ</th>
                  <th class="pb-2 pr-3 w-28">TK Có</th>
                  <th class="pb-2 pr-3">Diễn giải</th>
                  <th class="pb-2 pr-3 w-32">Đối tượng</th>
                  <th class="pb-2 pr-3 w-32 text-right">Số tiền</th>
                  <th v-if="form.journal_mode === 'manual'" class="pb-2 w-8"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(line, i) in form.lines" :key="i">
                  <!-- TK Nợ -->
                  <td class="py-2 pr-3">
                    <template v-if="form.journal_mode === 'manual'">
                      <input v-model="line.debit_account" type="text" list="account-codes-list"
                        class="w-full px-2 py-1 border border-gray-300 rounded text-xs font-mono focus:ring-1 focus:ring-primary-500 outline-none"
                        placeholder="1121" maxlength="20" />
                    </template>
                    <span v-else class="font-mono text-gray-800">{{ line.debit_account }}</span>
                  </td>
                  <!-- TK Có -->
                  <td class="py-2 pr-3">
                    <template v-if="form.journal_mode === 'manual'">
                      <input v-model="line.credit_account" type="text" list="account-codes-list"
                        class="w-full px-2 py-1 border border-gray-300 rounded text-xs font-mono focus:ring-1 focus:ring-primary-500 outline-none"
                        placeholder="3311" maxlength="20" />
                    </template>
                    <span v-else class="font-mono text-gray-800">{{ line.credit_account }}</span>
                  </td>
                  <!-- Diễn giải -->
                  <td class="py-2 pr-3">
                    <template v-if="form.journal_mode === 'manual'">
                      <input v-model="line.description" type="text"
                        class="w-full px-2 py-1 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-primary-500 outline-none" />
                    </template>
                    <span v-else class="text-gray-700 text-xs">{{ line.description }}</span>
                  </td>
                  <!-- Đối tượng -->
                  <td class="py-2 pr-3">
                    <template v-if="form.journal_mode === 'manual'">
                      <div class="flex gap-1">
                        <select v-model="line.partner_type" @change="line.partner_id = null"
                          class="px-1 py-1 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-primary-500 outline-none">
                          <option :value="null">—</option>
                          <option value="supplier">NCC</option>
                          <option value="customer">KH</option>
                          <option value="employee">NV</option>
                        </select>
                        <select v-if="line.partner_type" v-model="line.partner_id"
                          class="flex-1 px-1 py-1 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-primary-500 outline-none min-w-0">
                          <option :value="null">--</option>
                          <option v-for="p in partnerListFor(line.partner_type)" :key="p.id" :value="p.id">
                            {{ p.name }}
                          </option>
                        </select>
                      </div>
                    </template>
                    <span v-else class="text-xs text-gray-600">{{ partnerLabel(line) }}</span>
                  </td>
                  <!-- Số tiền -->
                  <td class="py-2 pr-3 text-right">
                    <template v-if="form.journal_mode === 'manual'">
                      <input v-model.number="line.amount" type="number" min="0.01" step="any"
                        class="w-full px-2 py-1 border border-gray-300 rounded text-xs text-right focus:ring-1 focus:ring-primary-500 outline-none" />
                    </template>
                    <span v-else class="font-medium text-gray-800">{{ formatVnd(line.amount) }}</span>
                  </td>
                  <!-- Xóa dòng (chỉ manual) -->
                  <td v-if="form.journal_mode === 'manual'" class="py-2 text-center">
                    <button type="button" @click="removeLine(i)"
                      class="text-red-400 hover:text-red-600 text-base leading-none">×</button>
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="form.lines.length > 1" class="border-t border-gray-200">
                <tr>
                  <td colspan="4" class="pt-2 text-xs font-semibold text-gray-500 text-right pr-3">Tổng cộng</td>
                  <td class="pt-2 text-right pr-3 font-bold text-gray-800 text-sm">{{ formatVnd(totalAmount) }}</td>
                  <td v-if="form.journal_mode === 'manual'"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div v-else class="text-sm text-gray-400 italic py-3">
            Chọn nghiệp vụ để tự sinh bút toán, hoặc
            <button type="button" @click="addLine" class="text-primary-600 hover:underline">thêm thủ công</button>.
          </div>

          <!-- Thêm dòng (chỉ manual) -->
          <button v-if="form.journal_mode === 'manual'" type="button" @click="addLine"
            class="mt-3 text-sm text-primary-600 hover:text-primary-800 font-medium flex items-center gap-1">
            <span class="text-lg leading-none">+</span> Thêm dòng bút toán
          </button>

          <!-- Lý do sửa -->
          <div v-if="form.edited_by_user" class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <label class="block text-sm font-medium text-amber-800 mb-1">
              Lý do sửa bút toán <span class="text-red-500">*</span>
            </label>
            <input v-model="form.edit_reason" type="text" placeholder="Nhập lý do điều chỉnh..."
              class="w-full px-3 py-2 border border-amber-300 rounded-lg focus:ring-2 focus:ring-amber-400 outline-none text-sm bg-white"
              :class="{ 'border-red-500': form.errors.edit_reason }" />
            <p v-if="form.errors.edit_reason" class="mt-1 text-xs text-red-600">{{ form.errors.edit_reason }}</p>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-3">
          <Link :href="route('accounting.cash-vouchers.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ voucher ? 'Cập nhật' : 'Lưu phiếu' }}
          </button>
        </div>
      </form>

      <!-- Datalist account codes -->
      <datalist id="account-codes-list">
        <option v-for="ac in accountCodes" :key="ac.code" :value="ac.code">{{ ac.name }}</option>
      </datalist>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  voucher:       Object,
  funds:         Array,
  nextCode:      String,
  defaultType:   String,
  suppliers:     Array,
  customers:     Array,
  employees:     Array,
  businessTypes: Array,
  accountCodes:  Array,
});

const { formatVnd } = useCurrency();

const form = useForm({
  code:            props.voucher?.code         ?? props.nextCode,
  type:            props.voucher?.type         ?? props.defaultType ?? 'receipt',
  fund_id:         props.voucher?.fund_id      ?? '',
  amount:          props.voucher?.amount       ?? '',
  voucher_date:    props.voucher?.voucher_date ?? new Date().toISOString().slice(0, 10),
  counterparty:    props.voucher?.counterparty ?? '',
  partner_type:    props.voucher?.partner_type ?? null,
  supplier_id:     props.voucher?.supplier_id  ?? null,
  customer_id:     props.voucher?.customer_id  ?? null,
  employee_id:     props.voucher?.employee_id  ?? null,
  description:     props.voucher?.description  ?? '',
  business_type:   props.voucher?.business_type ?? '',
  journal_mode:    props.voucher?.journal_mode  ?? 'auto',
  edited_by_user:  props.voucher?.edited_by_user ?? false,
  edit_reason:     props.voucher?.edit_reason   ?? '',
  lines:           props.voucher?.lines?.length ? props.voucher.lines : [],
});

const fundOptions = computed(() =>
  (props.funds ?? []).map(f => ({
    value: f.id,
    label: f.name,
    meta: f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng',
  }))
);
const supplierOptions = computed(() =>
  (props.suppliers ?? []).map(s => ({ value: s.id, code: s.code, label: s.name }))
);
const customerOptions = computed(() =>
  (props.customers ?? []).map(c => ({ value: c.id, code: c.code, label: c.name }))
);
const employeeOptions = computed(() =>
  (props.employees ?? []).map(e => ({ value: e.id, code: e.code, label: e.name }))
);

const partnerTypeOptions = [
  { value: null,       label: 'Không có' },
  { value: 'supplier', label: 'Nhà cung cấp' },
  { value: 'customer', label: 'Khách hàng' },
  { value: 'employee', label: 'Nhân viên' },
];

// Lọc nghiệp vụ theo loại phiếu
const filteredBusinessTypes = computed(() =>
  (props.businessTypes ?? []).filter(bt => {
    const paymentTypes = ['advance_payment', 'pay_offset', 'pay_supplier', 'expense_payment'];
    const receiptTypes = ['advance_return', 'collect_offset', 'collect_customer'];
    return form.type === 'payment' ? paymentTypes.includes(bt.value) : receiptTypes.includes(bt.value);
  })
);

const totalAmount = computed(() =>
  form.lines.reduce((sum, l) => sum + (parseFloat(l.amount) || 0), 0)
);

// ── Fund account resolution ───────────────────────────────────────────────
function fundAccount() {
  const fund = props.funds?.find(f => f.id === form.fund_id);
  return fund?.type === 'bank' ? '1121' : '1111';
}

// ── Default line generation (client-side, mirrors PHP rules) ─────────────
function buildDefaultLines() {
  if (!form.business_type || !form.fund_id) return [];
  const fa   = fundAccount();
  const amt  = parseFloat(form.amount) || 0;
  const desc = form.description || '';

  const partnerForType = () => {
    if (['advance_payment', 'advance_return'].includes(form.business_type)) {
      return { partner_type: 'employee', partner_id: form.employee_id };
    }
    if (form.business_type === 'pay_supplier') {
      const s = props.suppliers?.find(s => s.id === form.supplier_id);
      return { partner_type: 'supplier', partner_id: form.supplier_id,
               _counter: s?.payable_account_code || '3311' };
    }
    if (form.business_type === 'collect_customer') {
      const c = props.customers?.find(c => c.id === form.customer_id);
      return { partner_type: 'customer', partner_id: form.customer_id,
               _counter: c?.receivable_account_code || '1311' };
    }
    return { partner_type: null, partner_id: null };
  };

  const pInfo = partnerForType();

  const counterMap = {
    advance_payment:  '141',
    advance_return:   '141',
    collect_offset:   '3388',
    pay_offset:       '3388',
    pay_supplier:     pInfo._counter || '3311',
    collect_customer: pInfo._counter || '1311',
    expense_payment:  '6422',
  };
  const counter = counterMap[form.business_type] || '6422';

  const receiptTypes = ['advance_return', 'collect_offset', 'collect_customer'];
  const isReceipt = receiptTypes.includes(form.business_type);

  return [{
    debit_account:  isReceipt ? fa : counter,
    credit_account: isReceipt ? counter : fa,
    amount:         amt,
    description:    `${props.businessTypes?.find(b => b.value === form.business_type)?.label ?? ''}: ${desc}`,
    partner_type:   pInfo.partner_type,
    partner_id:     pInfo.partner_id,
  }];
}

function regenerateLines() {
  if (form.journal_mode !== 'auto') return;
  const lines = buildDefaultLines();
  form.lines = lines;
}

// ── Event handlers ────────────────────────────────────────────────────────
function onBusinessTypeChange() {
  if (form.journal_mode === 'auto') {
    regenerateLines();
  }
}

function onFundChange() {
  if (form.journal_mode === 'auto') regenerateLines();
}

function onAmountChange() {
  if (form.journal_mode === 'auto') regenerateLines();
}

function onDescriptionChange() {
  if (form.journal_mode === 'auto') regenerateLines();
}

function onPartnerTypeChange() {
  form.supplier_id = null;
  form.customer_id = null;
  form.employee_id = null;
  if (form.journal_mode === 'auto') regenerateLines();
}

function onEmployeeChange() {
  const emp = props.employees?.find(e => e.id === form.employee_id);
  if (emp) form.counterparty = emp.name;
  if (form.journal_mode === 'auto') regenerateLines();
}

function switchToManual() {
  form.journal_mode   = 'manual';
  form.edited_by_user = true;
}

function addLine() {
  if (form.journal_mode === 'auto') {
    form.journal_mode   = 'manual';
    form.edited_by_user = true;
  }
  form.lines.push({
    debit_account: '', credit_account: '',
    amount: parseFloat(form.amount) || 0,
    description: form.description || '',
    partner_type: null, partner_id: null,
  });
}

function removeLine(i) {
  form.lines.splice(i, 1);
}

// ── Partner helpers ───────────────────────────────────────────────────────
function partnerListFor(type) {
  if (type === 'supplier') return props.suppliers ?? [];
  if (type === 'customer') return props.customers ?? [];
  if (type === 'employee') return props.employees ?? [];
  return [];
}

function partnerLabel(line) {
  if (!line.partner_type || !line.partner_id) return '—';
  const list = partnerListFor(line.partner_type);
  const entity = list.find(p => p.id === line.partner_id);
  const prefix = line.partner_type === 'supplier' ? 'NCC' : line.partner_type === 'customer' ? 'KH' : 'NV';
  return entity ? `${prefix}: ${entity.name}` : '—';
}

// ── Supplier / Customer change handlers ──────────────────────────────────
function onSupplierChange(opt) {
  if (opt) form.counterparty = opt.label;
  if (form.journal_mode === 'auto') regenerateLines();
}

function onCustomerChange(opt) {
  if (opt) form.counterparty = opt.label;
  if (form.journal_mode === 'auto') regenerateLines();
}

// ── Submit ────────────────────────────────────────────────────────────────
function submit() {
  if (props.voucher) {
    form.put(route('accounting.cash-vouchers.update', props.voucher.id));
  } else {
    form.post(route('accounting.cash-vouchers.store'));
  }
}
</script>
