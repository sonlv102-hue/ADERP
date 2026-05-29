<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thanh toán nhà cung cấp (TK 331)</h1>
          <p class="text-sm text-gray-500 mt-0.5">Danh sách hóa đơn NCC chưa thanh toán — ghi nhận tiền đã trả</p>
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
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HĐ NCC</th>
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
            <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">
                <Link :href="route('purchasing.purchase-invoices.show', inv.id)" class="text-primary-600 hover:underline">
                  {{ inv.code }}
                </Link>
              </td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ inv.supplier }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ inv.invoice_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ inv.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-800">{{ formatVnd(inv.total) }}</td>
              <td class="px-5 py-3 text-right text-green-700">{{ formatVnd(inv.amount_paid) }}</td>
              <td class="px-5 py-3 text-right font-semibold text-orange-600">{{ formatVnd(inv.amount_due) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="inv.status_color">{{ inv.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <button @click="openPayment(inv)"
                  class="px-3 py-1.5 text-xs font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700">
                  Ghi trả tiền
                </button>
              </td>
            </tr>
            <tr v-if="!invoices.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Không có hóa đơn NCC chưa thanh toán</td>
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
          <div class="text-gray-600 mt-1">Còn phải trả: <span class="font-semibold text-orange-600">{{ formatVnd(payModal.amount_due) }}</span></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền trả <span class="text-red-500">*</span></label>
            <input v-model.number="payForm.amount" type="number" min="0.01" :max="payModal.amount_due" step="1000"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày trả <span class="text-red-500">*</span></label>
            <input v-model="payForm.payment_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức thanh toán</label>
          <select v-model="payForm.method"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm">
            <option value="cash">Tiền mặt</option>
            <option value="bank_transfer">Chuyển khoản</option>
            <option value="other">Khác</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số tham chiếu</label>
          <input v-model="payForm.reference" type="text" placeholder="Số séc, số CK..."
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
  invoices:  Array,
  suppliers: Array,
  statuses:  Array,
  filters:   Object,
});

const { formatVnd } = useCurrency();

const filters = ref({
  supplier_id: props.filters.supplier_id ?? '',
  status:      props.filters.status ?? '',
});

const payModal   = ref(null);
const submitting = ref(false);
const payForm = ref({ amount: 0, payment_date: new Date().toISOString().slice(0, 10), method: 'bank_transfer', reference: '', notes: '' });

const totalDue = computed(() => props.invoices.reduce((s, i) => s + i.amount_due, 0));

function applyFilters() {
  router.get(route('accounting.ap-payments.index'), {
    supplier_id: filters.value.supplier_id || undefined,
    status:      filters.value.status || undefined,
  }, { preserveState: true });
}

function openPayment(inv) {
  payModal.value = inv;
  payForm.value = {
    amount:       inv.amount_due,
    payment_date: new Date().toISOString().slice(0, 10),
    method:       'bank_transfer',
    reference:    '',
    notes:        '',
  };
}

function submitPayment() {
  if (!payForm.value.amount || !payForm.value.payment_date) return;
  submitting.value = true;
  router.post(route('purchasing.purchase-invoices.payments.store', payModal.value.id), {
    amount:       payForm.value.amount,
    payment_date: payForm.value.payment_date,
    method:       payForm.value.method,
    reference:    payForm.value.reference || null,
    notes:        payForm.value.notes || null,
  }, {
    onSuccess: () => { payModal.value = null; submitting.value = false; },
    onError:   () => { submitting.value = false; },
  });
}
</script>
