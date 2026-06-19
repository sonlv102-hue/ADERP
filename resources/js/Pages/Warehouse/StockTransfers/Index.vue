<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu chuyển kho</h1>
        <Link
          v-if="can('stock-transfers.create')"
          :href="route('warehouse.stock-transfers.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo phiếu chuyển kho
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Từ kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đến kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số dòng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in transfers.data" :key="t.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ t.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.transfer_date }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.from_warehouse }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.to_warehouse }}</td>
              <td class="px-5 py-3 text-gray-600">{{ t.items_count }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="t.status_color">{{ t.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ t.creator }}</td>
              <td class="px-5 py-3 text-right">
                <Link
                  :href="route('warehouse.stock-transfers.show', t.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium"
                >Xem</Link>
              </td>
            </tr>
            <tr v-if="!transfers.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có phiếu chuyển kho nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="transfers.links" :meta="transfers.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

defineProps({ transfers: Object });

const { hasPermission } = usePermission();
const can = hasPermission;
</script>
