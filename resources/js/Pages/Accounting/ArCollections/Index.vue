<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thu nợ khách hàng (TK 131)</h1>
          <p class="text-sm text-gray-500 mt-0.5">Hóa đơn chưa thu + công nợ đầu kỳ — ghi nhận tiền nhận được</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3">
        <select v-model="filters.customer_id" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none min-w-48">
          <option value="">Tất cả khách hàng</option>
          <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select v-model="filters.status" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <div class="ml-auto flex items-center gap-4 text-sm text-gray-500">
          <span>Tổng ứng trước KH:
            <span class="font-semibold text-blue-600">{{ formatVnd(totalAdvanceAvailable) }}</span>
          </span>
          <span>Tổng cần thu:
            <span class="font-semibold text-red-600">{{ formatVnd(totalDue) }}</span>
          </span>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hóa đơn / Chứng từ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn thu</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã thu</th>
              <th class="text-right px-5 py-3 font-semibold text-red-600">Còn phải thu</th>
              <th class="text-right px-5 py-3 font-semibold text-blue-600">Ứng trước KH</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in items" :key="item.source_type + '-' + item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">
                <template v-if="item.source_type === 'invoice'">
                  <Link :href="route('accounting.invoices.show', item.id)" class="text-primary-600 hover:underline">
                    {{ item.code }}
                  </Link>
                </template>
                <template v-else>
                  <span class="text-gray-700">{{ item.code }}</span>
                  <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                    Đầu kỳ
                  </span>
                </template>
              </td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ item.customer }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ item.issue_date }}</td>
              <td class="px-5 py-3 whitespace-nowrap" :class="isOverdue(item) ? 'text-red-600 font-medium' : 'text-gray-600'">
                {{ item.due_date ?? '—' }}
                <span v-if="isOverdue(item)" class="ml-1 text-xs">(Quá hạn)</span>
              </td>
              <td class="px-5 py-3 text-right text-gray-800">{{ formatVnd(item.total) }}</td>
              <td class="px-5 py-3 text-right text-green-700">{{ formatVnd(item.amount_paid) }}</td>
              <td class="px-5 py-3 text-right font-semibold text-red-600">{{ formatVnd(item.amount_due) }}</td>
              <td class="px-5 py-3 text-right">
                <span v-if="item.advance_available > 0"
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  {{ formatVnd(item.advance_available) }}
                </span>
                <span v-else class="text-gray-400 text-xs">—</span>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="item.status_color">{{ item.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <button @click="openPayment(item)"
                  class="px-3 py-1.5 text-xs font-medium rounded-lg"
                  :class="item.source_type === 'invoice' && item.advance_available > 0
                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                    : 'bg-primary-600 text-white hover:bg-primary-700'">
                  {{ item.source_type === 'invoice' && item.advance_available > 0 ? 'Thu / Đối trừ' : 'Ghi thu tiền' }}
                </button>
              </td>
            </tr>
            <tr v-if="!items.length">
              <td colspan="10" class="px-5 py-10 text-center text-gray-400">Không có khoản cần thu</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Payment Modal -->
    <Modal :show="payModal !== null" @close="closeModal" max-width="2xl">
      <template #title>Ghi nhận thu tiền — {{ payModal?.code }}</template>
      <div class="space-y-4" v-if="payModal">

        <!-- Info header -->
        <div class="p-3 bg-blue-50 rounded-lg text-sm grid grid-cols-2 gap-x-4 gap-y-1">
          <div class="col-span-2 font-semibold text-gray-800">{{ payModal.customer }}
            <span class="ml-2 font-mono text-xs text-gray-500">{{ payModal.code }}</span>
          </div>
          <div class="text-gray-600">Tổng hóa đơn: <span class="font-medium text-gray-900">{{ formatVnd(payModal.total) }}</span></div>
          <div class="text-gray-600">Đã thu: <span class="font-medium text-green-700">{{ formatVnd(payModal.amount_paid) }}</span></div>
          <div class="text-gray-600">Còn phải thu: <span class="font-semibold text-red-600">{{ formatVnd(payModal.amount_due) }}</span></div>
          <div v-if="totalOffset > 0" class="text-gray-600">
            Sau đối trừ: <span class="font-semibold text-blue-600">{{ formatVnd(Math.max(0, payModal.amount_due - totalOffset)) }}</span>
          </div>
          <div v-if="payModal.source_type === 'opening_balance'" class="col-span-2 text-amber-700 text-xs font-medium mt-1">
            Công nợ đầu kỳ
          </div>
        </div>

        <!-- Payment type selector (only for invoices with advances) -->
        <div v-if="payModal.source_type === 'invoice' && !loadingAdvances && advances.length > 0">
          <label class="block text-sm font-medium text-gray-700 mb-2">Kiểu xử lý thanh toán <span class="text-red-500">*</span></label>
          <div class="flex gap-2 flex-wrap">
            <button v-for="t in paymentTypes" :key="t.value"
              @click="paymentType = t.value"
              :class="paymentType === t.value
                ? 'bg-primary-600 text-white border-primary-600'
                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
              class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors">
              {{ t.label }}
            </button>
          </div>
        </div>

        <!-- Loading spinner for advances -->
        <div v-if="payModal.source_type === 'invoice' && loadingAdvances"
          class="py-3 text-center text-sm text-gray-400">
          Đang kiểm tra ứng trước khách hàng...
        </div>

        <!-- ============================================================ -->
        <!-- OFFSET section (offset / combined) -->
        <!-- ============================================================ -->
        <div v-if="(paymentType === 'offset' || paymentType === 'combined') && advances.length > 0"
          class="border border-blue-200 rounded-lg overflow-hidden">
          <div class="bg-blue-50 px-4 py-2 flex items-center justify-between">
            <span class="text-sm font-semibold text-blue-800">Khoản ứng trước có thể đối trừ</span>
            <span v-if="totalOffset > 0" class="text-sm font-medium text-blue-700">
              Tổng đối trừ: {{ formatVnd(totalOffset) }}
            </span>
          </div>

          <!-- Allocation date -->
          <div class="px-4 py-3 border-b border-blue-100 flex items-center gap-3">
            <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Ngày đối trừ <span class="text-red-500">*</span></label>
            <input v-model="allocationDate" type="date"
              class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
          </div>

          <table class="w-full text-xs">
            <thead class="bg-gray-50 border-b border-blue-100">
              <tr>
                <th class="px-3 py-2 text-left font-semibold text-gray-600">Ngày CT</th>
                <th class="px-3 py-2 text-left font-semibold text-gray-600">Loại</th>
                <th class="px-3 py-2 text-left font-semibold text-gray-600">Số CT</th>
                <th class="px-3 py-2 text-right font-semibold text-gray-600">Số tiền gốc</th>
                <th class="px-3 py-2 text-right font-semibold text-gray-600">Còn khả dụng</th>
                <th class="px-3 py-2 text-right font-semibold text-blue-700">Đối trừ lần này</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="adv in advances" :key="adv.id" class="hover:bg-blue-50">
                <td class="px-3 py-2 text-gray-600">{{ adv.advance_date }}</td>
                <td class="px-3 py-2">
                  <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">
                    {{ adv.advance_type_label }}
                  </span>
                </td>
                <td class="px-3 py-2 font-mono text-gray-600">{{ adv.reference_no || '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-800">{{ formatVnd(adv.amount) }}</td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">{{ formatVnd(adv.remaining_amount) }}</td>
                <td class="px-3 py-2 text-right">
                  <input
                    type="number"
                    :max="adv.remaining_amount"
                    min="0"
                    :value="advAllocAmounts[adv.id] ?? 0"
                    @input="setAllocAmount(adv.id, $event.target.value, adv.remaining_amount)"
                    class="w-32 px-2 py-1 border border-gray-300 rounded text-right text-xs focus:ring-2 focus:ring-blue-500 outline-none"
                    placeholder="0" />
                </td>
              </tr>
            </tbody>
          </table>

          <p v-if="offsetError" class="px-4 py-2 text-xs text-red-600 bg-red-50 border-t border-red-200">
            {{ offsetError }}
          </p>
        </div>

        <!-- ============================================================ -->
        <!-- CASH section (cash / combined / opening_balance) -->
        <!-- ============================================================ -->
        <div v-if="paymentType === 'cash' || paymentType === 'combined' || payModal.source_type === 'opening_balance'"
          class="space-y-4">
          <div v-if="paymentType === 'combined'"
            class="px-3 py-2 bg-gray-50 rounded-lg text-sm text-gray-600 border border-gray-200">
            Thu thêm bằng tiền mặt / chuyển khoản sau khi đối trừ
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                {{ paymentType === 'combined' ? 'Số tiền thu thêm' : 'Số tiền thu' }}
                <span class="text-red-500">*</span>
              </label>
              <input v-model.number="payForm.amount" type="number" min="0"
                :max="paymentType === 'combined' ? Math.max(0, payModal.amount_due - totalOffset) : payModal.amount_due"
                :class="payAmountError ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-primary-500'"
                class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none text-sm" />
              <p v-if="payAmountError" class="mt-1 text-xs text-red-600">{{ payAmountError }}</p>
              <p v-if="paymentType === 'combined' && totalOffset > 0" class="mt-1 text-xs text-gray-500">
                Tối đa: {{ formatVnd(Math.max(0, payModal.amount_due - totalOffset)) }}
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày thu <span class="text-red-500">*</span></label>
              <input v-model="payForm.payment_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
            </div>
          </div>

          <!-- Hình thức thanh toán -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức thanh toán <span class="text-red-500">*</span></label>
            <select v-model="payForm.method" @change="payForm.fund_id = null"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm">
              <option value="cash">Tiền mặt</option>
              <option value="bank_transfer">Chuyển khoản</option>
              <option value="other">Khác</option>
            </select>
          </div>

          <!-- Chọn quỹ -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              {{ payForm.method === 'cash' ? 'Quỹ tiền mặt nhận' : (payForm.method === 'bank_transfer' ? 'Tài khoản ngân hàng nhận' : 'Nguồn tiền') }}
              <span class="text-red-500">*</span>
            </label>
            <select v-model="payForm.fund_id"
              :class="payFundError ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-primary-500'"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none text-sm">
              <option :value="null">-- Chọn {{ payForm.method === 'bank_transfer' ? 'tài khoản ngân hàng' : 'quỹ' }} --</option>
              <option v-for="f in filteredFunds" :key="f.id" :value="f.id">
                {{ f.name }}{{ f.account_code ? ` (TK ${f.account_code})` : '' }}
              </option>
            </select>
            <p v-if="payFundError" class="mt-1 text-xs text-red-600">{{ payFundError }}</p>
            <p v-if="!filteredFunds.length && payForm.method !== 'other'" class="mt-1 text-xs text-amber-600">
              Chưa có quỹ {{ payForm.method === 'bank_transfer' ? 'ngân hàng' : 'tiền mặt' }} nào. Vui lòng tạo quỹ trong Kế toán → Quỹ.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tham chiếu (UNC / Số séc)</label>
            <input v-model="payForm.reference" type="text" placeholder="Số UNC, số séc, số giao dịch..."
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="payForm.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
          </div>
        </div>

        <!-- Summary for combined -->
        <div v-if="paymentType === 'combined' && (totalOffset > 0 || payForm.amount > 0)"
          class="p-3 bg-gray-50 rounded-lg text-sm border border-gray-200">
          <div class="flex justify-between text-gray-600">
            <span>Đối trừ ứng trước:</span>
            <span class="font-medium text-blue-700">{{ formatVnd(totalOffset) }}</span>
          </div>
          <div class="flex justify-between text-gray-600 mt-1">
            <span>Thu thêm:</span>
            <span class="font-medium text-gray-900">{{ formatVnd(payForm.amount || 0) }}</span>
          </div>
          <div class="flex justify-between font-semibold mt-2 pt-2 border-t border-gray-300">
            <span>Tổng xử lý:</span>
            <span class="text-primary-700">{{ formatVnd(totalOffset + (payForm.amount || 0)) }}</span>
          </div>
          <div class="flex justify-between text-gray-500 text-xs mt-1">
            <span>Còn phải thu sau:</span>
            <span>{{ formatVnd(Math.max(0, payModal.amount_due - totalOffset - (payForm.amount || 0))) }}</span>
          </div>
        </div>

      </div>
      <template #footer>
        <button @click="closeModal" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="submitPayment" :disabled="submitting"
          class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-60">
          {{ submitLabel }}
        </button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  items:     Array,
  customers: Array,
  funds:     Array,
  statuses:  Array,
  filters:   Object,
});

const { formatVnd } = useCurrency();

const filters = ref({
  customer_id: props.filters.customer_id ?? '',
  status:      props.filters.status ?? '',
});

const paymentTypes = [
  { value: 'cash',     label: 'Thu tiền' },
  { value: 'offset',   label: 'Đối trừ ứng trước' },
  { value: 'combined', label: 'Đối trừ + thu thêm' },
];

// Modal state
const payModal        = ref(null);
const paymentType     = ref('cash');
const advances        = ref([]);
const advAllocAmounts = ref({});
const allocationDate  = ref('');
const loadingAdvances = ref(false);
const submitting      = ref(false);
const payAmountError  = ref('');
const payFundError    = ref('');
const offsetError     = ref('');

const payForm = ref({
  amount: 0, payment_date: new Date().toISOString().slice(0, 10),
  method: 'bank_transfer', fund_id: null, reference: '', notes: '',
});

const totalDue = computed(() => (props.items ?? []).reduce((s, i) => s + i.amount_due, 0));
const totalAdvanceAvailable = computed(() => (props.items ?? []).reduce((s, i) => s + i.advance_available, 0));

const totalOffset = computed(() =>
  Object.values(advAllocAmounts.value).reduce((s, v) => s + (parseFloat(v) || 0), 0)
);

const filteredFunds = computed(() => {
  const method = payForm.value.method;
  if (method === 'cash')          return (props.funds ?? []).filter(f => f.type === 'cash');
  if (method === 'bank_transfer') return (props.funds ?? []).filter(f => f.type === 'bank');
  return props.funds ?? [];
});

const submitLabel = computed(() => {
  if (submitting.value) return 'Đang lưu...';
  if (paymentType.value === 'offset')   return 'Xác nhận đối trừ';
  if (paymentType.value === 'combined') return 'Xác nhận đối trừ & thu tiền';
  return 'Xác nhận thu tiền';
});

function isOverdue(item) {
  return item.status === 'overdue';
}

function applyFilters() {
  router.get(route('accounting.ar-collections.index'), {
    customer_id: filters.value.customer_id || undefined,
    status:      filters.value.status || undefined,
  }, { preserveState: true });
}

function setAllocAmount(advanceId, value, max) {
  const v = Math.min(parseFloat(value) || 0, parseFloat(max) || 0);
  advAllocAmounts.value = { ...advAllocAmounts.value, [advanceId]: v < 0 ? 0 : v };
}

function getActiveAllocations() {
  return advances.value
    .filter(a => (parseFloat(advAllocAmounts.value[a.id]) || 0) > 0)
    .map(a => ({ id: a.id, amount: parseFloat(advAllocAmounts.value[a.id]) }));
}

async function openPayment(item) {
  payModal.value        = item;
  paymentType.value     = 'cash';
  advances.value        = [];
  advAllocAmounts.value = {};
  allocationDate.value  = new Date().toISOString().slice(0, 10);
  loadingAdvances.value = false;
  submitting.value      = false;
  payAmountError.value  = '';
  payFundError.value    = '';
  offsetError.value     = '';

  payForm.value = {
    amount:       item.amount_due,
    payment_date: new Date().toISOString().slice(0, 10),
    method:       'bank_transfer',
    fund_id:      null,
    reference:    '',
    notes:        '',
  };

  // Load available advances for invoices only
  if (item.source_type === 'invoice' && item.customer_id) {
    loadingAdvances.value = true;
    try {
      const resp = await fetch(route('accounting.ar-collections.customer-advances') + '?customer_id=' + item.customer_id);
      const data = await resp.json();
      advances.value = data;
      const amounts = {};
      data.forEach(a => { amounts[a.id] = 0; });
      advAllocAmounts.value = amounts;
    } catch {
      // silently ignore — user can still use cash mode
    }
    loadingAdvances.value = false;
  }
}

function closeModal() {
  payModal.value = null;
}

// Submit advance allocations one by one, then optionally submit cash
function submitOffsets(pending, thenCash) {
  if (!pending.length) {
    if (thenCash) {
      submitCashPayment();
    } else {
      payModal.value = null;
      submitting.value = false;
    }
    return;
  }

  const [head, ...tail] = pending;
  router.post(
    route('accounting.invoices.advance-allocations.store', payModal.value.id),
    {
      opening_advance_id: head.id,
      allocated_amount:   head.amount,
      allocation_date:    allocationDate.value,
    },
    {
      preserveScroll: true,
      onSuccess: () => submitOffsets(tail, thenCash),
      onError: (errors) => {
        submitting.value = false;
        offsetError.value = errors.error || errors.allocated_amount || 'Lỗi khi đối trừ ứng trước.';
      },
    }
  );
}

function submitCashPayment() {
  const item = payModal.value;
  const url = item.source_type === 'opening_balance'
    ? route('accounting.ar-ap-opening-balance.pay', item.id)
    : route('accounting.invoices.payments.store', item.id);

  router.post(url, {
    amount:       payForm.value.amount,
    payment_date: payForm.value.payment_date,
    method:       payForm.value.method,
    fund_id:      payForm.value.fund_id,
    reference:    payForm.value.reference || null,
    notes:        payForm.value.notes || null,
  }, {
    onSuccess: () => { payModal.value = null; submitting.value = false; },
    onError:   (errors) => {
      submitting.value = false;
      if (errors.fund_id) payFundError.value = errors.fund_id;
      if (errors.amount)  payAmountError.value = errors.amount;
    },
  });
}

function submitPayment() {
  payAmountError.value = '';
  payFundError.value   = '';
  offsetError.value    = '';

  const item = payModal.value;
  const type = paymentType.value;

  // Validate offset
  if (type === 'offset' || type === 'combined') {
    const activeAllocs = getActiveAllocations();
    if (!activeAllocs.length) {
      offsetError.value = 'Vui lòng nhập số tiền đối trừ ít nhất một khoản ứng trước.';
      return;
    }
    if (!allocationDate.value) {
      offsetError.value = 'Vui lòng chọn ngày đối trừ.';
      return;
    }
    if (totalOffset.value > item.amount_due + 0.01) {
      offsetError.value = `Tổng đối trừ (${formatVnd(totalOffset.value)}) vượt quá số còn phải thu (${formatVnd(item.amount_due)}).`;
      return;
    }
  }

  // Validate cash
  if (type === 'cash' || type === 'combined' || item.source_type === 'opening_balance') {
    const cashAmt = parseFloat(payForm.value.amount) || 0;
    const maxCash = type === 'combined'
      ? Math.max(0, item.amount_due - totalOffset.value)
      : item.amount_due;

    if (cashAmt <= 0) {
      payAmountError.value = 'Vui lòng nhập số tiền hợp lệ (lớn hơn 0).';
      return;
    }
    if (cashAmt > maxCash + 0.01) {
      payAmountError.value = `Số tiền không được vượt quá ${formatVnd(maxCash)}.`;
      return;
    }
    if (!payForm.value.payment_date) {
      payAmountError.value = 'Vui lòng chọn ngày thu.';
      return;
    }
    if (!payForm.value.fund_id) {
      payFundError.value = 'Vui lòng chọn quỹ hoặc tài khoản ngân hàng nhận tiền.';
      return;
    }
  }

  submitting.value = true;

  if ((type === 'offset' || type === 'combined') && item.source_type === 'invoice') {
    submitOffsets(getActiveAllocations(), type === 'combined');
  } else {
    submitCashPayment();
  }
}
</script>
