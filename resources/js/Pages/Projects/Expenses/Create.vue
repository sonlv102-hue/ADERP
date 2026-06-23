<template>
  <AppLayout>
    <div class="max-w-5xl space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('projects.projects.show', project.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thêm chi phí phát sinh</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} — {{ project.name }}</p>
        </div>
      </div>

      <!-- Section 1: Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">1. Thông tin chung</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Dự án</label>
            <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-600 font-mono">
              {{ project.code }} — {{ project.name }}
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày chứng từ <span class="text-red-500">*</span></label>
            <input v-model="form.expense_date" type="date"
              :class="['w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500', form.errors.expense_date ? 'border-red-400' : 'border-gray-300']" />
            <p v-if="form.errors.expense_date" class="text-red-500 text-xs mt-1">{{ form.errors.expense_date }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số hóa đơn / chứng từ</label>
            <input v-model="form.invoice_number" type="text" placeholder="Số HĐ NCC, biên lai..."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải chung</label>
          <input v-model="form.description" type="text" placeholder="Mô tả tổng quát cho toàn bộ chứng từ..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <!-- Trợ lý nhập TK Có (tuỳ chọn) -->
        <div class="border border-blue-100 bg-blue-50 rounded-lg p-4 space-y-3">
          <p class="text-xs font-semibold text-blue-700">Trợ lý nhập TK Có (không bắt buộc)</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-600 mb-1">Hình thức ghi nhận</label>
              <select v-model="form.payment_method" @change="onPaymentMethodChange"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">— Chọn nếu muốn hệ thống gợi ý TK Có —</option>
                <option v-for="(mode, key) in PAYMENT_MODES" :key="key" :value="key">{{ mode.label }}</option>
              </select>
              <p class="text-xs text-blue-600 mt-1">Chọn để tự động điền TK Có. Kế toán vẫn có thể sửa từng dòng.</p>
            </div>
            <div v-if="form.payment_method">
              <label class="block text-xs text-gray-600 mb-1">TK Có sẽ điền</label>
              <div class="border border-blue-200 rounded-lg px-3 py-2 text-sm bg-white font-mono text-blue-700">
                {{ resolvedHeaderCredit || '(chưa xác định)' }}
              </div>
            </div>
          </div>
          <!-- Đối tượng liên quan theo hình thức -->
          <div v-if="form.payment_method" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <template v-if="form.payment_method === 'payable'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Nhà cung cấp <span class="text-blue-500">(nên có để theo dõi công nợ)</span></label>
                <RemoteSearchSelect
                  v-model="form.supplier_id"
                  :search-url="route('search.suppliers')"
                  :display-text="form.supplier_name"
                  placeholder="Tìm theo tên, mã NCC..."
                  @change="(opt) => { form.supplier_name = opt ? (opt.code ? opt.code + ' — ' + opt.label : opt.label) : '' }"
                />
              </div>
            </template>
            <template v-else-if="form.payment_method === 'cash'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Quỹ tiền mặt</label>
                <select v-model="form.fund_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn quỹ --</option>
                  <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }} ({{ f.account_code }})</option>
                </select>
              </div>
            </template>
            <template v-else-if="form.payment_method === 'bank'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Tài khoản ngân hàng</label>
                <select v-model="form.bank_account_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn TK ngân hàng --</option>
                  <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} - {{ b.account_number }} ({{ b.account_code }})</option>
                </select>
              </div>
            </template>
            <template v-else-if="form.payment_method === 'advance' || form.payment_method === 'salary'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Nhân viên</label>
                <select v-model="form.employee_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn nhân viên --</option>
                  <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                </select>
              </div>
            </template>
            <template v-else-if="form.payment_method === 'depreciation'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">TSCĐ / máy thi công</label>
                <select v-model="form.fixed_asset_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn TSCĐ --</option>
                  <option v-for="fa in fixedAssets" :key="fa.id" :value="fa.id">{{ fa.code }} — {{ fa.name }}</option>
                </select>
              </div>
            </template>
            <template v-else-if="form.payment_method === 'insurance'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">TK 338 chi tiết <span class="text-red-500">*</span></label>
                <select v-model="form.credit_account_insurance" @change="onInsuranceAccountChange"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn loại khoản trích --</option>
                  <option v-for="ins in INSURANCE_ACCOUNTS" :key="ins.value" :value="ins.value">{{ ins.label }}</option>
                </select>
              </div>
            </template>
            <template v-else-if="form.payment_method === 'misc'">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Tên đội / người nhận khoán</label>
                <input v-model="form.contractor_name" type="text" placeholder="Đội thợ Nguyễn Văn A..."
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 mb-1">Số hợp đồng khoán</label>
                <input v-model="form.contract_number" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Section 2: Bảng dòng chi phí -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
          <div>
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">2. Dòng chi phí</h2>
            <p class="text-xs text-gray-400 mt-0.5">
              TK Nợ / TK Có bắt buộc khi "Lưu và ghi nhận". Nếu TK Nợ là 154 → hạch toán thẳng WIP. Nếu TK Nợ khác 154 → kết chuyển sang 154 sau.
            </p>
          </div>
          <button type="button" @click="addLine"
            class="text-xs bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium">
            + Thêm dòng
          </button>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-28">Danh mục</th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 min-w-[160px]">Mô tả</th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-36">
                  TK Nợ
                  <span class="text-red-400 text-xs font-normal">*ghi nhận</span>
                </th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-36">
                  TK Có
                  <span class="text-red-400 text-xs font-normal">*ghi nhận</span>
                </th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-36">Số tiền (trước VAT) <span class="text-red-500">*</span></th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-20">VAT %</th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-32">Tiền VAT</th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-36">Tổng cộng</th>
                <th class="px-3 py-2.5 w-8" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(line, idx) in form.lines" :key="idx" class="hover:bg-gray-50 align-top">
                <!-- Danh mục -->
                <td class="px-3 py-2">
                  <select v-model="line.category"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
                  </select>
                </td>
                <!-- Mô tả -->
                <td class="px-3 py-2">
                  <input v-model="line.description" type="text" placeholder="Mô tả chi phí..."
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <!-- TK Nợ -->
                <td class="px-3 py-2">
                  <input v-model="line.debit_account" type="text" placeholder="vd: 154, 6237..."
                    :class="['w-full border rounded px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      line.debit_account && /^15[26]/.test(line.debit_account) ? 'border-red-400 bg-red-50' :
                      (submitAttemptedConfirm && !line.debit_account ? 'border-red-300' : 'border-gray-300')]" />
                  <p v-if="line.debit_account && /^15[26]/.test(line.debit_account)" class="text-xs text-red-500 mt-0.5">Dùng phiếu xuất kho</p>
                  <p v-else-if="line.debit_account && line.debit_account.startsWith('154')" class="text-xs text-blue-600 mt-0.5">→ WIP dự án</p>
                  <p v-else-if="line.debit_account" class="text-xs text-amber-600 mt-0.5">→ Kết chuyển sau</p>
                  <p v-else-if="submitAttemptedConfirm" class="text-xs text-red-500 mt-0.5">Bắt buộc khi ghi nhận</p>
                </td>
                <!-- TK Có -->
                <td class="px-3 py-2">
                  <input v-model="line.credit_account" type="text" placeholder="vd: 3311, 3388..."
                    :class="['w-full border rounded px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      submitAttemptedConfirm && !line.credit_account ? 'border-red-300' : 'border-gray-300']" />
                  <!-- Gợi ý liên quan đối tượng -->
                  <p v-if="line.credit_account === '3311' && !form.supplier_id"
                    class="text-xs text-amber-600 mt-0.5">Nên chọn NCC ở phần trợ lý</p>
                  <p v-else-if="line.credit_account === '3312' && !form.supplier_id"
                    class="text-xs text-amber-600 mt-0.5">Nên chọn NCC ở phần trợ lý</p>
                  <p v-else-if="line.credit_account === '1111' && !form.fund_id"
                    class="text-xs text-amber-600 mt-0.5">Nên chọn quỹ tiền mặt</p>
                  <p v-else-if="line.credit_account === '1121' && !form.bank_account_id"
                    class="text-xs text-amber-600 mt-0.5">Nên chọn TK ngân hàng</p>
                  <p v-else-if="submitAttemptedConfirm && !line.credit_account" class="text-xs text-red-500 mt-0.5">Bắt buộc khi ghi nhận</p>
                </td>
                <!-- Số tiền excl VAT -->
                <td class="px-3 py-2">
                  <input v-model="line.amount" type="number" min="0" step="1" placeholder="0"
                    :class="['w-full border rounded px-2 py-1.5 text-xs text-right font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      submitAttemptedConfirm && !(Number(line.amount) > 0) ? 'border-red-300' : 'border-gray-300']"
                    @input="computeLineVat(line)" />
                </td>
                <!-- VAT % -->
                <td class="px-3 py-2">
                  <input v-model="line.vat_rate" type="number" min="0" max="100" step="1" placeholder="0"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary-500"
                    @input="computeLineVat(line)" />
                </td>
                <!-- Tiền VAT -->
                <td class="px-3 py-2">
                  <input v-model="line.vat_amount" type="number" min="0" step="1" placeholder="0"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs text-right font-mono focus:outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <!-- Tổng cộng -->
                <td class="px-3 py-2 text-right font-mono text-sm font-medium text-gray-800 whitespace-nowrap">
                  {{ formatVnd(lineTotal(line)) }}
                </td>
                <!-- Xóa dòng -->
                <td class="px-3 py-2 text-center">
                  <button type="button" @click="removeLine(idx)"
                    class="text-gray-300 hover:text-red-500 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>

              <tr v-if="form.lines.length === 0">
                <td colspan="9" class="px-4 py-8 text-center text-gray-400 text-sm">
                  Chưa có dòng chi phí nào.
                  <button type="button" @click="addLine" class="text-primary-600 hover:underline ml-1">Thêm dòng đầu tiên</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Section 3: Tổng hợp + Bút toán dự kiến + Nút hành động -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6">
          <!-- Tổng hợp số tiền -->
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between gap-8">
              <span class="text-gray-500">Tổng trước VAT:</span>
              <span class="font-mono font-medium text-gray-800">{{ formatVnd(totalBeforeVat) }}</span>
            </div>
            <div class="flex justify-between gap-8">
              <span class="text-gray-500">Tổng VAT:</span>
              <span class="font-mono font-medium text-gray-800">{{ formatVnd(totalVat) }}</span>
            </div>
            <div class="flex justify-between gap-8 border-t border-gray-200 pt-1.5 mt-1">
              <span class="font-semibold text-gray-700">Tổng cộng:</span>
              <span class="font-mono font-bold text-gray-900 text-base">{{ formatVnd(totalAmount) }}</span>
            </div>
            <div v-if="totalTo154 > 0" class="flex justify-between gap-8 text-xs">
              <span class="text-blue-600">→ Vào TK 154 (WIP):</span>
              <span class="font-mono font-medium text-blue-700">{{ formatVnd(totalTo154) }}</span>
            </div>
            <div v-if="totalNeedTransfer > 0" class="flex justify-between gap-8 text-xs">
              <span class="text-amber-600">→ Cần kết chuyển sang 154:</span>
              <span class="font-mono font-medium text-amber-700">{{ formatVnd(totalNeedTransfer) }}</span>
            </div>
          </div>

          <!-- Bút toán dự kiến -->
          <div class="bg-gray-50 rounded-lg p-4 text-xs min-w-[240px] space-y-1">
            <p class="font-semibold text-gray-600 mb-2">Bút toán dự kiến:</p>
            <template v-for="(line, i) in form.lines" :key="i">
              <template v-if="line.debit_account && line.credit_account && Number(line.amount) > 0">
                <div class="font-mono">
                  <span class="text-blue-700">Nợ {{ line.debit_account }}</span>
                  <span class="text-gray-500 ml-2">{{ formatVnd(Number(line.amount)) }}</span>
                </div>
                <div v-if="Number(line.vat_amount) > 0" class="font-mono">
                  <span class="text-blue-700">Nợ 1331</span>
                  <span class="text-gray-500 ml-2">{{ formatVnd(Number(line.vat_amount)) }}</span>
                </div>
                <div class="font-mono border-t border-gray-200 pt-0.5 mt-0.5">
                  <span class="text-red-600">Có {{ line.credit_account }}</span>
                  <span class="text-gray-500 ml-2">{{ formatVnd(lineTotal(line)) }}</span>
                </div>
                <div v-if="i < form.lines.length - 1" class="border-t border-dashed border-gray-200 my-1" />
              </template>
              <template v-else>
                <div class="text-gray-400 italic text-xs py-0.5">
                  Dòng {{ i + 1 }}: Chưa đủ dữ liệu lập bút toán
                </div>
              </template>
            </template>
            <div v-if="form.lines.length === 0" class="text-gray-400 italic">Chưa có dòng chi phí</div>
          </div>
        </div>

        <!-- Lỗi submit -->
        <p v-if="submitError" class="text-red-600 text-xs mt-3 bg-red-50 px-3 py-2 rounded-lg">{{ submitError }}</p>

        <!-- Nút hành động -->
        <div class="flex flex-wrap justify-end gap-3 mt-5 pt-4 border-t border-gray-100">
          <Link :href="route('projects.projects.show', project.id)"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            Hủy
          </Link>
          <button type="button" @click="submit(false)"
            :disabled="form.processing || !canDraft"
            class="px-4 py-2 border border-gray-400 rounded-lg text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40">
            {{ form.processing ? 'Đang xử lý...' : 'Lưu nháp' }}
          </button>
          <button type="button" @click="submit(true)"
            :disabled="form.processing || !canDraft"
            class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white rounded-lg text-sm font-medium">
            {{ form.processing ? 'Đang xử lý...' : 'Lưu và ghi nhận' }}
          </button>
        </div>
      </div>
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
  expenseCategories: Array,
  funds:             Array,
  bankAccounts:      Array,
  employees:         Array,
  fixedAssets:       Array,
});

