<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.receipts.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ receipt.code }}</h1>
        <span :class="statusClass(receipt.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
          {{ { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' }[receipt.status] }}
        </span>
      </div>

      <div class="flex gap-2 mb-5">
        <button v-if="can('ccdc.manage') && receipt.status === 'draft'"
          @click="confirm" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm">
          Xác nhận & Tạo bút toán
        </button>
        <button v-if="can('ccdc.cancel') && receipt.status === 'confirmed'"
          @click="cancel" class="bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg text-sm">
          Hủy phiếu nhập
        </button>
      </div>

      <!-- Header info -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 mb-5">
        <dl class="grid grid-cols-3 gap-4 text-sm">
          <div><dt class="text-gray-500">Ngày nhập</dt><dd class="font-medium">{{ receipt.receipt_date }}</dd></div>
          <div><dt class="text-gray-500">Nhà cung cấp</dt><dd class="font-medium">{{ receipt.supplier_name || '—' }}</dd></div>
          <div><dt class="text-gray-500">Kho nhập</dt><dd class="font-medium">{{ receipt.warehouse_name }}</dd></div>
          <div><dt class="text-gray-500">Thanh toán</dt><dd class="font-medium">{{ receipt.payment_type }}</dd></div>
          <div v-if="receipt.fund_name"><dt class="text-gray-500">Quỹ</dt><dd>{{ receipt.fund_name }}</dd></div>
          <div v-if="receipt.notes"><dt class="text-gray-500">Ghi chú</dt><dd>{{ receipt.notes }}</dd></div>
        </dl>
      </div>

      <!-- Items -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto mb-5">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left">Mã CCDC</th>
              <th class="px-4 py-3 text-left">Tên</th>
              <th class="px-4 py-3 text-center">SL</th>
              <th class="px-4 py-3 text-right">Đơn giá</th>
              <th class="px-4 py-3 text-right">VAT</th>
              <th class="px-4 py-3 text-right">Thành tiền</th>
              <th class="px-4 py-3 text-center">TT CCDC</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="i in receipt.items" :key="i.id">
              <td class="px-4 py-3 font-mono text-xs">
                <Link :href="route('accounting.small-tools.show', i.tool_id)"
                  class="text-primary-600 hover:underline">{{ i.tool_code }}</Link>
              </td>
              <td class="px-4 py-3">{{ i.tool_name }}</td>
              <td class="px-4 py-3 text-center">{{ i.quantity }}</td>
              <td class="px-4 py-3 text-right font-mono">{{ formatVnd(i.unit_price) }}</td>
              <td class="px-4 py-3 text-right font-mono text-gray-500">{{ formatVnd(i.vat_amount) }}</td>
              <td class="px-4 py-3 text-right font-mono font-semibold">{{ formatVnd(i.total_amount) }}</td>
              <td class="px-4 py-3 text-center text-xs text-gray-500">{{ i.tool_status }}</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t font-semibold text-sm">
            <tr>
              <td colspan="4" class="px-4 py-3 text-right">Tổng VAT:</td>
              <td class="px-4 py-3 text-right font-mono">{{ formatVnd(receipt.vat_amount) }}</td>
              <td colspan="2"></td>
            </tr>
            <tr>
              <td colspan="4" class="px-4 py-3 text-right font-bold">Tổng cộng:</td>
              <td colspan="2" class="px-4 py-3 text-right font-mono font-bold text-primary-700">{{ formatVnd(receipt.total_amount) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div v-if="receipt.journal_entry_id" class="text-sm text-gray-500">
        Bút toán: #{{ receipt.journal_entry_id }}
        <Link :href="route('accounting.journal-entries.show', receipt.journal_entry_id)"
          class="text-primary-600 hover:underline ml-1">Xem</Link>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();
const props = defineProps({ receipt: Object });

function statusClass(s) {
  return { draft: 'bg-yellow-100 text-yellow-700', confirmed: 'bg-green-100 text-green-700', cancelled: 'bg-gray-100 text-gray-500' }[s];
}
function confirm() { router.post(route('accounting.small-tools.receipts.confirm', props.receipt.id)); }
function cancel()  { if (confirm('Hủy phiếu nhập? Thao tác không thể hoàn tác.')) router.post(route('accounting.small-tools.receipts.cancel', props.receipt.id)); }
</script>
