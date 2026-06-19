<template>
  <AppLayout title="Trung tâm rà soát & xử lý kế toán">
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-5">

      <!-- Header -->
      <div class="flex items-start justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Trung tâm rà soát & xử lý kế toán</h1>
          <p class="text-sm text-gray-500 mt-1">
            Phát hiện và xử lý các vấn đề liên quan NCC / 331UT / 3311 trực tiếp trên giao diện.
          </p>
        </div>
        <button @click="reload" class="btn-secondary text-sm" :disabled="loading">
          {{ loading ? 'Đang tải...' : 'Tải lại' }}
        </button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <SummaryCard v-for="(check, key) in checks" :key="key"
          :letter="key" :label="check.label" :count="check.count"
          :active="activeTab === key" @click="activeTab = key" />
      </div>

      <!-- Tab content -->
      <div class="bg-white rounded-xl shadow overflow-hidden">
        <!-- Tab A: Cancelled advances with unconverted CashVouchers -->
        <CheckPanel v-if="activeTab === 'A'" :items="checks.A.items" empty-text="Không có vấn đề nào.">
          <template #header>
            <p class="text-sm text-gray-600">
              Khoản trả trước NCC đã hủy nhưng <strong>CashVoucher vẫn chưa cancel</strong>.
              Hệ thống sẽ cancel CashVoucher và đảo JE Dr 331UT / Cr fund để hoàn lại số dư quỹ.
            </p>
          </template>
          <template #row="{ item }">
            <td class="px-4 py-3 text-sm">{{ item.supplier }}</td>
            <td class="px-4 py-3 text-sm font-mono">{{ item.advance_ref ?? `Advance #${item.advance_id}` }}</td>
            <td class="px-4 py-3 text-sm font-mono">{{ item.voucher_code }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.amount) }}</td>
            <td class="px-4 py-3 text-sm">{{ item.opening_date }}</td>
            <td class="px-4 py-3 text-sm">
              <span :class="item.has_posted_je ? 'text-red-600 font-medium' : 'text-yellow-600'">
                {{ item.has_posted_je ? 'JE vẫn posted' : 'CashVoucher chưa cancelled' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <button class="btn-danger text-xs" @click="openRepairA(item)">Xử lý</button>
            </td>
          </template>
          <template #thead>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NCC</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mã trả trước</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">CashVoucher</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Số tiền</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ngày</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vấn đề</th>
            <th class="px-4 py-2"></th>
          </template>
        </CheckPanel>

        <!-- Tab B: Active prepayments with missing JE -->
        <CheckPanel v-if="activeTab === 'B'" :items="checks.B.items" empty-text="Không có vấn đề nào.">
          <template #header>
            <p class="text-sm text-gray-600">
              Khoản trả trước NCC <strong>đang hoạt động</strong> nhưng thiếu CashVoucher hoặc JE, hoặc JE sai tài khoản.
              Trường hợp này cần xem xét thủ công và tạo JE điều chỉnh nếu cần.
            </p>
          </template>
          <template #row="{ item }">
            <td class="px-4 py-3 text-sm">{{ item.supplier }}</td>
            <td class="px-4 py-3 text-sm font-mono">{{ item.advance_ref ?? `#${item.advance_id}` }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.amount) }}</td>
            <td class="px-4 py-3 text-sm">{{ item.opening_date }}</td>
            <td class="px-4 py-3 text-sm text-red-600 font-medium">{{ item.problem }}</td>
            <td class="px-4 py-3 text-right">
              <a :href="route('accounting.journal-entries.index')" class="text-blue-600 text-xs hover:underline" target="_blank">
                Xem JE
              </a>
            </td>
          </template>
          <template #thead>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NCC</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mã</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Số tiền</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ngày</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vấn đề</th>
            <th class="px-4 py-2"></th>
          </template>
        </CheckPanel>

        <!-- Tab C: Invoices with wrong status -->
        <CheckPanel v-if="activeTab === 'C'" :items="checks.C.items" empty-text="Không có vấn đề nào.">
          <template #header>
            <p class="text-sm text-gray-600">
              Hóa đơn mua có <strong>trạng thái không khớp</strong> với tổng tiền đã thanh toán + đối trừ trả trước.
              Bấm "Sửa 1 hóa đơn" để cập nhật riêng lẻ, hoặc "Sửa tất cả" để cập nhật hàng loạt.
            </p>
            <div class="mt-2 flex gap-2">
              <button v-if="checks.C.count > 0" @click="repairAllC" class="btn-warning text-sm">
                Sửa tất cả ({{ checks.C.count }} hóa đơn)
              </button>
            </div>
          </template>
          <template #row="{ item }">
            <td class="px-4 py-3 text-sm font-mono">{{ item.invoice_code }}</td>
            <td class="px-4 py-3 text-sm">{{ item.supplier }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.total) }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.paid_amount) }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.advance_allocated) }}</td>
            <td class="px-4 py-3 text-sm">
              <span class="text-red-600">{{ item.current_status }}</span>
              <span class="text-gray-400 mx-1">→</span>
              <span class="text-green-600">{{ item.expected_status }}</span>
            </td>
            <td class="px-4 py-3 text-right">
              <button @click="repairOneC(item)" class="btn-primary text-xs">Sửa</button>
            </td>
          </template>
          <template #thead>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hóa đơn</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NCC</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tổng tiền</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Đã TT tiền</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Đã đối trừ</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Trạng thái</th>
            <th class="px-4 py-2"></th>
          </template>
        </CheckPanel>

        <!-- Tab D: Reversed allocations missing reversal JE -->
        <CheckPanel v-if="activeTab === 'D'" :items="checks.D.items" empty-text="Không có vấn đề nào.">
          <template #header>
            <p class="text-sm text-gray-600">
              Chứng từ đối trừ đã được đánh dấu reversed nhưng <strong>không tìm thấy JE đảo</strong> tương ứng.
              Trường hợp này cần xem xét thủ công để quyết định có cần tạo JE đảo không.
            </p>
          </template>
          <template #row="{ item }">
            <td class="px-4 py-3 text-sm">{{ item.supplier }}</td>
            <td class="px-4 py-3 text-sm font-mono">{{ item.invoice_code ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.allocated_amount) }}</td>
            <td class="px-4 py-3 text-sm">{{ item.reversed_at }}</td>
            <td class="px-4 py-3 text-right">
              <a :href="route('accounting.journal-entries.show', item.je_id)" class="text-blue-600 text-xs hover:underline" target="_blank">
                Xem JE #{{ item.je_id }}
              </a>
            </td>
          </template>
          <template #thead>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NCC</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hóa đơn</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Số đối trừ</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ngày hủy</th>
            <th class="px-4 py-2"></th>
          </template>
        </CheckPanel>

        <!-- Tab E: Payments possibly mislabeled -->
        <CheckPanel v-if="activeTab === 'E'" :items="checks.E.items" empty-text="Không có vấn đề nào.">
          <template #header>
            <p class="text-sm text-gray-600">
              Bút toán chi NCC ghi <strong>Nợ 3311</strong> nhưng <strong>không gắn với hóa đơn nào</strong>.
              Có thể là khoản trả trước bị hạch toán nhầm TK. Chọn từng dòng để tạo bút toán điều chỉnh
              <strong>Nợ 331UT / Có 3311</strong> (không ảnh hưởng quỹ/ngân hàng).
            </p>
          </template>
          <template #row="{ item }">
            <td class="px-4 py-3 text-sm font-mono">{{ item.je_code }}</td>
            <td class="px-4 py-3 text-sm">{{ item.supplier_name }}</td>
            <td class="px-4 py-3 text-sm font-mono">{{ item.voucher_code }}</td>
            <td class="px-4 py-3 text-sm text-right">{{ formatVnd(item.amount) }}</td>
            <td class="px-4 py-3 text-sm">{{ item.entry_date }}</td>
            <td class="px-4 py-3 text-sm text-blue-700 font-mono text-xs">{{ item.proposed_reclass }}</td>
            <td class="px-4 py-3 text-right">
              <button @click="openReclassForm(item)" class="btn-warning text-xs">Reclass</button>
            </td>
          </template>
          <template #thead>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bút toán</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">NCC</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phiếu chi</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Số tiền</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ngày</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Đề xuất</th>
            <th class="px-4 py-2"></th>
          </template>
        </CheckPanel>
      </div>

    </div>

    <!-- Modal Repair A: Cancel CashVoucher for cancelled advance -->
    <Modal v-if="modalA.open" @close="modalA.open = false" size="md">
      <template #title>Xử lý: Cancel CashVoucher cho khoản trả trước đã hủy</template>
      <div class="space-y-4">
        <div class="bg-yellow-50 border border-yellow-200 rounded p-3 text-sm text-yellow-800">
          <p class="font-medium">Thao tác sẽ thực hiện:</p>
          <ul class="mt-1 list-disc pl-4 space-y-1">
            <li>Cancel CashVoucher <strong>{{ modalA.item?.voucher_code }}</strong></li>
            <li>Tạo JE đảo: <strong>Nợ {{ modalA.item?.amount ? formatVnd(modalA.item.amount) : '' }} / Có 331UT</strong> (hoàn lại quỹ)</li>
            <li>Advance <strong>{{ modalA.item?.advance_ref }}</strong> vẫn giữ trạng thái cancelled</li>
          </ul>
        </div>
        <div>
          <label class="label">Lý do xử lý <span class="text-red-500">*</span></label>
          <textarea v-model="modalA.reason" class="input w-full mt-1" rows="3" placeholder="Nhập lý do..." />
        </div>
      </div>
      <template #footer>
        <button @click="modalA.open = false" class="btn-secondary">Hủy</button>
        <button @click="submitRepairA" class="btn-danger" :disabled="!modalA.reason.trim() || modalA.loading">
          {{ modalA.loading ? 'Đang xử lý...' : 'Xác nhận xử lý' }}
        </button>
      </template>
    </Modal>

    <!-- Modal Reclass E: Create adjusting JE 331UT / 3311 -->
    <Modal v-if="modalReclass.open" @close="modalReclass.open = false" size="md">
      <template #title>Phân loại lại: Nợ 331UT / Có 3311</template>
      <div class="space-y-4">
        <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
          <p class="font-medium">Bút toán điều chỉnh sẽ tạo:</p>
          <ul class="mt-1 list-disc pl-4 space-y-1">
            <li>Nợ <strong>331UT</strong> {{ formatVnd(modalReclass.amount) }}</li>
            <li>Có <strong>3311</strong> {{ formatVnd(modalReclass.amount) }}</li>
            <li>Không ảnh hưởng quỹ/ngân hàng</li>
            <li>Tham chiếu bút toán gốc: <strong>{{ modalReclass.item?.je_code }}</strong></li>
          </ul>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Số tiền điều chỉnh</label>
            <input v-model.number="modalReclass.amount" type="number" class="input w-full mt-1" min="1" />
          </div>
          <div>
            <label class="label">Ngày bút toán</label>
            <input v-model="modalReclass.date" type="date" class="input w-full mt-1" />
          </div>
        </div>
        <div>
          <label class="label">Lý do điều chỉnh <span class="text-red-500">*</span></label>
          <textarea v-model="modalReclass.reason" class="input w-full mt-1" rows="3" placeholder="Nhập lý do..." />
        </div>
      </div>
      <template #footer>
        <button @click="modalReclass.open = false" class="btn-secondary">Hủy</button>
        <button @click="submitReclass" class="btn-primary" :disabled="!modalReclass.reason.trim() || modalReclass.loading">
          {{ modalReclass.loading ? 'Đang xử lý...' : 'Tạo bút toán điều chỉnh' }}
        </button>
      </template>
    </Modal>

  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import Modal from '@/Components/Shared/Modal.vue'