const { formatVnd } = useCurrency();

const PAYMENT_MODES = {
  payable:      { label: 'Ghi công nợ NCC / nhà thầu có HĐ → Có 3311', credit: '3311' },
  cash:         { label: 'Chi tiền mặt → Có 1111',                      credit: '1111' },
  bank:         { label: 'Chi ngân hàng → Có 1121',                     credit: '1121' },
  advance:      { label: 'Quyết toán tạm ứng → Có 141',                 credit: '141'  },
  salary:       { label: 'Ghi nhận lương nội bộ → Có 3341',             credit: '3341' },
  misc:         { label: 'Thuê khoán / chưa trả → Có 3388',             credit: '3388' },
  insurance:    { label: 'Trích BHXH / KPCĐ → Có 338 chi tiết',         credit: ''     },
  depreciation: { label: 'Khấu hao TSCĐ / máy thi công → Có 214',      credit: '214'  },
};

const INSURANCE_ACCOUNTS = [
  { value: '33831', label: '33831 — BHXH người sử dụng lao động (17.5%)' },
  { value: '33832', label: '33832 — BHXH người lao động (8%)' },
  { value: '33841', label: '33841 — BHYT người sử dụng lao động (3%)' },
  { value: '33842', label: '33842 — BHYT người lao động (1.5%)' },
  { value: '3385',  label: '3385 — BHTN (gộp NLĐ + NSDLĐ)' },
  { value: '33821', label: '33821 — KPCĐ người sử dụng lao động (2%)' },
];

