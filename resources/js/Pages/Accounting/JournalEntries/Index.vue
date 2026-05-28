<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu kế toán / Bút toán</h1>
        <Link v-if="can('accounting.manage')" :href="route('accounting.journal-entries.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo bút toán
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <input v-model="search" @change="applyFilters" placeholder="Tìm mã, diễn giải..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="status" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="draft">Nháp</option>
          <option value="posted">Đã hạch toán</option>
          <option value="reversed">Đã đảo</option>
        </select>
        <input v-model="from" @change="applyFilters" type="date"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <input v-model="to" @change="applyFilters" type="date"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã BT</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng Nợ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="e in entries.data" :key="e.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ e.code }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ e.entry_date }}</td>
              <td class="px-5 py-3 text-gray-800">{{ e.description }}</td>
              <td class="px-5 py-3 text-right text-gray-800 font-medium">{{ formatVnd(e.total_debit) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="e.status_color">{{ e.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3">
                <span v-if="e.is_auto" class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-600 border border-blue-200">
                  Tự động
                </span>
                <span v-else class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                  Thủ công
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.journal-entries.show', e.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!entries.data?.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Chưa có bút toán nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="entries.links" :meta="entries.meta" />
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

const props = defineProps({ entries: Object, filters: Object });
const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');
const from   = ref(props.filters?.from ?? '');
const to     = ref(props.filters?.to ?? '');

function applyFilters() {
  router.get(route('accounting.journal-entries.index'),
    { search: search.value, status: status.value, from: from.value, to: to.value },
    { preserveState: true }
  );
}
</script>
