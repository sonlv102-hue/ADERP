<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu xuất kho</h1>
        <Link :href="route('warehouse.stock-exits.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo phiếu xuất
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày xuất</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Lý do</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số dòng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="exit in exits.data" :key="exit.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ exit.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.exit_date }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.warehouse?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.customer?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.reason ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.creator?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ exit.items_count }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="exit.status_color">{{ exit.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('warehouse.stock-exits.show', exit.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!exits.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có phiếu xuất nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="exits.links" :meta="exits.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

defineProps({ exits: Object });
</script>
