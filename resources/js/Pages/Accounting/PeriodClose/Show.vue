<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('period-close.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ batch.code }}</h1>
          <StatusBadge :color="batch.status_color">{{ batch.status_label }}</StatusBadge>
        </div>

        <!-- Nút đảo batch -->
        <button v-if="batch.status === 'posted'"
          @click="showReverse = true"
          class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Hủy/đảo kết chuyển
        </button>
      </div>

      <!-- Thông tin batch -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
          <div>
            <div class="text-xs text-gray-500 font-medium mb-1">Kỳ kế toán</div>
            <div class="text-sm font-semibold text-gray-800">{{ batch.period_label }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 font-medium mb-1">Người tạo</div>
            <div class="text-sm text-gray-700">{{ batch.created_by_name }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 font-medium mb-1">Ngày kết chuyển</div>
            <div class="text-sm text-gray-700">{{ batch.posted_at ?? '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 font-medium mb-1">Số bút toán</div>
            <div class="text-sm text-gray-700">{{ batch.journal_entry_count }}</div>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mt-6">
          <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="text-xs text-green-600 font-medium mb-1">Doanh thu kết chuyển</div>
            <div class="text-lg font-bold text-green-700">{{ formatVnd(batch.total_revenue) }}</div>
          </div>
          <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="text-xs text-red-600 font-medium mb-1">Chi phí kết chuyển</div>
            <div class="text-lg font-bold text-red-700">{{ formatVnd(batch.total_expense) }}</div>
          </div>
          <div :class="batch.profit_or_loss >= 0 ? 'bg-blue-50 border-blue-200' : 'bg-orange-50 border-orange-200'"
            class="border rounded-lg p-4">
            <div :class="batch.profit_or_loss >= 0 ? 'text-blue-600' : 'text-orange-600'"
              class="text-xs font-medium mb-1">
              {{ batch.profit_or_loss >= 0 ? 'Lợi nhuận' : 'Lỗ' }}
            </div>
            <div :class="batch.profit_or_loss >= 0 ? 'text-blue-700' : 'text-orange-700'"
              class="text-lg font-bold">
              {{ formatVnd(Math.abs(batch.profit_or_loss)) }}
            </div>
          </div>
        </div>

        <div v-if="batch.notes" class="mt-4 p-3 bg-gray-50 rounded-lg text-sm text-gray-600">
          <span class="font-medium">Ghi chú:</span> {{ batch.notes }}
        </div>

        <!-- Thông tin đảo -->
        <div v-if="batch.status === 'reversed'" class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm">
          <div class="font-medium text-yellow-800">Đã đảo kết chuyển</div>
          <div class="text-yellow-700 mt-1">Ngày đảo: {{ batch.reversed_at }} | Lý do: {{ batch.reverse_reason }}</div>
        </div>
      </div>

      <!-- Danh sách bút toán -->
      <div class="space-y-4">
        <h2 class="text-base font-semibold text-gray-800">Bút toán kết chuyển</h2>

        <div v-for="je in batch.journal_entries" :key="je.id"
          class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="font-mono font-semibold text-gray-800 text-sm">{{ je.code }}</span>
              <span class="text-gray-600 text-sm">{{ je.description }}</span>
              <StatusBadge :color="je.status_color" class="text-xs">{{ je.status_label }}</StatusBadge>
            </div>
            <div class="text-sm text-gray-500">{{ je.entry_date }} · {{ formatVnd(je.total_debit) }}</div>
          </div>
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50/50">
              <tr>
                <th class="text-left px-5 py-2 font-medium text-gray-500 text-xs w-24">TK</th>
                <th class="text-left px-5 py-2 font-medium text-gray-500 text-xs">Diễn giải</th>
                <th class="text-right px-5 py-2 font-medium text-gray-500 text-xs w-32">Nợ</th>
                <th class="text-right px-5 py-2 font-medium text-gray-500 text-xs w-32">Có</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="(l, i) in je.lines" :key="i" class="hover:bg-gray-50/50">
                <td class="px-5 py-2 font-mono text-gray-800">{{ l.account_code }}</td>
                <td class="px-5 py-2 text-gray-600">{{ l.description }}</td>
                <td class="px-5 py-2 text-right">
                  <span v-if="l.debit" class="text-gray-800">{{ formatVnd(l.debit) }}</span>
                  <span v-else class="text-gray-300">—</span>
                </td>
                <td class="px-5 py-2 text-right">
                  <span v-if="l.credit" class="text-gray-800">{{ formatVnd(l.credit) }}</span>
                  <span v-else class="text-gray-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal đảo batch -->
    <div v-if="showReverse" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-gray-900">Đảo kết chuyển {{ batch.code }}</h3>
        <p class="text-sm text-gray-600">
          Toàn bộ bút toán kết chuyển sẽ bị đảo ngược (reversed). Sau khi đảo có thể chạy lại kết chuyển cho kỳ này.
        </p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lý do đảo <span class="text-red-500">*</span></label>
          <textarea v-model="reverseReason" rows="3" maxlength="500"
            placeholder="Nhập lý do đảo bút toán kết chuyển..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div class="flex justify-end gap-3">
          <button @click="showReverse = false"
            class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
            Hủy
          </button>
          <button @click="submitReverse" :disabled="!reverseReason.trim() || reversing"
            class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-medium">
            {{ reversing ? 'Đang đảo...' : 'Xác nhận đảo' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'

const props = defineProps({ batch: Object })

const showReverse  = ref(false)
const reverseReason = ref('')
const reversing    = ref(false)

function submitReverse() {
  if (!reverseReason.value.trim()) return
  reversing.value = true
  router.post(route('period-close.reverse', props.batch.id), {
    reason: reverseReason.value,
  }, {
    onFinish: () => { reversing.value = false; showReverse.value = false },
  })
}

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫'
}
</script>