const submitError = ref('');
const submitAttemptedConfirm = ref(false);

const form = useForm({
  expense_date:             '',
  invoice_number:           '',
  payment_method:           '',   // optional helper — không bắt buộc
  description:              '',
  supplier_id:              null,
  supplier_name:            '',
  fund_id:                  '',
  bank_account_id:          '',
  employee_id:              '',
  fixed_asset_id:           '',
  credit_account_insurance: '',   // for insurance sub-TK
  contractor_name:          '',
  contractor_representative:'',
  contractor_phone:         '',
  contractor_id_number:     '',
  contract_number:          '',
  post_immediately:         true,
  lines: [],
});

// TK Có được resolve từ payment_method (header-level)
const resolvedHeaderCredit = computed(() => {
  if (!form.payment_method) return '';
  if (form.payment_method === 'insurance') return form.credit_account_insurance || '';
  if (form.payment_method === 'cash' && form.fund_id) {
    return props.funds.find(f => f.id == form.fund_id)?.account_code || '1111';
  }
  if (form.payment_method === 'bank' && form.bank_account_id) {
    return props.bankAccounts.find(b => b.id == form.bank_account_id)?.account_code || '1121';
  }
  return PAYMENT_MODES[form.payment_method]?.credit || '';
});

function makeLine() {
  const creditFromHelper = resolvedHeaderCredit.value;
  return {
    category:               'other',
    description:            '',
    debit_account:          '',   // không mặc định
    credit_account:         creditFromHelper || '',  // điền từ helper nếu đã chọn
    amount:                 '',
    vat_rate:               '',
    vat_amount:             '',
    has_vat_invoice:        false,
    pit_withholding_enabled: false,
    pit_rate:               10,
  };
}

