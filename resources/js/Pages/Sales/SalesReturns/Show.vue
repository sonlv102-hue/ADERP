<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('sales.sales-returns.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ salesReturn.code }}</h1>
          <StatusBadge :color="salesReturn.status_color">{{ salesReturn.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2">
          <Link v-if="salesReturn.status === 'draft'"
            :href="route('sales.sales-returns.edit', salesReturn.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="salesReturn.status === 'draft'"
            @click="confirm"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận
          </button>
          <button v-if="salesReturn.status === 'draft' || salesReturn.status === 'confirmed'"
            @click="cancel"
            class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy phiếu
          </button>
        </div>
      </div>

      <!-- Flash messages -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Meta cards -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Đơn hàng</p>
          <Link :href="route('sales.orders.show', salesReturn.order.id)"
            class="font-semibold text-primary-600 hover:text-primary-800 font-mono">
            {{ salesReturn.order.code }}
          </Link>
          <p class="text-sm text-gray-600 mt-1">{{ salesReturn.customer }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Kho nhận hàng</p>
          <p class="font-semibold text-gray-800">{{ salesReturn.warehouse }}</p>
          <p class="text-xs text-gray-500 mt-1">Ngày trả: {{ salesReturn.return_date }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Người tạo</p>
          <p class="font-semibold text-gray-800">{{ salesReturn.creator }}</p>
        </div>
      </div>

      <!-- Reason / Notes -->
      <div v-if="salesReturn.reason || salesReturn.notes" class="bg-white rounded-xl border border-gray-200 p-4 space-y-2 text-sm">
        <div v-if="salesReturn.reason">
          <span class="font-semibold text-gray-700">Lý do trả hàng: </span>
          <span class="text-gray-600">{{ salesReturn.reason }}</span>
        </div>
        <div v-if="salesReturn.notes">
          <span class="font-semibold text-gray-700">Ghi chú: </span>
          <span class="text-gray-600">{{ salesReturn.notes }}</span>
        </div>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Danh sách hàng trả</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">#</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Sản phẩm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">SL trả</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="(item, i) in salesReturn.items" :key="item.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-500">{{ i + 1 }}</td>
                <td class="px-5 py-3">
                  <p class="font-medium text-gray-800">{{ item.product_name }}</p>
                  <p class="text-xs text-gray-400 font-mono">{{ item.product_code }}</p>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-right font-medium text-gray-800">{{ item.quantity }}</td>
                <td class="px-5 py-3 text-right text-gray-600">{{ formatVnd(item.unit_price) }}</td>
                <td class="px-5 py-3 text-right font-semibold text-gray-800">{{ formatVnd(item.total) }}</td>
              </tr>
              <!-- Serials sub-row -->
              <tr v-if="item.has_serial && item.serials?.length" class="bg-gray-50">
                <td colspan="6" class="px-8 py-2">
                  <div class="flex flex-wrap gap-2">
                    <span v-for="s in item.serials" :key="s.serial_number"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-mono border border-gray-200 bg-white text-gray-600">
                      {{ s.serial_number }}
                      <StatusBadge :color="s.status_color" class="text-xs">{{ s.status_label }}</StatusBadge>
                    </span>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="5" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng giá trị trả:</td>
              <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(grandTotal) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ salesReturn: Object });
const { formatVnd } = useCurrency();

const grandTotal = computed(() =>
  props.salesReturn.items.reduce((sum, i) => sum + i.total, 0)
);

function confirm() {
  if (confirm('Xác nhận phiếu trả hàng? Hàng sẽ được nhập vào kho.')) {
    router.post(route('sales.sales-returns.confirm', props.salesReturn.id));
  }
}

function cancel() {
  if (confirm('Hủy phiếu trả hàng? Thao tác này không thể hoàn tác.')) {
    router.post(route('sales.sales-returns.cancel', props.salesReturn.id));
  }
}
</script>
