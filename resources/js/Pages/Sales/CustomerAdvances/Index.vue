<template>
  <AppLayout title="Ứng trước khách hàng">
    <div class="space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Ứng trước khách hàng</h1>
          <p class="text-sm text-gray-500 mt-0.5">Quản lý khoản khách hàng ứng trước (131UT)</p>
        </div>
        <Link v-if="can('accounting.manage')" :href="route('sales.customer-advances.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Thêm ứng trước
        </Link>
      </div>

      <!-- Summary -->
      <div class="grid grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <div class="text-sm text-gray-500">Khoản còn dư</div>
          <div class="text-xl font-bold text-gray-900 mt-1">{{ summary.open }}</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <div class="text-sm text-gray-500">Tổng còn khả dụng</div>
          <div class="text-xl font-bold text-primary-700 mt-1">{{ formatVnd(summary.total_remaining) }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex gap-3 flex-wrap">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm kiếm..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 w-56" />
        <select v-model="status" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <select v-model="advance_type" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả loại</option>
          <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <button @click="applyFilters"
          class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700">
          Tìm
        </button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Khách hàng</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Loại</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Ngày</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Số tiền</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Còn lại</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Trạng thái</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="adv in advances.data" :key="adv.id" class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900">{{ adv.customer }}</div>
                <div class="text-xs text-gray-500">{{ adv.customer_code }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-1 rounded-full"
                  :class="adv.advance_type === 'advance_receipt' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'">
                  {{ adv.type_label }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-600">{{ adv.advance_date }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ formatVnd(adv.amount) }}</td>
              <td class="px-4 py-3 text-right font-mono" :class="adv.remaining_amount > 0 ? 'text-primary-700 font-semibold' : 'text-gray-400'">
                {{ formatVnd(adv.remaining_amount) }}
              </td>
              <td class="px-4 py-3">
                <span class="text-xs px-2 py-1 rounded-full"
                  :class="{
                    'bg-green-100 text-green-700': adv.status === 'open',
                    'bg-yellow-100 text-yellow-700': adv.status === 'partially_applied',
                    'bg-gray-100 text-gray-500': adv.status === 'fully_applied',
                    'bg-red-100 text-red-600': adv.status === 'cancelled',
                  }">
                  {{ adv.status_label }}
                </span>
              </td>
              <td class="px-4 py-3">
                <Link :href="route('sales.customer-advances.show', adv.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">
                  Chi tiết
                </Link>
              </td>
            </tr>
            <tr v-if="advances.data.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">Chưa có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="advances.links" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import Pagination from '@/Components/Shared/Pagination.vue'
import { usePermission } from '@/composables/usePermission'

const { hasPermission: can } = usePermission()

const props = defineProps({
  advances:      Object,
  filters:       Object,
  customers:     Array,
  statusOptions: Array,
  typeOptions:   Array,
  summary:       Object,
})

const search       = ref(props.filters.search ?? '')
const status       = ref(props.filters.status ?? '')
const advance_type = ref(props.filters.advance_type ?? '')

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫'
}

function applyFilters() {
  router.get(route('sales.customer-advances.index'), {
    search: search.value,
    status: status.value,
    advance_type: advance_type.value,
  }, { preserveState: true })
}
</script>