function addLine() {
  form.lines.push(makeLine());
}

function removeLine(idx) {
  form.lines.splice(idx, 1);
}

addLine();

function onPaymentMethodChange() {
  // Reset related objects
  form.supplier_id     = null;
  form.supplier_name   = '';
  form.fund_id         = '';
  form.bank_account_id = '';
  form.employee_id     = '';
  form.fixed_asset_id  = '';
  form.credit_account_insurance = '';
  submitError.value    = '';

  // Auto-fill credit_account on lines that don't have one yet
  const creditTk = PAYMENT_MODES[form.payment_method]?.credit || '';
  if (form.payment_method !== 'insurance' && creditTk) {
    form.lines.forEach(l => {
      if (!l.credit_account) l.credit_account = creditTk;
    });
  }
}

function onInsuranceAccountChange() {
  const creditTk = form.credit_account_insurance;
  if (creditTk) {
    form.lines.forEach(l => {
      if (!l.credit_account || INSURANCE_ACCOUNTS.some(a => a.value === l.credit_account)) {
        l.credit_account = creditTk;
      }
    });
  }
}

function computeLineVat(line) {
  const amount  = parseFloat(line.amount) || 0;
  const vatRate = parseFloat(line.vat_rate) || 0;
  line.vat_amount = vatRate > 0 ? Math.round(amount * vatRate / 100) : '';
}

