<template>
  <AppLayout title="Ứng trước NCC đầu kỳ">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Ứng trước NCC đầu kỳ</h1>
        <Link
          :href="route('purchasing.supplier-advances.create')"
          class="btn-primary"
        >
          + Thêm ứng trước
        </Link>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-lg shadow p-4 mb-4 flex flex-wrap gap-3">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Tìm theo NCC hoặc mã tham chiếu..."
          class="input flex-1 min-w-[200px]"
          @input="debouncedSearch"
        />
        <select v-model="filters.supplier_id" class="input w-48" @change="applyFilters">
          <option value="">Tất cả NCC</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select v-model="filters.status" class="input w-44" @change="applyFilters">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <input
          v-model="filters.fiscal_year"
          type="number"
          placeholder="Năm..."
          class="input w-28"
          @change="applyFilters"
        />
      </div>

      <!-- Table -->
      <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="th">Nhà cung cấp</th>
              <th class="th text-center">Năm</th>
              <th class="th">Ngày mở</th>
              <th class="th text-right">Số ứng trước</th>
              <th class="th text-right">Đã đối trừ</th>
              <th class="th text-right">Còn lại</th>
              <th class="th">Tham chiếu</th>
              <th class="th text-center">Trạng thái</th>
              <th class="th"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="advances.data.length === 0">
              <td colspan="9" class="py-10 text-center text-gray-500 italic">
                Chưa có khoản ứng trước nào.
              </td>
            </tr>
            <tr
              v-for="adv in advances.data"
              :key="adv.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="goto(adv.id)"
            >
              <td class="td font-medium">{{ adv.supplier?.name }}</td>
              <td class="td text-center">{{ adv.fiscal_year }}</td>
              <td class="td">{{ formatDate(adv.opening_date) }}</td>
              <td class="td text-right font-mono">{{ fmt(adv.amount) }}</td>
              <td class="td text-right font-mono text-orange-600">
                {{ fmt(adv.amount - adv.remaining_amount) }}
              </td>
              <td class="td text-right font-mono font-semibold"
                :class="adv.remaining_amount > 0 ? 'text-green-700' : 'text-gray-400'"
              >
                {{ fmt(adv.remaining_amount) }}
              </td>
              <td class="td text-sm text-gray-500">{{ adv.reference_no || '—' }}</td>
              <td class="td text-center">
                <span :class="statusBadge(adv.status)" class="badge">
                  {{ statusLabel(adv.status) }}
                </span>
              </td>
              <td class="td text-right">
                <Link
                  :href="route('purchasing.supplier-advances.show', adv.id)"
                  class="text-indigo-600 hover:text-indigo-800 text-sm"
                  @click.stop
                >
                  Chi tiết
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <Pagination :links="advances.links" class="mt-4" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import Pagination from '@/Components/Pagination.vue'

const props = defineProps({
  advances:      Object,
  filters:       Object,
  suppliers:     Array,
  statusOptions: Array,
})

const filters = ref({ ...props.filters })

let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(applyFilters, 400)
}

function applyFilters() {
  router.get(route('purchasing.supplier-advances.index'), filters.value, { preserveState: true, replace: true })
}

function goto(id) {
  router.visit(route('purchasing.supplier-advances.show', id))
}

function formatDate(d) {
  if (!d) return '—'
  const parts = d.split('-')
  return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : d
}

function fmt(val) {
  return Number(val || 0).toLocaleString('vi-VN')
}

function statusLabel(s) {
  const map = {
    open:              'Còn dư',
    partially_applied: 'Đối trừ một phần',
    fully_applied:     'Đã dùng hết',
    cancelled:         'Đã hủy',
  }
  return map[s] || s
}

function statusBadge(s) {
  const map = {
    open:              'badge-green',
    partially_applied: 'badge-yellow',
    fully_applied:     'badge-gray',
    cancelled:         'badge-red',
  }
  return map[s] || 'badge-gray'
}
</script>
