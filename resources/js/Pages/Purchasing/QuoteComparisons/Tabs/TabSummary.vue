<template>
  <div class="space-y-5">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="rounded-xl border border-gray-200 p-4">
        <p class="text-xs text-gray-500">Giá trị theo phương án lựa chọn (chưa VAT)</p>
        <p class="text-xl font-bold text-gray-900 mt-1">{{ fmt(summary.selection_subtotal) }} ₫</p>
      </div>
      <div class="rounded-xl border border-gray-200 p-4">
        <p class="text-xs text-gray-500">Giá trị nếu luôn chọn giá thấp nhất</p>
        <p class="text-xl font-bold text-gray-900 mt-1">{{ fmt(summary.lowest_total) }} ₫</p>
      </div>
      <div class="rounded-xl border p-4" :class="diffClass">
        <p class="text-xs text-gray-500">Chênh lệch</p>
        <p class="text-xl font-bold mt-1">{{ fmt(summary.difference) }} ₫</p>
      </div>
    </div>

    <p class="text-sm" :class="summary.is_all_lowest ? 'text-green-700' : 'text-gray-600'">
      <template v-if="summary.is_all_lowest">
        Đạt phương án giá thấp nhất theo dữ liệu báo giá hiện tại.
      </template>
      <template v-else-if="summary.difference > 0">
        Phương án lựa chọn cao hơn giá thấp nhất: {{ fmt(summary.difference) }} đồng.
      </template>
      <template v-else>
        Đã chọn {{ summary.selected_count }}/{{ summary.item_count }} mặt hàng.
      </template>
    </p>

    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">NCC</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Số SKU được chọn</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Giá trị chưa VAT</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">VAT</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Tổng thanh toán</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="s in summary.by_supplier" :key="s.supplier_id" class="hover:bg-gray-50">
            <td class="px-4 py-3 text-gray-900 font-medium">{{ s.supplier_name }}</td>
            <td class="px-4 py-3 text-right text-gray-700">{{ s.sku_count }}</td>
            <td class="px-4 py-3 text-right text-gray-900">{{ fmt(s.subtotal) }}</td>
            <td class="px-4 py-3 text-right text-gray-600">{{ fmt(s.vat) }}</td>
            <td class="px-4 py-3 text-right font-medium text-gray-900">{{ fmt(s.total) }}</td>
          </tr>
          <tr v-if="!summary.by_supplier.length">
            <td colspan="5" class="px-4 py-10 text-center text-gray-400">Chưa chọn NCC cho mặt hàng nào</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ summary: Object });

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));
}

const diffClass = computed(() => {
  if (props.summary.difference > 0) return 'border-yellow-200 bg-yellow-50 text-yellow-800';
  return 'border-green-200 bg-green-50 text-green-800';
});
</script>
