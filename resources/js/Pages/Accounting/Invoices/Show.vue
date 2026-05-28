<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.invoices.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ invoice.code }}</h1>
          <StatusBadge :color="invoice.status_color">{{ invoice.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2">
          <!-- PDF -->
          <a :href="route('accounting.invoices.pdf', invoice.id)" target="_blank"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium">
            PDF
          </a>
          <!-- Edit (draft only) -->
          <Link v-if="invoice.allowed_actions.includes('edit') && can('accounting.manage')"
            :href="route('accounting.invoices.edit', invoice.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <!-- Mark sent -->
          <button v-if="invoice.allowed_actions.includes('mark_sent')"
            @click="action('mark-sent')"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Gửi hóa đơn
          </button>
          <!-- Mark overdue -->
          <button v-if="invoice.allowed_actions.includes('mark_overdue') && can('accounting.manage')"
            @click="action('mark-overdue')"
            class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Đánh dấu quá hạn
          </button>
          <!-- Mark paid -->
          <button v-if="invoice.allowed_actions.includes('mark_paid') && can('accounting.manage')"
            @click="action('mark-paid')"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Đánh dấu đã TT
          </button>
          <!-- Delete -->
          <button v-if="invoice.allowed_actions.includes('delete') && can('accounting.manage')"
            @click="deleteInvoice"
            class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-2 rounded-lg text-sm font-medium">
            Xóa
          </button>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-5">
        <!-- Info -->
        <div class="col-span-2 space-y-5">
          <!-- Invoice details -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Thông tin hóa đơn</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-gray-500">Khách hàng</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.customer.name }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Ngày phát hành</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.issue_date }}</dd>
              </div>
              <div v-if="invoice.due_date">
                <dt class="text-gray-500">Hạn thanh toán</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.due_date }}</dd>
              </div>
              <div v-if="invoice.order">
                <dt class="text-gray-500">Đơn hàng</dt>
                <dd class="mt-0.5">
                  <Link :href="route('sales.orders.show', invoice.order.id)" class="text-primary-600 hover:underline font-mono">
                    {{ invoice.order.code }}
                  </Link>
                </dd>
              </div>
              <div v-if="invoice.contract">
                <dt class="text-gray-500">Hợp đồng</dt>
                <dd class="mt-0.5">
                  <Link :href="route('sales.contracts.show', invoice.contract.id)" class="text-primary-600 hover:underline font-mono">
                    {{ invoice.contract.code }}
                  </Link>
                </dd>
              </div>
              <div>
                <dt class="text-gray-500">Người tạo</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.creator }}</dd>
              </div>
            </dl>
            <div v-if="invoice.notes" class="mt-4 pt-4 border-t border-gray-100">
              <dt class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ghi chú</dt>
              <dd class="text-sm text-gray-700 whitespace-pre-wrap">{{ invoice.notes }}</dd>
            </div>
          </div>

          <!-- E-Invoice section -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex justify-between items-center mb-4">
              <h2 class="text-base font-semibold text-gray-900">Hóa đơn điện tử (HĐDT)</h2>
              <div class="flex gap-2">
                <a v-if="invoice.e_inv_status === 'issued'"
                  :href="route('accounting.invoices.e-invoice-pdf', invoice.id)"
                  target="_blank"
                  class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg font-medium">
                  Tải PDF
                </a>
              </div>
            </div>

            <!-- Already issued -->
            <template v-if="invoice.e_inv_status === 'issued' || invoice.e_inv_status === 'cancelled'">
              <dl class="grid grid-cols-3 gap-3 text-sm mb-4">
                <div>
                  <dt class="text-xs text-gray-500">Mẫu số</dt>
                  <dd class="font-mono font-medium mt-0.5">{{ invoice.e_inv_template }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Ký hiệu</dt>
                  <dd class="font-mono font-medium mt-0.5">{{ invoice.e_inv_series }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Số</dt>
                  <dd class="font-bold text-primary-600 text-lg mt-0.5">{{ String(invoice.e_inv_number).padStart(7,'0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Ngày phát hành</dt>
                  <dd class="font-medium mt-0.5">{{ invoice.e_inv_issued_at }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Trạng thái</dt>
                  <dd class="mt-0.5">
                    <span :class="invoice.e_inv_status === 'issued' ? 'badge-green' : 'badge-red'">
                      {{ invoice.e_inv_status === 'issued' ? 'Đã phát hành' : 'Đã hủy' }}
                    </span>
                  </dd>
                </div>
              </dl>
              <div v-if="invoice.e_inv_cancel_reason" class="bg-red-50 p-3 rounded text-xs text-red-700">
                <strong>Lý do hủy:</strong> {{ invoice.e_inv_cancel_reason }}
              </div>
              <!-- Cancel button -->
              <div v-if="invoice.e_inv_status === 'issued' && can('accounting.manage')" class="mt-3 border-t pt-3">
                <form @submit.prevent="cancelEInvoice" class="flex gap-3 items-end">
                  <div class="flex-1">
                    <label class="form-label text-xs">Lý do hủy <span class="text-red-500">*</span></label>
                    <input v-model="cancelReason" class="form-input text-sm" placeholder="Nhập lý do hủy..." required />
                  </div>
                  <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Hủy HĐDT
                  </button>
                </form>
              </div>
            </template>

            <!-- Issue form -->
            <template v-else>
              <p class="text-sm text-gray-500 mb-4">Chưa phát hành hóa đơn điện tử cho hóa đơn này.</p>
              <form v-if="can('accounting.manage')" @submit.prevent="issueEInvoice" class="grid grid-cols-2 gap-4">
                <div>
                  <label class="form-label text-xs">Mẫu số <span class="text-red-500">*</span></label>
                  <input v-model="eInvForm.e_inv_template" class="form-input text-sm font-mono" placeholder="01GTKT0/001" required />
                </div>
                <div>
                  <label class="form-label text-xs">Ký hiệu <span class="text-red-500">*</span></label>
                  <input v-model="eInvForm.e_inv_series" class="form-input text-sm font-mono" placeholder="AA/24E" required />
                </div>
                <div class="col-span-2">
                  <button type="submit" :disabled="eInvForm.processing" class="btn-primary text-sm">
                    {{ eInvForm.processing ? 'Đang phát hành...' : 'Phát hành HĐDT' }}
                  </button>
                </div>
              </form>
            </template>
          </div>

          <!-- Payments list -->
          <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <h2 class="text-base font-semibold text-gray-900">Lịch sử thanh toán</h2>
              <button v-if="invoice.allowed_actions.includes('add_payment') && can('accounting.manage')"
                @click="showPaymentForm = true"
                class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
                + Thêm thanh toán
              </button>
            </div>

            <!-- Add payment form -->
            <div v-if="showPaymentForm" class="p-5 border-b border-gray-100 bg-gray-50">
              <form @submit.prevent="submitPayment" class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Số tiền <span class="text-red-500">*</span></label>
                  <input v-model.number="payForm.amount" type="number" min="0.01" step="1000"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày thanh toán <span class="text-red-500">*</span></label>
                  <input v-model="payForm.payment_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Phương thức</label>
                  <select v-model="payForm.method"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Mã tham chiếu</label>
                  <input v-model="payForm.reference" type="text"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div class="col-span-2 flex justify-end gap-2">
                  <button type="button" @click="showPaymentForm = false"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">Hủy</button>
                  <button type="submit" :disabled="payForm.processing"
                    class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
                    Ghi nhận
                  </button>
                </div>
              </form>
            </div>

            <table class="w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Phương thức</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Tham chiếu</th>
                  <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
                  <th class="px-5 py-3"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="p in invoice.payments" :key="p.id" class="hover:bg-gray-50">
                  <td class="px-5 py-3 text-gray-600">{{ p.payment_date }}</td>
                  <td class="px-5 py-3">{{ p.method_label }}</td>
                  <td class="px-5 py-3 text-gray-500">{{ p.reference ?? '—' }}</td>
                  <td class="px-5 py-3 text-right font-medium text-green-700">{{ formatVnd(p.amount) }}</td>
                  <td class="px-5 py-3 text-right">
                    <button v-if="can('accounting.manage')"
                      @click="deletePayment(p.id)"
                      class="text-red-400 hover:text-red-600 text-xs">Xóa</button>
                  </td>
                </tr>
                <tr v-if="!invoice.payments?.length">
                  <td colspan="5" class="px-5 py-8 text-center text-gray-400">Chưa có thanh toán nào</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Summary -->
        <div class="space-y-4">
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Tổng kết</h2>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between">
                <dt class="text-gray-500">Tổng trước thuế</dt>
                <dd class="font-medium">{{ formatVnd(invoice.subtotal) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Thuế VAT</dt>
                <dd class="font-medium">{{ formatVnd(invoice.tax_amount) }}</dd>
              </div>
              <div class="flex justify-between border-t border-gray-100 pt-2 mt-2">
                <dt class="font-semibold text-gray-900">Tổng cộng</dt>
                <dd class="font-bold text-lg text-primary-700">{{ formatVnd(invoice.total) }}</dd>
              </div>
              <div class="flex justify-between text-green-700">
                <dt>Đã thanh toán</dt>
                <dd class="font-medium">{{ formatVnd(invoice.amount_paid) }}</dd>
              </div>
              <div class="flex justify-between border-t border-gray-100 pt-2 mt-2"
                :class="invoice.amount_due > 0 ? 'text-red-700' : 'text-green-700'">
                <dt class="font-semibold">Còn lại</dt>
                <dd class="font-bold">{{ formatVnd(invoice.amount_due) }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  invoice: Object,
  methods: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const showPaymentForm = ref(false);
const today = new Date().toISOString().split('T')[0];

const payForm = useForm({
  amount:       0,
  payment_date: today,
  method:       'cash',
  reference:    '',
  notes:        '',
});

function action(act) {
  router.post(route(`accounting.invoices.${act}`, props.invoice.id));
}

function deleteInvoice() {
  if (confirm('Xóa hóa đơn này?')) {
    router.delete(route('accounting.invoices.destroy', props.invoice.id));
  }
}

function submitPayment() {
  payForm.post(route('accounting.invoices.payments.store', props.invoice.id), {
    onSuccess: () => {
      showPaymentForm.value = false;
      payForm.reset();
    },
  });
}

function deletePayment(paymentId) {
  if (confirm('Xóa thanh toán này?')) {
    router.delete(route('accounting.invoices.payments.destroy', [props.invoice.id, paymentId]));
  }
}

// E-invoice
const eInvForm    = useForm({ e_inv_template: '01GTKT0/001', e_inv_series: '' });
const cancelReason = ref('');

function issueEInvoice() {
  eInvForm.post(route('accounting.invoices.issue-einvoice', props.invoice.id));
}

function cancelEInvoice() {
  if (!cancelReason.value) return;
  router.post(route('accounting.invoices.cancel-einvoice', props.invoice.id), { e_inv_cancel_reason: cancelReason.value });
}
</script>
