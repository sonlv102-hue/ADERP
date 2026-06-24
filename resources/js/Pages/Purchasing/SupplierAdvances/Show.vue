<template>
  <AppLayout :title="`Ứng trước — ${advance.supplier?.name}`">
    <div class="space-y-5">

      <!-- Header -->
      <div class="flex items-start justify-between flex-wrap gap-y-3">
        <div class="space-y-1">
          <nav class="flex items-center gap-2 text-sm text-gray-500">
            <Link :href="route('purchasing.supplier-advances.index')" class="hover:text-primary-600">
              Tiền trả trước NCC
            </Link>
            <span>/</span>
            <span class="text-gray-700 font-medium">{{ advance.supplier?.name }}</span>
          </nav>
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-2xl font-bold text-gray-900">{{ advance.supplier?.name }}</h1>
            <span :class="typeBadgeClass" class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium">
              {{ advance.type_label }}
            </span>
            <StatusBadge :color="statusColor(advance.status)">{{ advance.status_label }}</StatusBadge>
          </div>
        </div>
        <div class="flex gap-2 flex-wrap">
          <Link v-if="advance.status !== 'cancelled'"
            :href="route('purchasing.supplier-advances.edit', advance.id)"
            class="erp-btn-secondary">Sửa</Link>
          <button v-if="advance.can_refund"
            @click="showRefundModal = true"
            class="erp-btn-primary">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16l-4-4m0 0l4-4m-4 4h18"/></svg>
            Thu hồi
          </button>
          <button v-if="advance.can_cancel"
            @click="showCancelModal = true"
            class="erp-btn-danger">Hủy</button>
        </div>
      </div>

      <!-- Stats row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Số tiền ban đầu</p>
          <p class="text-xl font-bold text-primary-700">{{ fmt(advance.amount) }} đ</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đã đối trừ</p>
          <p class="text-xl font-bold text-orange-600">{{ fmt(advance.allocated_amount) }} đ</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đã thu hồi</p>
          <p class="text-xl font-bold text-blue-600">{{ fmt(advance.refunded_amount) }} đ</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Còn lại</p>
          <p class="text-2xl font-bold" :class="advance.remaining_amount > 0 ? 'text-green-600' : 'text-gray-400'">
            {{ fmt(advance.remaining_amount) }} đ
          </p>
        </div>
      </div>

      <!-- Progress + Meta -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <!-- stacked progress bar -->
        <div class="flex items-center justify-between text-xs mb-2">
          <div class="flex gap-4">
            <span class="text-orange-600">Đối trừ: <strong>{{ fmt(advance.allocated_amount) }} đ</strong></span>
            <span class="text-blue-600">Thu hồi: <strong>{{ fmt(advance.refunded_amount) }} đ</strong></span>
          </div>
          <span class="text-gray-500">Còn: <strong class="text-green-600">{{ fmt(advance.remaining_amount) }} đ</strong></span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden flex">
          <div class="h-full bg-orange-400 transition-all" :style="{ width: allocPct + '%' }"></div>
          <div class="h-full bg-blue-400 transition-all" :style="{ width: refundPct + '%' }"></div>
        </div>

        <div v-if="advance.reference_no || advance.original_payment_date || advance.bank_transaction_ref || advance.notes"
          class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div v-if="advance.reference_no">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Mã tham chiếu</p>
            <p class="text-sm font-medium font-mono text-gray-900">{{ advance.reference_no }}</p>
          </div>
          <div v-if="advance.original_payment_date">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày CK gốc</p>
            <p class="text-sm font-medium text-gray-900">{{ formatDate(advance.original_payment_date) }}</p>
          </div>
          <div v-if="advance.bank_transaction_ref">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Mã GD ngân hàng</p>
            <p class="text-sm font-mono text-gray-900">{{ advance.bank_transaction_ref }}</p>
          </div>
          <div v-if="advance.notes" class="sm:col-span-2">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ghi chú</p>
            <p class="text-sm text-gray-700">{{ advance.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Allocations Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-900">Lịch sử đối trừ</h2>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hóa đơn</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền đối trừ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="advance.allocations.length === 0">
              <td colspan="6" class="py-10 text-center text-gray-400">Chưa có đối trừ nào.</td>
            </tr>
            <tr v-for="a in advance.allocations" :key="a.id"
              :class="a.status === 'reversed' ? 'opacity-50 bg-gray-50' : 'hover:bg-gray-50'">
              <td class="px-5 py-3 text-gray-600">{{ formatDate(a.allocation_date) }}</td>
              <td class="px-5 py-3">
                <Link v-if="a.invoice"
                  :href="route('purchasing.purchase-invoices.show', a.purchase_invoice_id)"
                  class="text-primary-600 hover:text-primary-800 font-medium font-mono text-xs">
                  {{ a.invoice?.code }}
                </Link>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="px-5 py-3 text-right font-mono font-semibold"
                :class="a.status === 'reversed' ? 'line-through text-gray-400' : 'text-orange-700'">
                {{ fmt(a.allocated_amount) }} đ
              </td>
              <td class="px-5 py-3 text-sm text-gray-500 max-w-xs truncate">{{ a.reason || '—' }}</td>
              <td class="px-5 py-3 text-sm text-gray-700">{{ a.creator?.name }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="a.status === 'active' ? 'green' : 'gray'">
                  {{ a.status === 'active' ? 'Hoạt động' : 'Đã thu hồi' }}
                </StatusBadge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Refunds Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-900">Lịch sử thu hồi</h2>
          <button v-if="advance.can_refund" @click="showRefundModal = true"
            class="erp-btn-primary text-xs px-3 py-1.5">+ Thu hồi</button>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày thu hồi</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hình thức</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nguồn (quỹ/ngân hàng)</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="refunds.length === 0">
              <td colspan="7" class="py-10 text-center text-gray-400">Chưa có thu hồi nào.</td>
            </tr>
            <tr v-for="r in refunds" :key="r.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 text-gray-600">{{ r.refund_date }}</td>
              <td class="px-5 py-3 text-right font-mono font-semibold text-blue-700">{{ fmt(r.amount) }} đ</td>
              <td class="px-5 py-3">
                <span :class="r.refund_method === 'cash' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'"
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium">
                  {{ r.refund_method === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }}
                </span>
              </td>
              <td class="px-5 py-3 text-sm text-gray-700">{{ r.source_name }}</td>
              <td class="px-5 py-3 text-sm text-gray-500">{{ r.description || '—' }}</td>
              <td class="px-5 py-3 text-sm text-gray-700">{{ r.creator }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="r.status === 'confirmed' ? 'green' : 'red'">
                  {{ r.status === 'confirmed' ? 'Đã ghi nhận' : 'Đã hủy' }}
                </StatusBadge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Refund Modal -->
    <Modal :show="showRefundModal" max-width="lg" @close="closeRefundModal">
      <template #title>Thu hồi tiền trả trước NCC</template>
      <div class="space-y-4">
        <p class="text-sm text-gray-500">
          Số còn lại: <strong class="text-green-700">{{ fmt(advance.remaining_amount) }} đ</strong>
        </p>
        <div v-if="refundErrors.general" class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
          {{ refundErrors.general }}
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày thu hồi <span class="text-red-500">*</span></label>
            <input v-model="refundForm.refund_date" type="date" class="erp-input"
              :class="refundErrors.refund_date ? 'erp-input-error' : ''" />
            <p v-if="refundErrors.refund_date" class="text-xs text-red-500 mt-1">{{ refundErrors.refund_date }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
            <input v-model="refundForm.amount" type="number" min="1" :max="advance.remaining_amount"
              class="erp-input" :class="refundErrors.amount ? 'erp-input-error' : ''"
              placeholder="0" />
            <p v-if="refundErrors.amount" class="text-xs text-red-500 mt-1">{{ refundErrors.amount }}</p>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức thu <span class="text-red-500">*</span></label>
          <select v-model="refundForm.refund_method" class="erp-input">
            <option value="cash">Tiền mặt</option>
            <option value="bank">Ngân hàng</option>
          </select>
        </div>
        <div v-if="refundForm.refund_method === 'cash'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ tiền mặt <span class="text-red-500">*</span></label>
          <select v-model="refundForm.fund_id" class="erp-input"
            :class="refundErrors.fund_id ? 'erp-input-error' : ''">
            <option value="">-- Chọn quỹ --</option>
            <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
          </select>
          <p v-if="refundErrors.fund_id" class="text-xs text-red-500 mt-1">{{ refundErrors.fund_id }}</p>
        </div>
        <div v-if="refundForm.refund_method === 'bank'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản ngân hàng <span class="text-red-500">*</span></label>
          <select v-model="refundForm.bank_account_id" class="erp-input"
            :class="refundErrors.bank_account_id ? 'erp-input-error' : ''">
            <option value="">-- Chọn tài khoản --</option>
            <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }} ({{ b.account_number }})</option>
          </select>
          <p v-if="refundErrors.bank_account_id" class="text-xs text-red-500 mt-1">{{ refundErrors.bank_account_id }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải</label>
          <input v-model="refundForm.description" type="text" class="erp-input" placeholder="Tùy chọn..." />
        </div>
      </div>
      <template #footer>
        <button @click="closeRefundModal" class="erp-btn-secondary">Hủy</button>
        <button @click="submitRefund" :disabled="refunding" class="erp-btn-primary">
          {{ refunding ? 'Đang xử lý...' : 'Xác nhận thu hồi' }}
        </button>
      </template>
    </Modal>

    <!-- Cancel Modal -->
    <Modal :show="showCancelModal" max-width="md" @close="showCancelModal = false">
      <template #title>Hủy khoản ứng trước</template>
      <div class="space-y-3">
        <p class="text-sm text-gray-600">Thao tác này sẽ hủy khoản ứng trước và không thể hoàn tác.</p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lý do hủy <span class="text-red-500">*</span></label>
          <textarea v-model="cancelReason" rows="3" class="erp-input" placeholder="Nhập lý do..."></textarea>
        </div>
      </div>
      <template #footer>
        <button @click="showCancelModal = false" class="erp-btn-secondary">Đóng</button>
        <button @click="doCancel" :disabled="!cancelReason.trim() || cancelling" class="erp-btn-danger">
          {{ cancelling ? 'Đang hủy...' : 'Xác nhận hủy' }}
        </button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'
import Modal from '@/Components/Shared/Modal.vue'

const props = defineProps({
  advance:      Object,
  refunds:      Array,
  funds:        Array,
  bankAccounts: Array,
})

// Cancel
const showCancelModal = ref(false)
const cancelReason = ref('')
const cancelling = ref(false)

// Refund
const showRefundModal = ref(false)
const refunding = ref(false)
const refundErrors = reactive({})
const refundForm = reactive({
  refund_date:     new Date().toISOString().split('T')[0],
  amount:          '',
  refund_method:   'cash',
  fund_id:         '',
  bank_account_id: '',
  description:     '',
})

const total = computed(() => props.advance.amount ?? 0)
const allocPct = computed(() => total.value > 0 ? Math.min(100, (props.advance.allocated_amount / total.value) * 100) : 0)
const refundPct = computed(() => total.value > 0 ? Math.min(100 - allocPct.value, (props.advance.refunded_amount / total.value) * 100) : 0)

const typeBadgeClass = computed(() =>
  props.advance.advance_type === 'prepayment'
    ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-200'
    : 'bg-gray-100 text-gray-600'
)

function formatDate(d) {
  if (!d) return '—'
  const parts = String(d).split('-')
  return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : d
}

function fmt(val) {
  return Number(val || 0).toLocaleString('vi-VN')
}

function statusColor(s) {
  const map = { open: 'green', partially_applied: 'yellow', fully_applied: 'gray', cancelled: 'red' }
  return map[s] || 'gray'
}

function closeRefundModal() {
  showRefundModal.value = false
  Object.keys(refundErrors).forEach(k => delete refundErrors[k])
}

function submitRefund() {
  Object.keys(refundErrors).forEach(k => delete refundErrors[k])
  refunding.value = true
  router.post(
    route('purchasing.supplier-advances.refund', props.advance.id),
    {
      refund_date:     refundForm.refund_date,
      amount:          refundForm.amount,
      refund_method:   refundForm.refund_method,
      fund_id:         refundForm.fund_id || null,
      bank_account_id: refundForm.bank_account_id || null,
      description:     refundForm.description,
    },
    {
      onSuccess: () => { showRefundModal.value = false; refunding.value = false },
      onError: (errors) => {
        refunding.value = false
        Object.assign(refundErrors, errors)
      },
    }
  )
}

function doCancel() {
  if (!cancelReason.value.trim()) return
  cancelling.value = true
  router.post(
    route('purchasing.supplier-advances.cancel', props.advance.id),
    { reason: cancelReason.value },
    {
      onSuccess: () => { showCancelModal.value = false; cancelling.value = false },
      onError: () => { cancelling.value = false },
    }
  )
}
</script>
