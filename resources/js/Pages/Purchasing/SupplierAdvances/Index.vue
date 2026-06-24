<template>
  <AppLayout title="Tiền trả trước NCC">
    <div class="space-y-5">

      <!-- Header -->
      <div class="erp-page-header">
        <h1 class="text-2xl font-bold text-gray-900">Tiền trả trước NCC</h1>
        <Link :href="route('purchasing.supplier-advances.create')" class="erp-btn-primary">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
          Thêm ứng trước
        </Link>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Tổng khoản</p>
          <p class="text-2xl font-bold text-gray-900">{{ advances.total }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Còn dư</p>
          <p class="text-2xl font-bold text-green-600">{{ summary.open }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Tổng còn lại</p>
          <p class="text-xl font-bold text-blue-700">{{ fmt(summary.total_remaining) }} đ</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đã dùng hết / Hủy</p>
          <p class="text-2xl font-bold text-gray-400">{{ summary.closed }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="filters.search" type="text" placeholder="Tìm theo NCC hoặc mã tham chiếu..."
          class="erp-input w-full sm:w-64" @input="debouncedSearch" />
        <select v-model="filters.supplier_id" class="erp-input w-full sm:w-auto" @change="applyFilters">
          <option value="">Tất cả NCC</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select v-model="filters.advance_type" class="erp-input w-full sm:w-auto" @change="applyFilters">
          <option value="">Tất cả loại</option>
          <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <select v-model="filters.status" class="erp-input w-full sm:w-auto" @change="applyFilters">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <input v-model="filters.fiscal_year" type="number" placeholder="Năm..."
          class="erp-input w-24" @change="applyFilters" />
        <button v-if="hasActiveFilters" @click="clearFilters"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Năm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã đối trừ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã thu hồi</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="advances.data.length === 0">
              <td colspan="10" class="py-12 text-center text-gray-400">Chưa có khoản ứng trước nào.</td>
            </tr>
            <tr v-for="adv in advances.data" :key="adv.id"
              class="hover:bg-gray-50 cursor-pointer" @click="goto(adv.id)">
              <td class="px-5 py-3 font-medium text-gray-900">{{ adv.supplier }}</td>
              <td class="px-5 py-3">
                <span :class="adv.advance_type === 'prepayment'
                  ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-200'
                  : 'bg-gray-100 text-gray-600'"
                  class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium">
                  {{ adv.type_label }}
                </span>
              </td>
              <td class="px-5 py-3 text-center text-gray-500">{{ adv.fiscal_year || '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ adv.opening_date }}</td>
              <td class="px-5 py-3 text-right font-mono font-medium text-gray-900">{{ fmt(adv.amount) }}</td>
              <td class="px-5 py-3 text-right font-mono text-orange-600">{{ fmt(adv.allocated_amount) }}</td>
              <td class="px-5 py-3 text-right font-mono text-blue-600">{{ fmt(adv.refunded_amount) }}</td>
              <td class="px-5 py-3 text-right font-mono font-semibold"
                :class="adv.remaining_amount > 0 ? 'text-green-700' : 'text-gray-400'">
                {{ fmt(adv.remaining_amount) }}
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="statusColor(adv.status)">{{ adv.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap" @click.stop>
                <Link :href="route('purchasing.supplier-advances.show', adv.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3">Xem</Link>
                <Link v-if="adv.status !== 'cancelled'"
                  :href="route('purchasing.supplier-advances.edit', adv.id)"
                  class="text-gray-600 hover:text-gray-800 text-xs font-medium mr-3">Sửa</Link>
                <Link v-if="adv.can_refund"
                  :href="route('purchasing.supplier-advances.show', adv.id)"
                  class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-3">Thu hồi</Link>
                <Link v-if="adv.can_cancel"
                  :href="route('purchasing.supplier-advances.show', adv.id)"
                  class="text-yellow-700 hover:text-yellow-900 text-xs font-medium mr-3">Hủy</Link>
                <button v-if="adv.can_delete"
                  @click="confirmDelete(adv)"
                  class="text-red-600 hover:text-red-800 text-xs font-medium">Xóa</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="advances.links" class="mt-2" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'
import Pagination from '@/Components/Shared/Pagination.vue'

const props = defineProps({
  advances:      Object,
  filters:       Object,
  suppliers:     Array,
  statusOptions: Array,
  typeOptions:   Array,
  summary:       Object,
})

const filters = ref({ ...props.filters })

const hasActiveFilters = computed(() =>
  filters.value.search || filters.value.supplier_id ||
  filters.value.advance_type || filters.value.status || filters.value.fiscal_year
)

let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(applyFilters, 400)
}

function applyFilters() {
  router.get(route('purchasing.supplier-advances.index'), filters.value, { preserveState: true, replace: true })
}

function clearFilters() {
  filters.value = { search: '', supplier_id: '', advance_type: '', status: '', fiscal_year: '' }
  applyFilters()
}

function goto(id) {
  router.visit(route('purchasing.supplier-advances.show', id))
}

function confirmDelete(adv) {
  if (!confirm(`Xóa khoản ứng trước đầu kỳ của "${adv.supplier}"? Hành động này không thể hoàn tác.`)) return
  router.delete(route('purchasing.supplier-advances.destroy', adv.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('vi-VN')
}

function statusColor(s) {
  const map = { open: 'green', partially_applied: 'yellow', fully_applied: 'gray', cancelled: 'red' }
  return map[s] || 'gray'
}
</script>
