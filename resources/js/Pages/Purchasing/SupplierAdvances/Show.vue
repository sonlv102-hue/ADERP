<template>
  <AppLayout :title="`Ứng trước — ${advance.supplier?.name}`">
    <div class="max-w-5xl mx-auto px-4 py-6 space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.supplier-advances.index')" class="text-gray-500 hover:text-gray-700">
            ← Danh sách ứng trước
          </Link>
          <span class="text-gray-400">/</span>
          <h1 class="text-xl font-bold text-gray-900">{{ advance.supplier?.name }}</h1>
          <span :class="statusBadge(advance.status)" class="badge">{{ statusLabel(advance.status) }}</span>
        </div>
        <div class="flex gap-2" v-if="advance.status !== 'cancelled'">
          <Link
            :href="route('purchasing.supplier-advances.edit', advance.id)"
            class="btn-secondary text-sm"
          >
            Sửa
          </Link>
          <button @click="showCancelModal = true" class="btn-danger text-sm">
            Hủy ứng trước
          </button>
        </div>
      </div>

      <!-- Info Card -->
      <div class="bg-white rounded-lg shadow p-6 grid grid-cols-2 md:grid-cols-4 gap-6">
        <div>
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Năm tài chính</p>
          <p class="text-lg font-semibold">{{ advance.fiscal_year }}</p>
        </div>
        <div>
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày đầu kỳ</p>
          <p class="text-lg font-semibold">{{ formatDate(advance.opening_date) }}</p>
        </div>
        <div>
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Số ứng trước ban đầu</p>
          <p class="text-lg font-semibold text-blue-700">{{ fmt(advance.amount) }} đ</p>
        </div>
        <div>
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Còn lại</p>
          <p class="text-2xl font-bold" :class="advance.remaining_amount > 0 ? 'text-green-700' : 'text-gray-400'">
            {{ fmt(advance.remaining_amount) }} đ
          </p>
        </div>
        <div v-if="advance.reference_no">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Tham chiếu</p>
          <p class="text-sm font-medium">{{ advance.reference_no }}</p>
        </div>
        <div v-if="advance.original_payment_date">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày chuyển khoản gốc</p>
          <p class="text-sm font-medium">{{ formatDate(advance.original_payment_date) }}</p>
        </div>
        <div v-if="advance.bank_transaction_ref">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Mã GD ngân hàng</p>
          <p class="text-sm font-medium">{{ advance.bank_transaction_ref }}</p>
        </div>
        <div v-if="advance.notes">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ghi chú</p>
          <p class="text-sm">{{ advance.notes }}</p>
        </div>
      </div>

      <!-- Progress bar -->
      <div class="bg-white rounded-lg shadow p-4">
        <div class="flex justify-between text-sm text-gray-600 mb-2">
          <span>Đã đối trừ: {{ fmt(usedAmount) }} đ</span>
          <span>Còn lại: {{ fmt(advance.remaining_amount) }} đ</span>
        </div>
        <div class="h-3 bg-gray-200 rounded-full overflow-hidden">
          <div
            class="h-full bg-orange-400 transition-all"
            :style="{ width: usedPct + '%' }"
          ></div>
        </div>
        <p class="text-xs text-gray-500 mt-1 text-right">
          {{ usedPct.toFixed(1) }}% đã dùng
        </p>
      </div>

      <!-- Allocations Table -->
      <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-900">Lịch sử đối trừ</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="th">Ngày</th>
              <th class="th">Hóa đơn</th>
              <th class="th text-right">Số tiền đối trừ</th>
              <th class="th">Diễn giải</th>
              <th class="th">Người tạo</th>
              <th class="th text-center">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="advance.allocations.length === 0">
              <td colspan="6" class="py-8 text-center text-gray-400 italic">Chưa có đối trừ nào.</td>
            </tr>
            <tr
              v-for="a in advance.allocations"
              :key="a.id"
              :class="a.status === 'reversed' ? 'opacity-50' : ''"
            >
              <td class="td text-sm">{{ formatDate(a.allocation_date) }}</td>
              <td class="td">
                <Link
                  v-if="a.invoice"
                  :href="route('purchasing.purchase-invoices.show', a.purchase_invoice_id)"
                  class="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
                >
                  {{ a.invoice?.code }}
                </Link>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="td text-right font-mono font-semibold" :class="a.status === 'reversed' ? 'line-through text-gray-400' : 'text-orange-700'">
                {{ fmt(a.allocated_amount) }} đ
              </td>
              <td class="td text-sm text-gray-500 max-w-xs truncate">{{ a.reason || '—' }}</td>
              <td class="td text-sm">{{ a.creator?.name }}</td>
              <td class="td text-center">
                <span :class="a.status === 'active' ? 'badge-green' : 'badge-gray'" class="badge">
                  {{ a.status === 'active' ? 'Hoạt động' : 'Đã thu hồi' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Cancel Modal -->
    <div v-if="showCancelModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Hủy khoản ứng trước</h3>
        <label class="label mb-1">Lý do hủy <span class="text-red-500">*</span></label>
        <textarea v-model="cancelReason" rows="3" class="input w-full" placeholder="Nhập lý do..."></textarea>
        <div class="flex gap-3 mt-4">
          <button @click="doCancel" class="btn-danger" :disabled="!cancelReason.trim() || cancelling">
            {{ cancelling ? 'Đang hủy...' : 'Xác nhận hủy' }}
          </button>
          <button @click="showCancelModal = false" class="btn-secondary">Đóng</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'

const props = defineProps({
  advance: Object,
})

const showCancelModal = ref(false)
const cancelReason = ref('')
const cancelling = ref(false)

const usedAmount = computed(() => (props.advance.amount ?? 0) - (props.advance.remaining_amount ?? 0))
const usedPct = computed(() => {
  const total = props.advance.amount ?? 0
  if (total <= 0) return 0
  return Math.min(100, (usedAmount.value / total) * 100)
})

function formatDate(d) {
  if (!d) return '—'
  const parts = String(d).split('-')
  if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`
  return d
}

function fmt(val) {
  return Number(val || 0).toLocaleString('vi-VN')
}

function statusLabel(s) {
  const map = {
    open: 'Còn dư', partially_applied: 'Đối trừ một phần',
    fully_applied: 'Đã dùng hết', cancelled: 'Đã hủy',
  }
  return map[s] || s
}

function statusBadge(s) {
  const map = {
    open: 'badge-green', partially_applied: 'badge-yellow',
    fully_applied: 'badge-gray', cancelled: 'badge-red',
  }
  return map[s] || 'badge-gray'
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
