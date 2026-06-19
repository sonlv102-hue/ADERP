<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Luân chuyển quỹ</h1>
        <Link :href="route('accounting.fund-transfers.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Tạo phiếu luân chuyển
        </Link>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center">
        <select v-model="filterStatus" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Từ quỹ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đến quỹ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in transfers.data" :key="t.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ t.transfer_no }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.transfer_date }}</td>
              <td class="px-5 py-3 text-gray-700">{{ t.from_fund }}</td>
              <td class="px-5 py-3 text-gray-700">{{ t.to_fund }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(t.amount) }}</td>
              <td class="px-5 py-3 text-center">
                <span :class="statusClass(t.status_color)"
                  class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ t.status_label }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.fund-transfers.show', t.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!transfers.data?.length">
              <td colspan="7" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có phiếu luân chuyển quỹ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="transfers.links" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  transfers: Object,
  filters:   Object,
  statuses:  Array,
});

const { formatVnd } = useCurrency();
const filterStatus  = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('accounting.fund-transfers.index'), { status: filterStatus.value }, { preserveState: true });
}

function statusClass(color) {
  const map = {
    gray:   'bg-gray-100 text-gray-700',
    blue:   'bg-blue-100 text-blue-700',
    orange: 'bg-orange-100 text-orange-700',
    red:    'bg-red-100 text-red-700',
    green:  'bg-green-100 text-green-700',
  };
  return map[color] ?? 'bg-gray-100 text-gray-700';
}
</script>
