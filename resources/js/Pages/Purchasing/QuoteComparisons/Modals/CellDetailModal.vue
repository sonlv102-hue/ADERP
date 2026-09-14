<template>
  <Modal :show="true" max-width="md" @close="$emit('close')">
    <template #title>Chi tiết báo giá</template>
    <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
      <dt class="text-gray-500">Mặt hàng</dt>
      <dd class="col-span-2 text-gray-900">{{ item.product_code }} — {{ item.product_name }}</dd>

      <dt class="text-gray-500">NCC</dt>
      <dd class="col-span-2 text-gray-900">{{ supplierName }}</dd>

      <dt class="text-gray-500">Số báo giá</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.quote_no || '—' }} (v{{ cell.version_no }})</dd>

      <dt class="text-gray-500">Ngày báo giá</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.quote_date || '—' }}</dd>

      <dt class="text-gray-500">Đơn giá</dt>
      <dd class="col-span-2 text-gray-900">{{ fmt(cell.unit_price) }}</dd>

      <dt class="text-gray-500">Chiết khấu</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.discount_percent }}%</dd>

      <dt class="text-gray-500">Giá sau CK</dt>
      <dd class="col-span-2 font-semibold text-gray-900">{{ fmt(cell.net_unit_price) }}</dd>

      <dt class="text-gray-500">VAT</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.vat_percent }}%</dd>

      <dt class="text-gray-500">Thời gian giao</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.delivery_time || '—' }}</dd>

      <dt class="text-gray-500">Bảo hành</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.warranty || '—' }}</dd>

      <dt class="text-gray-500">Ghi chú</dt>
      <dd class="col-span-2 text-gray-900">{{ cell.note || '—' }}</dd>
    </dl>
    <template #footer>
      <a
        :href="route('purchasing.quote-comparisons.quotes.file', { quoteComparison: routeComparisonId, quote: cell.quote_id })"
        class="erp-btn-secondary"
      >Xem file báo giá gốc</a>
      <button class="erp-btn-primary" @click="$emit('close')">Đóng</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Shared/Modal.vue';

const props = defineProps({ cell: Object, item: Object });
defineEmits(['close']);

const page = usePage();
const routeComparisonId = computed(() => page.props.comparison?.id);
const supplierName = computed(() => {
  const s = (page.props.matrix?.suppliers ?? []).find(x => x.supplier_id === props.cell.supplier_id);
  return s?.name ?? '';
});

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));
}
</script>
