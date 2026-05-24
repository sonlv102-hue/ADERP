<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Hợp đồng mua</h1>
        <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-contracts.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo hợp đồng mua
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tiêu đề</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn mua</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Giá trị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hiệu lực</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in contracts.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ c.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900 max-w-xs truncate">{{ c.title }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.supplier }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ c.order_code ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(c.value) }}</td>
              <td class="px-5 py-3 text-gray-500 text-xs">
                {{ c.start_date ?? '—' }}
                <span v-if="c.end_date"> → {{ c.end_date }}</span>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('purchasing.purchase-contracts.show', c.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!contracts.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có hợp đồng mua nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="contracts.links" :meta="contracts.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

defineProps({ contracts: Object });

const { hasPermission } = usePermission();
const can = hasPermission;

const { formatVnd } = useCurrency();
</script>
