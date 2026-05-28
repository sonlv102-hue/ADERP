<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Đơn mua hàng</h1>
        <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-orders.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo đơn mua
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã đơn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày đặt</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kho nhận</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhập kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hóa đơn / TT</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="po in orders.data" :key="po.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ po.code }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ po.supplier }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                {{ po.order_date }}
                <span v-if="po.expected_date" class="block text-xs text-gray-400">Dự kiến: {{ po.expected_date }}</span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ po.warehouse }}</td>
              <td class="px-5 py-3 text-right text-gray-800 font-medium">{{ formatVnd(po.total) }}</td>

              <!-- Trạng thái đơn -->
              <td class="px-5 py-3">
                <StatusBadge :color="po.status_color">{{ po.status_label }}</StatusBadge>
              </td>

              <!-- Nhập kho -->
              <td class="px-5 py-3">
                <span v-if="po.receipt_status === 'done'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã nhận đủ
                </span>
                <span v-else-if="po.receipt_status === 'partial'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Nhận một phần
                </span>
                <span v-else-if="po.receipt_status === 'none'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                  Chưa nhận
                </span>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <!-- Hóa đơn / Thanh toán -->
              <td class="px-5 py-3">
                <span v-if="po.invoice_status === 'paid'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã thanh toán
                </span>
                <span v-else-if="po.invoice_status === 'partial_paid'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  Đang thanh toán
                </span>
                <span v-else-if="po.invoice_status"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 border border-yellow-200">
                  Có HĐ / Chưa TT
                </span>
                <span v-else-if="po.status !== 'cancelled'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                  Chưa lập HĐ
                </span>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <td class="px-5 py-3 text-right">
                <Link :href="route('purchasing.purchase-orders.show', po.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!orders.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có đơn mua hàng nào</td>
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
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

defineProps({ orders: Object });

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();
</script>
