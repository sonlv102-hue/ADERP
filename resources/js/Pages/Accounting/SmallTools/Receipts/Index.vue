<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Phiếu nhập kho CCDC</h1>
      <Link v-if="can('ccdc.manage')" :href="route('accounting.small-tools.receipts.create')"
        class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
        + Tạo phiếu nhập
      </Link>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Mã phiếu</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Ngày nhập</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Nhà cung cấp</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Kho</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Tổng tiền</th>
            <th class="px-4 py-3 text-center font-semibold text-gray-700">Trạng thái</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="!receipts.data.length">
            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Chưa có phiếu nhập nào.</td>
          </tr>
          <tr v-for="r in receipts.data" :key="r.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-xs">{{ r.code }}</td>
            <td class="px-4 py-3">{{ r.receipt_date }}</td>
            <td class="px-4 py-3 text-gray-600">{{ r.supplier_name || '—' }}</td>
            <td class="px-4 py-3 text-gray-600">{{ r.warehouse_name }}</td>
            <td class="px-4 py-3 text-right font-mono">{{ formatVnd(r.total_amount) }}</td>
            <td class="px-4 py-3 text-center">
              <span :class="statusClass(r.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                {{ { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' }[r.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <Link :href="route('accounting.small-tools.receipts.show', r.id)"
                class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="receipts.last_page > 1" class="flex justify-end mt-4 gap-1">
      <Link v-for="link in receipts.links" :key="link.label"
        :href="link.url || '#'"
        :class="['px-3 py-1 text-sm rounded border', link.active ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50']"
        v-html="link.label" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();
const props = defineProps({ receipts: Object, filters: Object });

function statusClass(s) {
  return { draft: 'bg-yellow-100 text-yellow-700', confirmed: 'bg-green-100 text-green-700', cancelled: 'bg-gray-100 text-gray-500' }[s];
}
</script>
