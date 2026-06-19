<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Kết chuyển cuối kỳ</h1>
      </div>

      <!-- ─── Xem trước & tạo kết chuyển ─── -->
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

        <!-- Lỗi API với error_code -->
        <div v-if="previewError" class="p-3 bg-red-50 border border-red-300 rounded-lg text-sm">
          <div class="font-semibold text-red-800">{{ previewErrorCode ? `[${previewErrorCode}]` : 'Lỗi' }}</div>
          <div class="text-red-700 mt-0.5">{{ previewError }}</div>
        </div>

        <!-- Preview kết quả — 5 tabs -->
        <div v-if="preview" class="space-y-4">

          <!-- Tab bar -->
          <div class="flex gap-1 border-b border-gray-200">
            <button v-for="tab in tabs" :key="tab.key"
              @click="activeTab = tab.key"
              :class="activeTab === tab.key
                ? 'border-b-2 border-primary-600 text-primary-700 font-semibold'
                : 'text-gray-500 hover:text-gray-700'"
              class="px-4 py-2 text-sm -mb-px">
              {{ tab.label }}
              <span v-if="tab.badge" class="ml-1 px-1.5 py-0.5 rounded text-xs"
                :class="tab.badgeClass">{{ tab.badge }}</span>
            </button>
          </div>

          <!-- A. Checklist -->
          <div v-show="activeTab === 'checklist'" class="space-y-2">
            <p class="text-xs text-gray-500">Kiểm tra các nghiệp vụ định kỳ trước khi kết chuyển.</p>
            <div v-for="item in preview.checklist" :key="item.key"
              class="flex items-start gap-3 p-3 rounded-lg border"
              :class="checklistRowClass(item.status)">
              <span class="text-lg leading-none mt-0.5">{{ checklistIcon(item.status) }}</span>
              <div>
                <div class="text-sm font-medium">{{ item.label }}</div>
                <div class="text-xs mt-0.5 opacity-80">{{ item.message }}</div>
              </div>
            </div>
          </div>

          <!-- B. Doanh thu -->
          <div v-show="activeTab === 'income'">
            <div v-if="!preview.incomeSections.length" class="text-sm text-gray-400 py-4 text-center">Không có phát sinh doanh thu trong kỳ.</div>
            <div v-else class="overflow-x-auto">
              <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-16">TK</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600">Tên tài khoản</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Phát sinh Nợ</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Phát sinh Có</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Số KC (thuần)</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-40">Bút toán KC</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="s in preview.incomeSections" :key="s.code" class="bg-green-50/30">
                    <td class="px-4 py-2 font-mono text-gray-800">{{ s.code }}</td>
                    <td class="px-4 py-2 text-gray-700">{{ s.name }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ formatVnd(s.total_debit) }}</td>
                    <td class="px-4 py-2 text-right text-green-700">{{ formatVnd(s.total_credit) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">{{ formatVnd(s.closing_amount) }}</td>
                    <td class="px-4 py-2 text-xs font-mono text-gray-500">{{ s.entry_text }}</td>
                  </tr>
                  <tr class="bg-gray-50 font-semibold border-t-2 border-gray-300">
                    <td colspan="4" class="px-4 py-2 text-right text-gray-700">Tổng doanh thu kết chuyển:</td>
                    <td class="px-4 py-2 text-right text-green-700">{{ formatVnd(preview.totalRevenue) }}</td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- C. Chi phí -->
          <div v-show="activeTab === 'expense'">
            <div v-if="!preview.expenseSections.length" class="text-sm text-gray-400 py-4 text-center">Không có phát sinh chi phí trong kỳ.</div>
            <div v-else class="overflow-x-auto">
              <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-16">TK</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600">Tên tài khoản</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Phát sinh Nợ</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Phát sinh Có</th>
                    <th class="text-right px-4 py-2 font-semibold text-gray-600 w-36">Số KC</th>
                    <th class="text-left px-4 py-2 font-semibold text-gray-600 w-40">Bút toán KC</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="s in preview.expenseSections" :key="s.code" class="bg-red-50/20">
                    <td class="px-4 py-2 font-mono text-gray-800">{{ s.code }}</td>
                    <td class="px-4 py-2 text-gray-700">{{ s.name }}</td>
                    <td class="px-4 py-2 text-right text-red-700">{{ formatVnd(s.total_debit) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ formatVnd(s.total_credit) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-red-700">{{ formatVnd(s.closing_amount) }}</td>
                    <td class="px-4 py-2 text-xs font-mono text-gray-500">{{ s.entry_text }}</td>
                  </tr>
                  <tr class="bg-gray-50 font-semibold border-t-2 border-gray-300">
                    <td colspan="4" class="px-4 py-2 text-right text-gray-700">Tổng chi phí kết chuyển:</td>
                    <td class="px-4 py-2 text-right text-red-700">{{ formatVnd(preview.totalExpense) }}</td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- D. Kết quả -->
          <div v-show="activeTab === 'result'" class="space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                <div class="text-xs text-green-600 font-medium mb-1">Tổng doanh thu</div>
                <div class="text-lg font-bold text-green-700">{{ formatVnd(preview.totalRevenue) }}</div>
              </div>
              <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                <div class="text-xs text-red-600 font-medium mb-1">Tổng chi phí</div>
                <div class="text-lg font-bold text-red-700">{{ formatVnd(preview.totalExpense) }}</div>
              </div>
              <div :class="preview.profitOrLoss >= 0 ? 'bg-blue-50 border-blue-200' : 'bg-orange-50 border-orange-200'"
                class="border rounded-lg p-4 text-center">
                <div :class="preview.profitOrLoss >= 0 ? 'text-blue-600' : 'text-orange-600'" class="text-xs font-medium mb-1">
                  {{ preview.profitOrLoss >= 0 ? 'Lợi nhuận trước thuế' : 'Lỗ trước thuế' }}
                </div>
                <div :class="preview.profitOrLoss >= 0 ? 'text-blue-700' : 'text-orange-700'" class="text-lg font-bold">
                  {{ formatVnd(Math.abs(preview.profitOrLoss)) }}
                </div>
              </div>
              <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                <div class="text-xs text-gray-500 font-medium mb-1">Thuế TNDN tạm tính</div>
                <div class="text-sm font-semibold text-gray-700">{{ formatVnd(preview.result?.citExpense ?? 0) }}</div>
                <div class="text-xs text-gray-400 mt-1">Bút toán 4212</div>
                <div class="text-xs font-mono text-gray-600">{{ preview.profitOrLoss >= 0 ? 'Nợ 911 / Có 4212' : 'Nợ 4212 / Có 911' }}</div>
              </div>
            </div>

            <!-- Bút toán kết chuyển lãi/lỗ sang 4212 -->
            <div v-if="preview.profitLines.length" class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="bg-gray-50 px-4 py-2 text-xs font-semibold text-gray-600 border-b">Bút toán kết chuyển kết quả → TK 4212</div>
              <table class="min-w-full text-sm">
                <tbody>
                  <tr v-for="(l, i) in preview.profitLines" :key="i" class="divide-x divide-gray-100">
                    <td class="px-4 py-2 font-mono text-gray-700 w-16">{{ l.account }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ l.description }}</td>
                    <td class="px-4 py-2 text-right text-gray-800 w-32">{{ l.debit > 0 ? formatVnd(l.debit) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-gray-800 w-32">{{ l.credit > 0 ? formatVnd(l.credit) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- E. Cảnh báo -->
          <div v-show="activeTab === 'warnings'" class="space-y-2">
            <div v-if="!preview.warnings.length" class="text-sm text-gray-400 py-4 text-center">Không có cảnh báo.</div>
            <div v-for="w in preview.warnings" :key="w.code"
              :class="warningClass(w.type)"
              class="flex items-start gap-2 p-3 rounded-lg text-sm border">
              <span class="font-semibold shrink-0 uppercase text-xs mt-0.5 w-16">{{ w.type }}</span>
              <span class="font-mono text-xs text-gray-400 w-40 shrink-0">{{ w.code }}</span>
              <span>{{ w.message }}</span>
            </div>
          </div>

          <!-- Nút tạo kết chuyển -->
          <div v-if="preview.canClose" class="flex items-center gap-4 pt-2 border-t border-gray-100">
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
            Không thể kết chuyển do có cảnh báo nghiêm trọng.
            <button @click="activeTab = 'warnings'" class="ml-2 underline text-red-600">Xem cảnh báo</button>
          </div>
        </div>
      </div>

      <!-- ─── Chuyển lợi nhuận cuối năm (4212 → 4211) ─── -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-base font-semibold text-gray-800">Chuyển lợi nhuận sang năm trước (4212 → 4211)</h2>
        <p class="text-xs text-gray-500">Thực hiện đầu năm mới, sau khi đã kết chuyển tháng 12. Chuyển số dư TK 4212 (lãi/lỗ năm nay) sang TK 4211 (lũy kế năm trước).</p>

        <div class="flex items-end gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Năm tài chính</label>
            <input v-model.number="yearEndYear" type="number" min="2020" max="2099" placeholder="2026"
              class="rounded-lg border border-gray-300 px-3 py-2 text-sm w-28" />
          </div>
          <button @click="loadYearEndPreview" :disabled="!yearEndYear || loadingYearEnd"
            class="bg-gray-700 hover:bg-gray-800 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
            {{ loadingYearEnd ? 'Đang tính...' : 'Xem trước' }}
          </button>
        </div>

        <div v-if="yearEndError" class="p-3 bg-red-50 border border-red-300 rounded-lg text-sm text-red-700">{{ yearEndError }}</div>

        <div v-if="yearEndPlan" class="space-y-3">
          <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm">
            <div class="font-medium text-blue-800">{{ yearEndPlan.description }}</div>
            <div class="text-blue-700 mt-1">Số dư TK 4212 tích luỹ đến 31/12/{{ yearEndPlan.year }}: {{ formatVnd(Math.abs(yearEndPlan.balance)) }} {{ yearEndPlan.balance >= 0 ? '(Lãi)' : '(Lỗ)' }}</div>
          </div>

          <div v-if="yearEndPlan.lines.length" class="border rounded-lg overflow-hidden text-sm">
            <table class="min-w-full">
              <thead class="bg-gray-50">
                <tr>
                  <th class="text-left px-4 py-2 text-xs font-semibold text-gray-600">TK</th>
                  <th class="text-left px-4 py-2 text-xs font-semibold text-gray-600">Diễn giải</th>
                  <th class="text-right px-4 py-2 text-xs font-semibold text-gray-600">Nợ</th>
                  <th class="text-right px-4 py-2 text-xs font-semibold text-gray-600">Có</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(l, i) in yearEndPlan.lines" :key="i">
                  <td class="px-4 py-2 font-mono">{{ l.account }}</td>
                  <td class="px-4 py-2 text-gray-600">{{ l.description }}</td>
                  <td class="px-4 py-2 text-right">{{ l.debit > 0 ? formatVnd(l.debit) : '—' }}</td>
                  <td class="px-4 py-2 text-right">{{ l.credit > 0 ? formatVnd(l.credit) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex items-center gap-4">
            <div class="flex-1">
              <input v-model="yearEndNotes" type="text" maxlength="500" placeholder="Ghi chú (tuỳ chọn)"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <button @click="confirmYearEnd = true"
              class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium">
              Thực hiện chuyển lợi nhuận
            </button>
          </div>
        </div>
      </div>

      <!-- ─── Lịch sử batch ─── -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Lịch sử kết chuyển</h2>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã batch</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Doanh thu</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Chi phí</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Lãi/Lỗ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!batches.length">
              <td colspan="10" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có batch kết chuyển nào.</td>
            </tr>
            <tr v-for="b in batches" :key="b.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-semibold text-gray-800">{{ b.code }}</td>
              <td class="px-5 py-3">
                <span :class="b.batch_type === 'year_end' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'"
                  class="px-2 py-0.5 rounded text-xs font-medium">
                  {{ b.batch_type === 'year_end' ? 'Cuối năm' : 'Hàng tháng' }}
                </span>
              </td>
              <td class="px-5 py-3 text-gray-700">{{ b.period_label }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="b.status_color">{{ b.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right text-gray-700">{{ b.total_revenue ? formatVnd(b.total_revenue) : '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ b.total_expense ? formatVnd(b.total_expense) : '—' }}</td>
              <td class="px-5 py-3 text-right font-semibold"
                :class="b.profit_or_loss >= 0 ? 'text-green-700' : 'text-red-600'">
                {{ b.profit_or_loss >= 0 ? '+' : '' }}{{ formatVnd(b.profit_or_loss) }}
              </td>
              <td class="px-5 py-3 text-gray-600">{{ b.created_by_name }}</td>
              <td class="px-5 py-3 text-gray-500">{{ b.posted_at ?? '—' }}</td>
              <td class="px-5 py-3">
                <Link :href="route('accounting.period-close.show', b.id)" class="text-primary-600 hover:underline text-xs">Chi tiết</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ─── Modal xác nhận kết chuyển tháng ─── -->
    <div v-if="confirmClose" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-gray-900">Xác nhận kết chuyển kỳ {{ selectedPeriod }}</h3>
        <p class="text-sm text-gray-600">Tạo bút toán kết chuyển doanh thu, chi phí và lãi/lỗ vào TK 911 → 4212. Có thể đảo batch nếu kỳ chưa khóa.</p>
        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm space-y-1">
          <div class="flex justify-between"><span>Doanh thu:</span><strong>{{ formatVnd(preview?.totalRevenue) }}</strong></div>
          <div class="flex justify-between"><span>Chi phí:</span><strong>{{ formatVnd(preview?.totalExpense) }}</strong></div>
          <div class="flex justify-between border-t border-blue-200 pt-2 mt-1">
            <span>{{ (preview?.profitOrLoss ?? 0) >= 0 ? 'Lợi nhuận:' : 'Lỗ:' }}</span>
            <strong :class="(preview?.profitOrLoss ?? 0) >= 0 ? 'text-green-700' : 'text-red-600'">
              {{ formatVnd(Math.abs(preview?.profitOrLoss ?? 0)) }}
            </strong>
          </div>
        </div>
        <div class="flex justify-end gap-3">
          <button @click="confirmClose = false" class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
          <button @click="submitClose" :disabled="closing"
            class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium">
            {{ closing ? 'Đang tạo...' : 'Xác nhận kết chuyển' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Modal xác nhận chuyển lợi nhuận cuối năm ─── -->
    <div v-if="confirmYearEnd" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-gray-900">Xác nhận chuyển lợi nhuận năm {{ yearEndYear }}</h3>
        <div class="p-3 bg-yellow-50 border border-yellow-300 rounded-lg text-sm text-yellow-800">
          Thao tác này sẽ tạo bút toán chuyển số dư TK 4212 sang TK 4211. Chỉ thực hiện sau khi đã kết chuyển tháng 12 và trước khi mở năm kế toán mới.
        </div>
        <div class="flex justify-end gap-3">
          <button @click="confirmYearEnd = false" class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
          <button @click="submitYearEnd" :disabled="submittingYearEnd"
            class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium">
            {{ submittingYearEnd ? 'Đang xử lý...' : 'Xác nhận' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'

const props = defineProps({
  batches: Array,
  periods: Array,
})

// ── Monthly close state
const selectedPeriod  = ref('')
const preview         = ref(null)
const previewError    = ref('')
const previewErrorCode = ref('')
const loadingPreview  = ref(false)
const confirmClose    = ref(false)
const closing         = ref(false)
const closeNotes      = ref('')
const activeTab       = ref('checklist')

// ── Year-end state
const yearEndYear         = ref(new Date().getFullYear() - 1)
const yearEndPlan         = ref(null)
const yearEndError        = ref('')
const loadingYearEnd      = ref(false)
const confirmYearEnd      = ref(false)
const submittingYearEnd   = ref(false)
const yearEndNotes        = ref('')

// ── Tabs (computed based on preview data)
const tabs = computed(() => {
  if (!preview.value) return []
  const warnCount = preview.value.warnings?.length ?? 0
  const criticalCount = preview.value.warnings?.filter(w => w.type === 'critical').length ?? 0
  const missingCount = preview.value.checklist?.filter(c => ['warning', 'missing', 'needs_review'].includes(c.status)).length ?? 0
  return [
    {
      key: 'checklist', label: 'Checklist',
      badge: missingCount || null,
      badgeClass: missingCount ? 'bg-yellow-100 text-yellow-700' : '',
    },
    { key: 'income',  label: 'Doanh thu', badge: null, badgeClass: '' },
    { key: 'expense', label: 'Chi phí',   badge: null, badgeClass: '' },
    { key: 'result',  label: 'Kết quả',   badge: null, badgeClass: '' },
    {
      key: 'warnings', label: 'Cảnh báo',
      badge: warnCount || null,
      badgeClass: criticalCount ? 'bg-red-100 text-red-700' : (warnCount ? 'bg-yellow-100 text-yellow-700' : ''),
    },
  ]
})

async function loadPreview() {
  if (!selectedPeriod.value) return
  loadingPreview.value = true
  previewError.value   = ''
  previewErrorCode.value = ''
  preview.value        = null
  activeTab.value      = 'checklist'

  try {
    const res = await fetch(route('accounting.period-close.preview'), {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      body:    JSON.stringify({ period: selectedPeriod.value }),
    })
    const data = await res.json()
    if (!res.ok || !data.success) {
      previewError.value     = data.message || 'Lỗi không xác định'
      previewErrorCode.value = data.error_code || ''
      return
    }
    preview.value = data
  } catch (e) {
    previewError.value     = 'Không thể kết nối đến máy chủ. Kiểm tra kết nối mạng.'
    previewErrorCode.value = 'NETWORK_ERROR'
  } finally {
    loadingPreview.value = false
  }
}

function submitClose() {
  closing.value = true
  router.post(route('accounting.period-close.store'), {
    period: selectedPeriod.value,
    notes:  closeNotes.value,
  }, {
    onFinish: () => { closing.value = false; confirmClose.value = false },
  })
}

async function loadYearEndPreview() {
  if (!yearEndYear.value) return
  loadingYearEnd.value = true
  yearEndError.value   = ''
  yearEndPlan.value    = null

  try {
    const res = await fetch(route('accounting.period-close.year-end-preview'), {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      body:    JSON.stringify({ year: yearEndYear.value }),
    })
    const data = await res.json()
    if (!res.ok || !data.success) {
      yearEndError.value = data.message || 'Lỗi không xác định'
      return
    }
    yearEndPlan.value = data
  } catch (e) {
    yearEndError.value = 'Không thể kết nối đến máy chủ.'
  } finally {
    loadingYearEnd.value = false
  }
}

function submitYearEnd() {
  submittingYearEnd.value = true
  router.post(route('accounting.period-close.year-open'), {
    year:  yearEndYear.value,
    notes: yearEndNotes.value,
  }, {
    onFinish: () => { submittingYearEnd.value = false; confirmYearEnd.value = false },
  })
}

// ── Helpers
function warningClass(type) {
  return {
    critical:    'bg-red-50 border-red-300 text-red-800',
    warning:     'bg-yellow-50 border-yellow-300 text-yellow-800',
    info:        'bg-blue-50 border-blue-200 text-blue-800',
  }[type] ?? 'bg-gray-50 border-gray-200 text-gray-700'
}

function checklistIcon(status) {
  return { ok: '✅', warning: '⚠️', missing: '❌', needs_review: '🔍', info: 'ℹ️', skip: '⬜' }[status] ?? '•'
}

function checklistRowClass(status) {
  return {
    ok:           'bg-green-50 border-green-200',
    warning:      'bg-yellow-50 border-yellow-300',
    missing:      'bg-red-50 border-red-300',
    needs_review: 'bg-orange-50 border-orange-300',
    info:         'bg-blue-50 border-blue-200',
    skip:         'bg-gray-50 border-gray-200',
  }[status] ?? 'bg-gray-50 border-gray-200'
}

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫'
}
</script>
