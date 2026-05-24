<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu nhập kho</h1>
        <div class="flex items-center gap-2">
          <a :href="route('warehouse.stock-entries.export-pdf')" target="_blank"
            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Xuất danh sách
          </a>
          <Link :href="route('purchasing.purchase-orders.index')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2"
            title="Nhập kho từ đơn mua hàng">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo từ đơn mua
          </Link>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày nhập</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số dòng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ entry.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ entry.entry_date }}</td>
              <td class="px-5 py-3 text-gray-600">{{ entry.warehouse ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ entry.supplier ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ entry.creator ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ entry.items_count }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="entry.status_color">{{ entry.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('warehouse.stock-entries.show', entry.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!entries.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có phiếu nhập nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="entries.links" :meta="entries.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

defineProps({ entries: Object });
</script>