const props = defineProps({
  checks: Object,
})

const loading  = ref(false)
const activeTab = ref('A')

// ─── Summary card component ──────────────────────────────────────────────────
const SummaryCard = {
  props: ['letter', 'label', 'count', 'active'],
  emits: ['click'],
  template: `
    <button @click="$emit('click')"
      class="rounded-xl border p-3 text-left transition-all"
      :class="active
        ? 'border-blue-500 bg-blue-50 shadow-sm'
        : 'border-gray-200 bg-white hover:border-blue-300'">
      <div class="flex items-center gap-2">
        <span class="text-sm font-bold text-gray-500">{{ letter }}</span>
        <span :class="count > 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'"
          class="ml-auto text-xs font-bold px-2 py-0.5 rounded-full">
          {{ count }}
        </span>
      </div>
      <p class="text-xs text-gray-600 mt-1 leading-tight">{{ label }}</p>
    </button>
  `
}

// ─── CheckPanel component ─────────────────────────────────────────────────────
const CheckPanel = {
  props: ['items', 'emptyText'],
  slots: ['header', 'thead', 'row'],
  template: `
    <div class="p-4 space-y-3">
      <div v-if="$slots.header"><slot name="header" /></div>
      <div v-if="!items || items.length === 0" class="text-center py-10 text-gray-400">
        <p class="text-lg">✓</p>
        <p>{{ emptyText }}</p>
      </div>
      <div v-else class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50"><tr><slot name="thead" /></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in items" :key="item.advance_id ?? item.invoice_id ?? item.je_id ?? item.allocation_id"
              class="hover:bg-gray-50">
              <slot name="row" :item="item" />
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  `
}

