<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thanh toán nhà cung cấp (TK 331)</h1>
          <p class="text-sm text-gray-500 mt-0.5">Hóa đơn NCC chưa thanh toán + công nợ đầu kỳ — ghi nhận tiền đã trả</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3">
        <select v-model="filters.supplier_id" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none min-w-48">
          <option value="">Tất cả nhà cung cấp</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select v-model="filters.status" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <div class="ml-auto text-sm text-gray-500 self-center">
          Tổng cần trả: <span class="font-semibold text-orange-600">{{ formatVnd(totalDue) }}</span>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HĐ / Chứng từ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn trả</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã trả</th>
              <th class="text-right px-5 py-3 font-semibold text-orange-600">Còn phải trả</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in items" :key="item.source_type + '-' + item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">
                <template v-if="item.source_type === 'purchase_invoice'">
                  <Link :href="route('purchasing.purchase-invoices.show', item.id)" class="text-primary-600 hover:underline">
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
              <td class="px-5 py-3 text-gray-800 font-medium">{{ item.supplier }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ item.invoice_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ item.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-800">{{ formatVnd(item.total) }}</td>
              <td class="px-5 py-3 text-right text-green-700">{{ formatVnd(item.amount_paid) }}</td>
              <td class="px-5 py-3 text-right font-semibold text-orange-600">{{ formatVnd(item.amount_due) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="item.status_color">{{ item.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <button @click="openPayment(item)"
                  class="px-3 py-1.5 text-xs font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700">
                  Ghi trả tiền
                </button>
              </td>
            </tr>
            <tr v-if="!items.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Không có khoản cần thanh toán</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Payment Modal -->
    <Modal :show="payModal !== null" @close="payModal = null">
      <template #title>Ghi nhận thanh toán NCC — {{ payModal?.code }}</template>
      <div class="space-y-4" v-if="payModal">
        <div class="p-3 bg-orange-50 rounded-lg text-sm">
          <div class="text-gray-600">Nhà cung cấp: <span class="font-medium text-gray-900">{{ payModal.supplier }}</span></div>
          <div v-if="payModal.source_type === 'opening_balance'" class="text-amber-700 mt-1 text-xs font-medium">
            Công nợ đầu kỳ
          </div>
          <div class="text-gray-600 mt-1">Còn phải trả: <span class="font-semibold text-orange-600">{{ formatVnd(payModal.amount_due) }}</span></div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền trả <span class="text-red-500">*</span></label>
            <input v-model.number="payForm.amount" type="number" @invalid.prevent
              :class="payAmountError ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-primary-500'"
              class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none text-sm" />
            <p v-if="payAmountError" class="mt-1 text-xs text-red-600">{{ payAmountError }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày trả <span class="text-red-500">*</span></label>
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

        <!-- Chọn quỹ / tài khoản ngân hàng -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            {{ payForm.method === 'cash' ? 'Quỹ tiền mặt' : (payForm.method === 'bank_transfer' ? 'Tài khoản ngân hàng' : 'Nguồn tiền') }}
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
      <template #footer>
        <button @click="payModal = null" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="submitPayment" :disabled="submitting"
          class="px-4 py-2 text-sm bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-60">
          {{ submitting ? 'Đang lưu...' : 'Xác nhận thanh toán' }}
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
  suppliers: Array,
  funds:     Array,
  statuses:  Array,
  filters:   Object,
});

const { formatVnd } = useCurrency();

const filters = ref({
  supplier_id: props.filters.supplier_id ?? '',
  status:      props.filters.status ?? '',
});

const payModal       = ref(null);
const submitting     = ref(false);
const payAmountError = ref('');
const payFundError   = ref('');
const payForm = ref({
  amount: 0, payment_date: new Date().toISOString().slice(0, 10),
  method: 'bank_transfer', fund_id: null, reference: '', notes: '',
});

const totalDue = computed(() => (props.items ?? []).reduce((s, i) => s + i.amount_due, 0));

const filteredFunds = computed(() => {
  const method = payForm.value.method;
  if (method === 'cash')          return (props.funds ?? []).filter(f => f.type === 'cash');
  if (method === 'bank_transfer') return (props.funds ?? []).filter(f => f.type === 'bank');
  return props.funds ?? [];
});

function applyFilters() {
  router.get(route('accounting.ap-payments.index'), {
    supplier_id: filters.value.supplier_id || undefined,
    status:      filters.value.status || undefined,
  }, { preserveState: true });
}

function openPayment(item) {
  payModal.value = item;
  payForm.value = {
    amount:       item.amount_due,
    payment_date: new Date().toISOString().slice(0, 10),
    method:       'bank_transfer',
    fund_id:      null,
    reference:    '',
    notes:        '',
  };
  payAmountError.value = '';
  payFundError.value   = '';
}

function submitPayment() {
  payAmountError.value = '';
  payFundError.value   = '';

  if (!payForm.value.amount || payForm.value.amount <= 0) {
    payAmountError.value = 'Vui lòng nhập số tiền hợp lệ (lớn hơn 0).';
    return;
  }
  if (payForm.value.amount > payModal.value.amount_due) {
    payAmountError.value = `Số tiền không được vượt quá số còn lại (${new Intl.NumberFormat('vi-VN').format(payModal.value.amount_due)} ₫).`;
    return;
  }
  if (!payForm.value.payment_date) {
    payAmountError.value = 'Vui lòng chọn ngày trả.';
    return;
  }
  if (!payForm.value.fund_id) {
    payFundError.value = 'Vui lòng chọn quỹ hoặc tài khoản ngân hàng để chi tiền.';
    return;
  }

  submitting.value = true;

  const item = payModal.value;
  const url = item.source_type === 'opening_balance'
    ? route('accounting.ar-ap-opening-balance.pay', item.id)
    : route('purchasing.purchase-invoices.payments.store', item.id);

  router.post(url, {
    amount:       payForm.value.amount,
    payment_date: payForm.value.payment_date,
    method:       payForm.value.method,
    fund_id:      payForm.value.fund_id,
    reference:    payForm.value.reference || null,
    notes:        payForm.value.notes || null,
  }, {
    onSuccess: () => { payModal.value = null; submitting.value = false; payAmountError.value = ''; payFundError.value = ''; },
    onError:   (errors) => {
      submitting.value = false;
      if (errors.fund_id) payFundError.value = errors.fund_id;
    },
  });
}
</script>
