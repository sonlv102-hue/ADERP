<template>
  <AppLayout>
    <div class="max-w-4xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-invoices.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ invoice.code }}</h1>
          <StatusBadge :color="invoice.status_color">{{ invoice.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2">
          <!-- FSM transition buttons -->
          <template v-for="tr in invoice.transitions" :key="tr.value">
            <button @click="doTransition(tr.value)" :disabled="busy"
              :class="tr.value === 'cancelled' ? 'border border-red-300 text-red-600 hover:bg-red-50' : 'bg-primary-600 hover:bg-primary-700 text-white'"
              class="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
              {{ tr.label }}
            </button>
          </template>
          <Link v-if="invoice.status === 'pending'" :href="route('purchasing.purchase-invoices.edit', invoice.id)"
            class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="invoice.status === 'cancelled'" @click="showDeleteModal = true" :disabled="busy"
            class="border border-red-400 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Xóa
          </button>
        </div>
      </div>

      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa hóa đơn đầu vào</h3>
            <p class="text-sm text-gray-600 mb-5">
              Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> hóa đơn
              <strong>{{ invoice.code }}</strong>? Thao tác này không thể hoàn tác.
            </p>
            <div class="flex justify-end gap-2">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="doDelete"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xóa hóa đơn</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm">
          <div>
            <p class="text-gray-500 mb-1">Nhà cung cấp</p>
            <p class="font-medium text-gray-900">{{ invoice.supplier }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">MST nhà cung cấp</p>
            <p class="font-medium text-gray-900">{{ invoice.supplier_tax_code ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Đơn mua hàng</p>
            <Link :href="route('purchasing.purchase-orders.show', invoice.purchase_order_id)"
              class="font-mono text-primary-600 hover:underline font-medium">
              {{ invoice.purchase_order }}
            </Link>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Số HĐ NCC</p>
            <p class="font-medium text-gray-900">{{ invoice.invoice_number ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Ngày hóa đơn</p>
            <p class="font-medium text-gray-900">{{ invoice.invoice_date ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Hạn thanh toán</p>
            <p class="font-medium" :class="invoice.remaining > 0 ? 'text-red-600' : 'text-gray-900'">
              {{ invoice.due_date ?? '—' }}
            </p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Người tạo</p>
            <p class="font-medium text-gray-900">{{ invoice.creator }}</p>
          </div>
          <div v-if="invoice.notes" class="col-span-2">
            <p class="text-gray-500 mb-1">Ghi chú</p>
            <p class="text-gray-800">{{ invoice.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Tổng giá trị -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="grid grid-cols-4 gap-4 text-center">
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-xs text-gray-500 mb-1">Trước thuế</p>
            <p class="font-semibold text-gray-900">{{ formatVnd(invoice.subtotal) }}</p>
          </div>
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-xs text-gray-500 mb-1">Thuế VAT</p>
            <p class="font-semibold text-gray-900">{{ formatVnd(invoice.tax_amount) }}</p>
          </div>
          <div class="p-3 bg-blue-50 rounded-lg">
            <p class="text-xs text-blue-600 mb-1">Tổng cộng</p>
            <p class="font-bold text-blue-700">{{ formatVnd(invoice.total) }}</p>
          </div>
          <div class="p-3 rounded-lg" :class="invoice.remaining > 0 ? 'bg-red-50' : 'bg-green-50'">
            <p class="text-xs mb-1" :class="invoice.remaining > 0 ? 'text-red-600' : 'text-green-600'">Còn lại</p>
            <p class="font-bold" :class="invoice.remaining > 0 ? 'text-red-700' : 'text-green-700'">{{ formatVnd(invoice.remaining) }}</p>
          </div>
        </div>
      </div>

      <!-- Tài liệu đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tài liệu đính kèm (file hóa đơn NCC)</p>
        <div v-if="invoice.file_name" class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span class="text-sm text-gray-800 flex-1 truncate">{{ invoice.file_name }}</span>
          <a :href="invoice.file_url" target="_blank" download
            class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
          <button @click="deleteFile"
            class="text-red-500 hover:text-red-700 text-xs font-medium whitespace-nowrap">Xóa</button>
        </div>
        <div v-else class="space-y-2">
          <label class="block cursor-pointer">
            <input type="file" class="hidden" ref="fileInput" @change="onFileSelected">
            <div class="px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-dashed border-gray-300 rounded-lg hover:bg-gray-100 text-center">
              {{ selectedFile ? selectedFile.name : 'Nhấn để chọn file hóa đơn (PDF, ảnh, Word...)' }}
            </div>
          </label>
          <div v-if="selectedFile" class="flex justify-end">
            <button @click="uploadFile" :disabled="uploading"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
              {{ uploading ? 'Đang tải...' : 'Đính kèm' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Tab: Thanh toán -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-800">Lịch sử thanh toán</h2>
          <button v-if="canPay" @click="showPayForm = !showPayForm"
            class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
            + Ghi nhận TT
          </button>
        </div>

        <!-- Form thêm thanh toán -->
        <div v-if="showPayForm" class="px-5 py-4 border-b border-gray-100 bg-green-50">
          <form @submit.prevent="submitPayment" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Số tiền <span class="text-red-500">*</span></label>
              <input v-model.number="payForm.amount" type="number" min="1" step="1000" :max="invoice.remaining"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Ngày TT <span class="text-red-500">*</span></label>
              <input v-model="payForm.payment_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Hình thức</label>
              <select v-model="payForm.method"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="bank_transfer">Chuyển khoản</option>
                <option value="cash">Tiền mặt</option>
                <option value="other">Khác</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Số tham chiếu</label>
              <input v-model="payForm.reference" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div class="col-span-2 sm:col-span-3">
              <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú</label>
              <input v-model="payForm.notes" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div class="flex items-end gap-2">
              <button type="submit" :disabled="payForm.processing"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50">
                Lưu
              </button>
              <button type="button" @click="showPayForm = false"
                class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded-lg text-sm">
                Hủy
              </button>
            </div>
          </form>
        </div>

        <!-- Danh sách thanh toán -->
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hình thức</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tham chiếu</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người ghi</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in invoice.payments" :key="p.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 text-gray-700">{{ p.payment_date }}</td>
              <td class="px-5 py-3 text-gray-700">{{ p.method_label }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.reference ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-green-700">{{ formatVnd(p.amount) }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.creator }}</td>
              <td class="px-5 py-3 text-right">
                <button v-if="canPay" @click="deletePayment(p.id)"
                  class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
              </td>
            </tr>
            <tr v-if="!invoice.payments?.length">
              <td colspan="6" class="px-5 py-6 text-center text-gray-400 text-sm">Chưa có thanh toán nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ invoice: Object });

const { hasPermission } = usePermission();
const { formatVnd } = useCurrency();
const busy = ref(false);
const showPayForm    = ref(false);
const showDeleteModal = ref(false);

// File attachment
const fileInput = ref(null);
const selectedFile = ref(null);
const uploading = ref(false);

const onFileSelected = (e) => {
  selectedFile.value = e.target.files[0] ?? null;
};

const uploadFile = () => {
  if (!selectedFile.value) return;
  const formData = new FormData();
  formData.append('file', selectedFile.value);
  uploading.value = true;
  router.post(route('purchasing.purchase-invoices.attachment.upload', props.invoice.id), formData, {
    preserveScroll: true,
    onSuccess: () => {
      selectedFile.value = null;
      if (fileInput.value) fileInput.value.value = '';
    },
    onFinish: () => { uploading.value = false; },
  });
};

const deleteFile = () => {
  if (confirm('Xóa file đính kèm?')) {
    router.delete(route('purchasing.purchase-invoices.attachment.delete', props.invoice.id), {
      preserveScroll: true,
    });
  }
};

const canPay = computed(() =>
  hasPermission('purchasing.create') &&
  !['cancelled', 'paid'].includes(props.invoice.status)
);

const payForm = useForm({
  amount:       props.invoice.remaining ?? 0,
  payment_date: new Date().toISOString().split('T')[0],
  method:       'bank_transfer',
  reference:    '',
  notes:        '',
});

function doDelete() {
  showDeleteModal.value = false;
  router.delete(route('purchasing.purchase-invoices.destroy', props.invoice.id));
}

function doTransition(status) {
  if (busy.value) return;
  busy.value = true;
  router.post(route('purchasing.purchase-invoices.transition', props.invoice.id), { status }, {
    onFinish: () => { busy.value = false; },
  });
}

function submitPayment() {
  payForm.post(route('purchasing.purchase-invoices.payments.store', props.invoice.id), {
    onSuccess: () => {
      showPayForm.value = false;
      payForm.reset();
    },
  });
}

function deletePayment(paymentId) {
  if (!confirm('Xóa thanh toán này?')) return;
  router.delete(route('purchasing.purchase-invoices.payments.destroy', [props.invoice.id, paymentId]));
}

</script>