function formatVnd(v) {
  return new Intl.NumberFormat('vi-VN').format(v || 0) + ' ₫'
}

function reload() {
  loading.value = true
  router.reload({ onFinish: () => { loading.value = false } })
}

// ─── Modal A (Repair cancelled advance) ─────────────────────────────────────
const modalA = ref({ open: false, item: null, reason: '', loading: false })

function openRepairA(item) {
  modalA.value = { open: true, item, reason: '', loading: false }
}

function submitRepairA() {
  if (!modalA.value.reason.trim()) return
  modalA.value.loading = true
  router.post(route('accounting.repair-center.repair-cancelled-advance'), {
    advance_id: modalA.value.item.advance_id,
    reason: modalA.value.reason,
  }, {
    onSuccess: () => { modalA.value.open = false },
    onFinish: () => { modalA.value.loading = false },
  })
}

// ─── Repair C (Invoice status) ────────────────────────────────────────────────
function repairOneC(item) {
  if (!confirm(`Cập nhật trạng thái hóa đơn ${item.invoice_code}: ${item.current_status} → ${item.expected_status}?`)) return
  router.post(route('accounting.repair-center.repair-invoice-status'), { invoice_id: item.invoice_id })
}

function repairAllC() {
  if (!confirm(`Cập nhật lại trạng thái cho ${props.checks.C.count} hóa đơn?`)) return
  router.post(route('accounting.repair-center.repair-all-invoice-statuses'))
}

// ─── Modal Reclass (E) ────────────────────────────────────────────────────────
const modalReclass = ref({ open: false, item: null, amount: 0, date: '', reason: '', loading: false })

function openReclassForm(item) {
  const today = new Date().toISOString().slice(0, 10)
  modalReclass.value = { open: true, item, amount: item.amount, date: today, reason: '', loading: false }
}

function submitReclass() {
  if (!modalReclass.value.reason.trim()) return
  modalReclass.value.loading = true
  router.post(route('accounting.repair-center.reclass'), {
    je_id:  modalReclass.value.item.je_id,
    amount: modalReclass.value.amount,
    date:   modalReclass.value.date,
    reason: modalReclass.value.reason,
  }, {
    onSuccess: () => { modalReclass.value.open = false },
    onFinish: () => { modalReclass.value.loading = false },
  })
}
</script>
