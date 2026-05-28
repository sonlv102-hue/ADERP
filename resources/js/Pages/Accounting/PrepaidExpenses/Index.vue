<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Chi phí trả trước</h1>
          <p class="text-sm text-gray-500 mt-0.5">TK 142 (ngắn hạn) / TK 242 (dài hạn)</p>
        </div>
        <Link v-if="can('accounting.manage')" :href="route('accounting.prepaid-expenses.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm mới
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @change="applyFilters" placeholder="Tìm mã, diễn giải..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="status" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="active">Đang phân bổ</option>
          <option value="fully_amortized">Đã phân bổ hết</option>
          <option value="cancelled">Đã hủy</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">TK</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã phân bổ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="e in expenses.data" :key="e.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-700">{{ e.code }}</td>
              <td class="px-5 py-3 text-gray-800">{{ e.description }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ e.account_code }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ fmt(e.total_amount) }}</td>
              <td class="px-5 py-3 text-right text-green-700">{{ fmt(e.amortized_amount) }}</td>
              <td class="px-5 py-3 text-right font-semibold"
                :class="e.remaining_amount > 0 ? 'text-blue-700' : 'text-gray-400'">
                {{ fmt(e.remaining_amount) }}
              </td>
              <td class="px-5 py-3 text-xs text-gray-500">{{ e.start_date }} → {{ e.end_date }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="e.status_color">{{ e.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.prepaid-expenses.show', e.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium text-sm">Xem</Link>
              </td>
            </tr>
            <tr v-if="!expenses.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có chi phí trả trước nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="expenses.links" :meta="expenses.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ expenses: Object, filters: Object });
const { hasPermission: can } = usePermission();
const { formatVnd: fmt } = useCurrency();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('accounting.prepaid-expenses.index'),
    { search: search.value, status: status.value },
    { preserveState: true });
}
</script>
