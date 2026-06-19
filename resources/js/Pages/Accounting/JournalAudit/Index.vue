<template>
  <AppLayout title="Rà soát bút toán kế toán">
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-5">
      <!-- Header -->
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Rà soát bút toán kế toán</h1>
          <p class="text-sm text-gray-500 mt-1">Phát hiện bút toán thiếu, sai tài khoản, mất cân bằng theo TT133.</p>
        </div>
      </div>

      <!-- Filter form -->
      <div class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 items-end">
        <div class="flex flex-col gap-1">
          <label class="label text-xs">Từ ngày</label>
          <input v-model="form.from" type="date" class="input w-40" />
        </div>
        <div class="flex flex-col gap-1">
          <label class="label text-xs">Đến ngày</label>
          <input v-model="form.to" type="date" class="input w-40" />
        </div>
        <div class="flex flex-col gap-1">
          <label class="label text-xs">Mức độ</label>
          <select v-model="form.severity" class="input w-44">
            <option value="">Tất cả</option>
            <option value="critical">Nghiêm trọng</option>
            <option value="warning">Cảnh báo</option>
          </select>
        </div>
        <div class="flex flex-col gap-1">
          <label class="label text-xs">Loại lỗi</label>
          <select v-model="form.type" class="input w-56">
            <option value="">Tất cả</option>
            <option v-for="(info, code) in errorCodes" :key="code" :value="code">
              [{{ code }}] {{ info.label }}
            </option>
          </select>
        </div>
        <button @click="runAudit" class="btn-primary h-9" :disabled="!form.from && !form.to">
          Rà soát
        </button>
        <button v-if="ranAudit" @click="clearFilters" class="btn-secondary h-9">Xóa filter</button>
      </div>

      <!-- Chưa chạy -->
      <div v-if="!ranAudit" class="bg-blue-50 border border-blue-200 rounded-lg p-6 text-center text-blue-700">
        <p class="font-medium">Chọn khoảng ngày và nhấn <strong>Rà soát</strong> để bắt đầu kiểm tra.</p>
        <p class="text-sm mt-1 text-blue-500">Gợi ý: kiểm tra từ đầu năm tài chính đến nay.</p>
      </div>

      <!-- Kết quả: tổng hợp -->
      <div v-else-if="summary.total === 0" class="bg-green-50 border border-green-200 rounded-lg p-6 text-center text-green-700">
        <p class="text-lg font-semibold">✓ Không phát hiện vấn đề nào trong khoảng thời gian được kiểm tra.</p>
      </div>

      <template v-else>
        <!-- Summary cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div class="bg-white rounded-lg shadow p-4 text-center">
            <p class="text-3xl font-bold text-gray-900">{{ summary.total }}</p>
            <p class="text-sm text-gray-500 mt-1">Tổng vấn đề phát hiện</p>
          </div>
          <div class="bg-red-50 rounded-lg shadow p-4 text-center border border-red-100">
            <p class="text-3xl font-bold text-red-600">{{ summary.critical }}</p>
            <p class="text-sm text-red-500 mt-1">Nghiêm trọng</p>
          </div>
          <div class="bg-yellow-50 rounded-lg shadow p-4 text-center border border-yellow-100">
            <p class="text-3xl font-bold text-yellow-600">{{ summary.warning }}</p>
            <p class="text-sm text-yellow-500 mt-1">Cảnh báo</p>
          </div>
        </div>

        <!-- Findings table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">Danh sách vấn đề ({{ findings.length }})</h2>
            <span class="text-xs text-gray-400">Nhấn vào mã chứng từ để mở chi tiết</span>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="th w-20">Mã lỗi</th>
                  <th class="th w-24">Mức độ</th>
                  <th class="th">Chứng từ</th>
                  <th class="th w-28">Ngày</th>
                  <th class="th text-right w-36">Số tiền</th>
                  <th class="th">Đối tượng</th>
                  <th class="th">Mô tả lỗi</th>
                  <th class="th">Cách xử lý</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr
                  v-for="(f, i) in findings"
                  :key="i"
                  :class="f.severity === 'critical' ? 'bg-red-50/30' : 'bg-yellow-50/20'"
                >
                  <td class="td">
                    <span class="font-mono text-xs font-bold text-gray-700">{{ f.error_code }}</span>
                  </td>
                  <td class="td">
                    <span v-if="f.severity === 'critical'" class="badge badge-red">Nghiêm trọng</span>
                    <span v-else class="badge badge-yellow">Cảnh báo</span>
                  </td>
                  <td class="td font-medium">
                    <component :is="docLink(f) ? 'a' : 'span'"
                      :href="docLink(f)"
                      target="_blank"
                      :class="docLink(f) ? 'text-indigo-600 hover:underline' : 'text-gray-700'"
                    >
                      {{ f.document_code || `${f.document_type} #${f.document_id}` }}
                    </component>
                    <span class="ml-1 text-xs text-gray-400">{{ docTypeLabel(f.document_type) }}</span>
                  </td>
                  <td class="td text-sm text-gray-500">{{ formatDate(f.document_date) }}</td>
                  <td class="td text-right font-mono text-sm">
                    {{ f.document_amount != null ? fmt(f.document_amount) : '—' }}
                  </td>
                  <td class="td text-sm text-gray-600">{{ f.partner_name || '—' }}</td>
                  <td class="td text-sm text-red-700 max-w-xs">{{ f.description }}</td>
                  <td class="td text-sm text-blue-700 max-w-xs italic">{{ f.suggested_action }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'

const props = defineProps({
  findings:   { type: Array,   default: () => [] },
  summary:    { type: Object,  default: () => ({ total: 0, critical: 0, warning: 0 }) },
  filters:    { type: Object,  default: () => ({}) },
  errorCodes: { type: Object,  default: () => ({}) },
  ranAudit:   { type: Boolean, default: false },
})

const form = ref({
  from:     props.filters.from     || '',
  to:       props.filters.to       || '',
  severity: props.filters.severity || '',
  type:     props.filters.type     || '',
})

function runAudit() {
  router.get(route('accounting.journal-audit.index'), form.value, { preserveState: true })
}

function clearFilters() {
  form.value = { from: '', to: '', severity: '', type: '' }
  router.get(route('accounting.journal-audit.index'), {}, { preserveState: false })
}

function formatDate(d) {
  if (!d) return '—'
  return d.substring(0, 10).split('-').reverse().join('/')
}

function fmt(val) {
  return Number(val || 0).toLocaleString('vi-VN') + ' đ'
}

function docTypeLabel(type) {
  const map = {
    invoice:          '(HĐ bán)',
    purchase_invoice: '(HĐ mua)',
    stock_entry:      '(Nhập kho)',
    stock_exit:       '(Xuất kho)',
    journal_entry:    '(Bút toán)',
    payment:          '(Thanh toán)',
  }
  return map[type] || ''
}

function docLink(f) {
  const routes = {
    invoice:          f.document_id ? route('accounting.invoices.show', f.document_id) : null,
    purchase_invoice: f.document_id ? route('purchasing.purchase-invoices.show', f.document_id) : null,
    stock_entry:      f.document_id ? route('warehouse.stock-entries.show', f.document_id) : null,
    stock_exit:       f.document_id ? route('warehouse.stock-exits.show', f.document_id) : null,
    journal_entry:    f.document_id ? route('accounting.journal-entries.show', f.document_id) : null,
  }
  return routes[f.document_type] || null
}
</script>
