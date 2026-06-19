<template>
  <AppLayout>
    <div class="max-w-2xl space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.invoices.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ invoice ? 'Sửa hóa đơn' : 'Tạo hóa đơn' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Mã hóa đơn -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã hóa đơn <span class="text-red-500">*</span></label>
            <input v-model="form.code" :disabled="!!invoice" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50" />
            <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
          </div>

          <!-- Khách hàng -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
            <select v-model="form.customer_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn khách hàng --</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p v-if="form.errors.customer_id" class="text-red-500 text-xs mt-1">{{ form.errors.customer_id }}</p>
          </div>

          <!-- Ngày phát hành -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày phát hành <span class="text-red-500">*</span></label>
            <input v-model="form.issue_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.issue_date" class="text-red-500 text-xs mt-1">{{ form.errors.issue_date }}</p>
          </div>

          <!-- Hạn thanh toán -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hạn thanh toán</label>
            <input v-model="form.due_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.due_date" class="text-red-500 text-xs mt-1">{{ form.errors.due_date }}</p>
          </div>

          <!-- Liên kết đơn hàng -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên kết</label>
            <select v-model="form.order_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option :value="null">-- Không chọn --</option>
              <option v-for="o in orders" :key="o.id" :value="o.id">
                {{ o.code }} — {{ formatVnd(o.total) }}
              </option>
            </select>
            <p v-if="form.order_id && autoFilledFrom === 'order'" class="text-xs text-blue-500 mt-1">
              ✓ Đã tự chọn hợp đồng và điền số tiền tương ứng
            </p>
          </div>

          <!-- Liên kết hợp đồng -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hợp đồng liên kết</label>
            <select v-model="form.contract_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option :value="null">-- Không chọn --</option>
              <option v-for="c in contracts" :key="c.id" :value="c.id">
                {{ c.code }} — {{ c.title }} ({{ formatVnd(c.value) }})
              </option>
            </select>
            <p v-if="form.contract_id && autoFilledFrom === 'contract'" class="text-xs text-blue-500 mt-1">
              ✓ Đã tự điền số tiền từ hợp đồng
            </p>
          </div>
        </div>

        <!-- Tài khoản doanh thu — chỉ cho standalone invoice không gắn đơn hàng -->
        <div v-if="!form.order_id" class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Tài khoản doanh thu
            <span class="text-orange-500 text-xs ml-1">(cần điền khi không có đơn hàng liên kết)</span>
          </label>
          <select v-model="form.revenue_account_code"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option :value="null">-- Chưa chọn (sẽ log cảnh báo khi hạch toán) --</option>
            <option v-for="a in revenueAccounts" :key="a.code" :value="a.code">{{ a.label }}</option>
          </select>
          <p class="text-xs text-gray-400 mt-1">
            Nếu để trống, hệ thống fallback về 5111 và ghi cảnh báo vào log. Cần kế toán xác nhận tài khoản phù hợp.
          </p>
          <p v-if="form.errors.revenue_account_code" class="text-red-500 text-xs mt-1">{{ form.errors.revenue_account_code }}</p>
        </div>

        <!-- Số tiền -->
        <div class="grid grid-cols-3 gap-4 pt-2 border-t border-gray-100">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng trước thuế <span class="text-red-500">*</span></label>
            <input v-model.number="form.subtotal" type="number" min="0" step="any" @input="updateTotal"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.subtotal" class="text-red-500 text-xs mt-1">{{ form.errors.subtotal }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thuế (VAT)</label>
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

        <!-- Ghi chú -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('accounting.invoices.index')"
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
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  invoice:         Object,
  nextCode:        String,
  customers:       Array,
  orders:          Array,
  contracts:       Array,
  methods:         Array,
  revenueAccounts: Array,
});

const today = new Date().toISOString().split('T')[0];
const autoFilledFrom = ref(null);
const skipContractWatch = ref(false);

const form = useForm({
  code:                 props.invoice?.code                 ?? props.nextCode,
  customer_id:          props.invoice?.customer_id          ?? '',
  order_id:             props.invoice?.order_id             ?? null,
  contract_id:          props.invoice?.contract_id          ?? null,
  issue_date:           props.invoice?.issue_date           ?? today,
  due_date:             props.invoice?.due_date             ?? '',
  subtotal:             props.invoice?.subtotal             ?? 0,
  tax_amount:           props.invoice?.tax_amount           ?? 0,
  total:                props.invoice?.total                ?? 0,
  notes:                props.invoice?.notes                ?? '',
  revenue_account_code: props.invoice?.revenue_account_code ?? null,
});

watch(() => form.order_id, (id) => {
  if (!id) { autoFilledFrom.value = null; return; }
  // Khi có order_id, revenue_account_code không cần thiết (lấy từ order_items)
  form.revenue_account_code = null;
  const contract = props.contracts.find(c => c.order_id === id);
  if (contract) {
    skipContractWatch.value = true;
    form.contract_id = contract.id;
    form.subtotal    = parseFloat(contract.value) || 0;
    form.tax_amount  = 0;
    form.total       = parseFloat(contract.value) || 0;
    autoFilledFrom.value = 'order';
  }
});

watch(() => form.contract_id, (id) => {
  if (skipContractWatch.value) { skipContractWatch.value = false; return; }
  if (!id) { autoFilledFrom.value = null; return; }
  const contract = props.contracts.find(c => c.id === id);
  if (contract) {
    form.subtotal   = parseFloat(contract.value) || 0;
    form.tax_amount = 0;
    form.total      = parseFloat(contract.value) || 0;
    autoFilledFrom.value = 'contract';
  }
});

function updateTotal() {
  form.total = (form.subtotal || 0) + (form.tax_amount || 0);
}

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫';
}

function submit() {
  if (props.invoice) {
    form.put(route('accounting.invoices.update', props.invoice.id));
  } else {
    form.post(route('accounting.invoices.store'));
  }
}
</script>
