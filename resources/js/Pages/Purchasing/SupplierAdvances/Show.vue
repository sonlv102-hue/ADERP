<template>
  <AppLayout :title="`Ứng trước — ${advance.supplier?.name}`">
    <div class="space-y-5">

      <!-- Breadcrumb + Header -->
      <div class="flex items-start justify-between">
        <div class="space-y-1">
          <nav class="flex items-center gap-2 text-sm text-gray-500">
            <Link :href="route('purchasing.supplier-advances.index')" class="hover:text-primary-600">
              Tiền trả trước NCC
            </Link>
            <span>/</span>
            <span class="text-gray-700 font-medium">{{ advance.supplier?.name }}</span>
          </nav>
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-900">{{ advance.supplier?.name }}</h1>
            <span :class="typeBadgeClass" class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium">
              {{ advance.type_label }}
            </span>
            <StatusBadge :color="statusColor(advance.status)">{{ advance.status_label }}</StatusBadge>
          </div>
        </div>
        <div class="flex gap-2" v-if="advance.status !== 'cancelled'">
          <Link :href="route('purchasing.supplier-advances.edit', advance.id)"
            class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button @click="showCancelModal = true"
            class="bg-white border border-red-300 hover:border-red-400 text-red-600 hover:text-red-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </button>
        </div>
      </div>

      <!-- Stats row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Năm tài chính</p>
          <p class="text-2xl font-bold text-gray-900">{{ advance.fiscal_year || '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày đầu kỳ</p>
          <p class="text-lg font-semibold text-gray-900">{{ formatDate(advance.opening_date) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Số tiền ban đầu</p>
          <p class="text-xl font-bold text-primary-700">{{ fmt(advance.amount) }} đ</p>
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
        <div class="flex items-center justify-between text-sm mb-3">
          <span class="text-gray-600">Đã đối trừ: <strong class="text-orange-600">{{ fmt(usedAmount) }} đ</strong></span>
          <span class="text-gray-600">Còn lại: <strong class="text-green-600">{{ fmt(advance.remaining_amount) }} đ</strong></span>
          <span class="text-gray-500 text-xs">{{ usedPct.toFixed(1) }}% đã dùng</span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full bg-orange-400 rounded-full transition-all" :style="{ width: usedPct + '%' }"></div>
        </div>

        <!-- Extra meta -->
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
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-900">Lịch sử đối trừ</h2>
        </div>
        <table class="w-full text-sm">
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
              <td colspan="6" class="py-12 text-center text-gray-400">Chưa có đối trừ nào.</td>
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

    </div>

    <!-- Cancel Modal -->
    <div v-if="showCancelModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-base font-semibold text-gray-900">Hủy khoản ứng trước</h3>
        </div>
        <div class="px-6 py-4 space-y-3">
          <p class="text-sm text-gray-600">Thao tác này sẽ đánh dấu khoản ứng trước là đã hủy và không thể khôi phục.</p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lý do hủy <span class="text-red-500">*</span></label>
            <textarea v-model="cancelReason" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
              placeholder="Nhập lý do..."></textarea>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-200 flex gap-3 justify-end">
          <button @click="showCancelModal = false"
            class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            Đóng
          </button>
          <button @click="doCancel" :disabled="!cancelReason.trim() || cancelling"
            class="bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
            {{ cancelling ? 'Đang hủy...' : 'Xác nhận hủy' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'

const props = defineProps({ advance: Object })

const showCancelModal = ref(false)
const cancelReason = ref('')
const cancelling = ref(false)

const usedAmount = computed(() => (props.advance.amount ?? 0) - (props.advance.remaining_amount ?? 0))
const usedPct = computed(() => {
  const total = props.advance.amount ?? 0
  return total > 0 ? Math.min(100, (usedAmount.value / total) * 100) : 0
})

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