function lineTotal(line) {
  return (parseFloat(line.amount) || 0) + (parseFloat(line.vat_amount) || 0);
}

const totalBeforeVat = computed(() =>
  form.lines.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);
const totalVat = computed(() =>
  form.lines.reduce((s, l) => s + (parseFloat(l.vat_amount) || 0), 0)
);
const totalAmount = computed(() => totalBeforeVat.value + totalVat.value);

const totalTo154 = computed(() =>
  form.lines
    .filter(l => l.debit_account?.startsWith('154'))
    .reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);
const totalNeedTransfer = computed(() =>
  form.lines
    .filter(l => l.debit_account && !l.debit_account.startsWith('154') && !/^15[26]/.test(l.debit_account))
    .reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);

// Lưu nháp: chỉ cần ngày chứng từ và ít nhất 1 dòng
const canDraft = computed(() => {
  if (!form.expense_date) return false;
  if (form.lines.length === 0) return false;
  return true;
});

// Lưu và ghi nhận: mỗi dòng phải có TK Nợ, TK Có, số tiền > 0; không được dùng TK 152/156
const canConfirm = computed(() => {
  if (!canDraft.value) return false;
  return form.lines.every(l =>
    l.debit_account &&
    l.credit_account &&
    Number(l.amount) > 0 &&
    !/^15[26]/.test(l.debit_account)
  );
});

function submit(postImmediately) {
  submitError.value = '';

  if (!canDraft.value) {
    submitError.value = 'Vui lòng nhập ngày chứng từ và ít nhất một dòng chi phí.';
    return;
  }

  if (postImmediately) {
    submitAttemptedConfirm.value = true;
    if (!canConfirm.value) {
      submitError.value = 'Để ghi nhận, mỗi dòng cần có TK Nợ, TK Có và số tiền > 0. Không được dùng TK 152/156.';
      return;
    }
  }

  form.post_immediately = postImmediately;

  const cleanLines = form.lines.map(l => ({
    category:    l.category,
    description: l.description || null,
    debit_account:  l.debit_account || null,
    credit_account: l.credit_account || null,
    amount:      Math.round(parseFloat(l.amount) || 0),
    vat_rate:    parseFloat(l.vat_rate) || 0,
    vat_amount:  Math.round(parseFloat(l.vat_amount) || 0),
    has_vat_invoice:          !!l.has_vat_invoice,
    pit_withholding_enabled:  false,
    pit_rate:    0,
    labor_type:  null,
  }));

  // Đưa credit_account insurance vào form.credit_account để backend resolve
  const extraData = form.payment_method === 'insurance'
    ? { credit_account: form.credit_account_insurance || null }
    : {};

  form.transform(data => ({
    ...data,
    lines: cleanLines,
    ...extraData,
  })).post(route('projects.projects.expenses.batch', props.project.id), {
    onError: (errors) => {
      const first = Object.values(errors)[0];
      submitError.value = first ?? 'Có lỗi xảy ra. Vui lòng kiểm tra lại.';
    },
  });
}
</script>
