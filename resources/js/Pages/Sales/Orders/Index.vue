<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Đơn hàng</h1>
        <Link :href="route('sales.orders.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo đơn hàng
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã ĐH</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày đặt</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">BG liên kết</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Duyệt đơn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hợp đồng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Giao hàng</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="o in orders.data" :key="o.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ o.code }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ o.customer }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                {{ o.order_date }}
                <span v-if="o.expected_delivery" class="block text-xs text-gray-400">Giao: {{ o.expected_delivery }}</span>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ o.quotation_code ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ formatVnd(o.total) }}</td>

              <!-- Duyệt đơn -->
              <td class="px-5 py-3">
                <StatusBadge :color="o.status_color">{{ o.status_label }}</StatusBadge>
              </td>

              <!-- Hợp đồng -->
              <td class="px-5 py-3">
                <span v-if="o.has_contract"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Có HĐ
                </span>
                <span v-else-if="o.status !== 'cancelled'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                  Chưa có HĐ
                </span>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <!-- Giao hàng -->
              <td class="px-5 py-3">
                <span v-if="o.delivery_status === 'done'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã giao
                </span>
                <span v-else-if="o.delivery_status === 'partial'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Đang giao
                </span>
                <span v-else-if="o.delivery_status === 'none'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                  Chưa giao
                </span>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <td class="px-5 py-3 text-right">
                <Link :href="route('sales.orders.show', o.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!orders.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có đơn hàng nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="orders.links" :meta="orders.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

defineProps({ orders: Object });

const { formatVnd } = useCurrency();
</script>
