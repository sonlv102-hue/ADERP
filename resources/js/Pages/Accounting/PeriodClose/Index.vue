<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Kết chuyển cuối kỳ</h1>
      </div>

      <!-- Chọn kỳ + xem trước -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <h2 class="text-base font-semibold text-gray-800">Xem trước &amp; tạo bút toán kết chuyển</h2>

        <div class="flex items-end gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Kỳ kế toán</label>
            <select v-model="selectedPeriod"
              class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500">
              <option value="">— Chọn kỳ —</option>
              <option v-for="p in periods" :key="p.fiscal_period" :value="p.fiscal_period"
                :disabled="p.status === 'locked'">
                {{ p.label }} ({{ p.status_label }})
              </option>
            </select>
          </div>
          <button @click="loadPreview" :disabled="!selectedPeriod || loadingPreview"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
            {{ loadingPreview ? 'Đang tính...' : 'Xem trước' }}
          </button>
        </div>

        <!-- Lỗi API -->
        <div v-if="previewError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          {{ previewError }}
        </div>

        <!-- Kết quả preview -->
        <div v-if="preview" class="space-y-5">
          <!-- Cảnh báo -->
          <div v-if="preview.warnings.length" class="space-y-2">
            <h3 class="text-sm font-semibold text-gray-700">Cảnh báo</h3>
            <div v-for="w in preview.warnings" :key="w.code"
              :class="warningClass(w.type)"
              class="flex items-start gap-2 p-3 rounded-lg text-sm border">
              <span class="font-semibold shrink-0 uppercase text-xs mt-0.5">{{ w.type }}</span>
              <span>{{ w.message }}</span>
            </div>
          </div>

          <!-- Bảng kết chuyển -->
          <div v-if="preview.accountLines.length">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Chi tiết kết chuyển</h3>
            <div class="overflow-x-auto">
              <table class="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-20">TK</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600">Tên tài khoản</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-28">Loại</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Số tiền</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-40">Bút toán</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="l in preview.accountLines" :key="l.code"
                    :class="l.type === 'revenue' ? 'bg-green-50/30' : 'bg-red-50/30'">
                    <td class="px-4 py-2 font-mono text-gray-800">{{ l.code }}</td>
                    <td class="px-4 py-2 text-gray-700">{{ l.name }}</td>
                    <td class="px-4 py-2">
                      <span :class="l.type === 'revenue' ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100'"
                        class="px-2 py-0.5 rounded text-xs font-medium">
                        {{ l.type === 'revenue' ? 'Doanh thu' : 'Chi phí' }}
                      </span>
                    </td>
                    <td class="px-4 py-2 text-right font-medium">{{ formatVnd(l.amount) }}</td>
                    <td class="px-4 py-2 text-gray-500 text-xs font-mono">{{ l.entry_text }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Tổng hợp lãi/lỗ -->
          <div class="grid grid-cols-4 gap-4">
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
              <div class="text-xs text-green-600 font-medium mb-1">Tổng doanh thu</div>
              <div class="text-base font-bold text-green-700">{{ formatVnd(preview.totalRevenue) }}</div>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
              <div class="text-xs text-red-600 font-medium mb-1">Tổng chi phí</div>
              <div class="text-base font-bold text-red-700">{{ formatVnd(preview.totalExpense) }}</div>
            </div>
            <div :class="preview.profitOrLoss >= 0 ? 'bg-blue-50 border-blue-200' : 'bg-orange-50 border-orange-200'"
              class="border rounded-lg p-4 text-center">
              <div :class="preview.profitOrLoss >= 0 ? 'text-blue-600' : 'text-orange-600'"
                class="text-xs font-medium mb-1">
                {{ preview.profitOrLoss >= 0 ? 'Lợi nhuận' : 'Lỗ' }}
              </div>
              <div :class="preview.profitOrLoss >= 0 ? 'text-blue-700' : 'text-orange-700'"
                class="text-base font-bold">
                {{ formatVnd(Math.abs(preview.profitOrLoss)) }}
              </div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
              <div class="text-xs text-gray-500 font-medium mb-1">Bút toán KC 911→4212</div>
              <div class="text-xs text-gray-700 font-mono mt-1">
                {{ preview.profitOrLoss >= 0 ? 'Nợ 911 / Có 4212' : 'Nợ 4212 / Có 911' }}
              </div>
            </div>
          </div>

          <!-- Nút tạo kết chuyển -->
          <div v-if="preview.canClose" class="flex items-center gap-4 pt-2">
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú (tuỳ chọn)</label>
              <input v-model="closeNotes" type="text" maxlength="500" placeholder="Ghi chú về lần kết chuyển này..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <button @click="confirmClose = true"
              class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg text-sm font-medium mt-6">
              Tạo bút toán kết chuyển
            </button>
          </div>

          <div v-else-if="preview.hasCritical"
            class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 font-medium">
            Không thể kết chuyển do có cảnh báo nghiêm trọng ở trên.
          </div>
        </div>
      </div>

      <!-- Lịch sử batch -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Lịch sử kết chuyển</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã batch</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Doanh thu</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Chi phí</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Lãi/Lỗ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày tạo</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!batches.length">
              <td colspan="9" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có batch kết chuyển nào.</td>
            </tr>
            <tr v-for="b in batches" :key="b.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-semibold text-gray-800">{{ b.code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ b.period_label }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="b.status_color">{{ b.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(b.total_revenue) }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(b.total_expense) }}</td>
              <td class="px-5 py-3 text-right font-semibold"
                :class="b.profit_or_loss >= 0 ? 'text-green-700' : 'text-red-600'">
                {{ b.profit_or_loss >= 0 ? '+' : '' }}{{ formatVnd(b.profit_or_loss) }}
              </td>
              <td class="px-5 py-3 text-gray-600">{{ b.created_by_name }}</td>
              <td class="px-5 py-3 text-gray-500">{{ b.posted_at ?? '—' }}</td>
              <td class="px-5 py-3">
                <Link :href="route('period-close.show', b.id)"
                  class="text-primary-600 hover:underline text-xs">Chi tiết</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal xác nhận kết chuyển -->
    <div v-if="confirmClose" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-gray-900">Xác nhận kết chuyển kỳ {{ selectedPeriod }}</h3>
        <p class="text-sm text-gray-600">
          Thao tác này sẽ tạo bút toán kết chuyển doanh thu, chi phí và lãi/lỗ vào TK 911 → 4212.
          Sau khi tạo, có thể đảo batch nếu kỳ chưa khóa.
        </p>
        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm">
          <div class="flex justify-between"><span>Doanh thu kết chuyển:</span><strong>{{ formatVnd(preview?.totalRevenue) }}</strong></div>
          <div class="flex justify-between"><span>Chi phí kết chuyển:</span><strong>{{ formatVnd(preview?.totalExpense) }}</strong></div>
          <div class="flex justify-between border-t border-blue-200 mt-2 pt-2">
            <span>{{ (preview?.profitOrLoss ?? 0) >= 0 ? 'Lợi nhuận:' : 'Lỗ:' }}</span>
            <strong :class="(preview?.profitOrLoss ?? 0) >= 0 ? 'text-green-700' : 'text-red-600'">
              {{ formatVnd(Math.abs(preview?.profitOrLoss ?? 0)) }}
            </strong>
          </div>
        </div>
        <div class="flex justify-end gap-3">
          <button @click="confirmClose = false"
            class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
            Hủy
          </button>
          <button @click="submitClose" :disabled="closing"
            class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium">
            {{ closing ? 'Đang tạo...' : 'Xác nhận kết chuyển' }}
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
import StatusBadge from '@/Components/StatusBadge.vue'
import { usePermission } from '@/composables/usePermission'

const { hasPermission: can } = usePermission()

const props = defineProps({
  batches: Array,
  periods: Array,
})

const selectedPeriod = ref('')
const preview        = ref(null)
const previewError   = ref('')
const loadingPreview = ref(false)
const confirmClose   = ref(false)
const closing        = ref(false)
const closeNotes     = ref('')

async function loadPreview() {
  if (!selectedPeriod.value) return
  loadingPreview.value = true
  previewError.value   = ''
  preview.value        = null

  try {
    const res = await fetch(route('period-close.preview'), {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      body:    JSON.stringify({ period: selectedPeriod.value }),
    })
    const data = await res.json()
    if (!res.ok) { previewError.value = data.error || 'Lỗi không xác định'; return }
    preview.value = data
  } catch (e) {
    previewError.value = 'Không thể kết nối máy chủ.'
  } finally {
    loadingPreview.value = false
  }
}

function submitClose() {
  closing.value = true
  router.post(route('period-close.store'), {
    period: selectedPeriod.value,
    notes:  closeNotes.value,
  }, {
    onFinish: () => { closing.value = false; confirmClose.value = false },
  })
}

function warningClass(type) {
  return {
    critical: 'bg-red-50 border-red-300 text-red-800',
    warning:  'bg-yellow-50 border-yellow-300 text-yellow-800',
    info:     'bg-blue-50 border-blue-200 text-blue-800',
  }[type] ?? 'bg-gray-50 border-gray-200 text-gray-700'
}

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫'
}
</script>
