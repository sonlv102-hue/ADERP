<template>
  <AppLayout :title="`Ứng trước #${advance.id}`">
    <div class="max-w-3xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex items-start justify-between">
        <div>
          <nav class="flex items-center gap-2 text-sm text-gray-500 mb-1">
            <Link :href="route('sales.customer-advances.index')" class="hover:text-primary-600">
              Ứng trước khách hàng
            </Link>
            <span>/</span>
            <span class="text-gray-700">Chi tiết #{{ advance.id }}</span>
          </nav>
          <h1 class="text-2xl font-bold text-gray-900">{{ advance.type_label }}</h1>
        </div>
        <div class="flex gap-2">
          <span class="text-sm px-3 py-1.5 rounded-full font-medium"
            :class="{
              'bg-green-100 text-green-700': advance.status === 'open',
              'bg-yellow-100 text-yellow-700': advance.status === 'partially_applied',
              'bg-gray-100 text-gray-500': advance.status === 'fully_applied',
              'bg-red-100 text-red-600': advance.status === 'cancelled',
            }">
            {{ advance.status_label }}
          </span>
        </div>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        <div class="px-6 py-4 grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-gray-500 mb-0.5">Khách hàng</div>
            <div class="font-medium text-gray-900">{{ advance.customer?.name }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-0.5">Ngày</div>
            <div class="text-gray-900">{{ formatDate(advance.advance_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-0.5">Số tiền ban đầu</div>
            <div class="font-mono text-gray-900">{{ formatVnd(advance.amount) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-0.5">Còn lại</div>
            <div class="font-mono font-bold" :class="advance.remaining_amount > 0 ? 'text-primary-700' : 'text-gray-400'">
              {{ formatVnd(advance.remaining_amount) }}
            </div>
          </div>
          <div v-if="advance.reference_no">
            <div class="text-xs text-gray-500 mb-0.5">Mã tham chiếu</div>
            <div class="text-gray-900">{{ advance.reference_no }}</div>
          </div>
          <div v-if="advance.notes">
            <div class="text-xs text-gray-500 mb-0.5">Ghi chú</div>
            <div class="text-gray-700 text-sm">{{ advance.notes }}</div>
          </div>
        </div>
        <div v-if="advance.status === 'open' && can('accounting.manage')" class="px-6 py-4">
          <button @click="showCancelModal = true"
            class="text-red-600 hover:text-red-800 text-sm font-medium">
            Hủy khoản ứng trước
          </button>
        </div>
      </div>

      <!-- Allocations -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-6 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">Lịch sử đối trừ</h2>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">Hóa đơn</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-gray-600">Số tiền đối trừ</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="a in advance.allocations" :key="a.id">
              <td class="px-4 py-3 text-gray-600">{{ formatDate(a.allocation_date) }}</td>
              <td class="px-4 py-3">
                <span v-if="a.invoice">{{ a.invoice.code }}</span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="px-4 py-3 text-right font-mono">{{ formatVnd(a.allocated_amount) }}</td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-1 rounded-full"
                  :class="a.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                  {{ a.status === 'active' ? 'Hoạt động' : 'Đã thu hồi' }}
                </span>
              </td>
            </tr>
            <tr v-if="advance.allocations.length === 0">
              <td colspan="4" class="px-4 py-6 text-center text-gray-400">Chưa có đối trừ</td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>

    <!-- Cancel Modal -->
    <div v-if="showCancelModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-96">
        <h3 class="font-semibold text-gray-900 mb-3">Hủy khoản ứng trước</h3>
        <textarea v-model="cancelReason" rows="3" placeholder="Lý do hủy..."
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 mb-4"></textarea>
        <div class="flex gap-3">
          <button @click="doCancel" :disabled="!cancelReason.trim()"
            class="bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận hủy
          </button>
          <button @click="showCancelModal = false"
            class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Đóng
          </button>
        </div>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import { usePermission } from '@/composables/usePermission'

const { hasPermission: can } = usePermission()

const props = defineProps({ advance: Object })

const showCancelModal = ref(false)
const cancelReason = ref('')

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫'
}

function formatDate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return `${String(dt.getDate()).padStart(2,'0')}/${String(dt.getMonth()+1).padStart(2,'0')}/${dt.getFullYear()}`
}

function doCancel() {
  router.post(route('sales.customer-advances.cancel', props.advance.id), {
    reason: cancelReason.value,
  }, {
    onSuccess: () => { showCancelModal.value = false; cancelReason.value = '' },
  })
}
</script>
