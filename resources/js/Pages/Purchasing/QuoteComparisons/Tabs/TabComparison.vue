<template>
  <div class="space-y-4">
    <div class="flex flex-wrap gap-4 items-center justify-between">
      <div class="flex items-center gap-3 text-sm">
        <span class="text-gray-500">So sánh theo:</span>
        <label class="flex items-center gap-1.5">
          <input type="radio" value="gross" :checked="priceBasis === 'gross'" @change="$emit('change-basis', 'gross')" />
          Giá gốc
        </label>
        <label class="flex items-center gap-1.5">
          <input type="radio" value="net" :checked="priceBasis === 'net'" @change="$emit('change-basis', 'net')" />
          Giá sau CK
        </label>
      </div>
      <p class="text-xs text-gray-400">Ô tô xanh = giá thấp nhất. Ô "—" = NCC không báo giá mặt hàng đó.</p>
    </div>

    <div v-if="!matrix.suppliers.length" class="text-center text-gray-400 py-10">
      Chưa có báo giá NCC nào để so sánh.
    </div>

    <div v-else class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm border-collapse">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-3 py-3 font-semibold text-gray-600 sticky left-0 bg-gray-50 z-10">Mã hàng</th>
            <th class="text-left px-3 py-3 font-semibold text-gray-600">Tên hàng</th>
            <th class="text-right px-3 py-3 font-semibold text-gray-600">SL</th>
            <th class="text-left px-3 py-3 font-semibold text-gray-600">ĐVT</th>
            <th v-for="s in matrix.suppliers" :key="s.supplier_id" class="text-right px-3 py-3 font-semibold text-gray-600 whitespace-nowrap">
              {{ s.name }}
              <span class="block text-[10px] font-normal text-gray-400">{{ s.code }} · v{{ s.version_no }}</span>
            </th>
            <th class="text-right px-3 py-3 font-semibold text-gray-600">Giá thấp nhất</th>
            <th class="text-left px-3 py-3 font-semibold text-gray-600">NCC thấp nhất</th>
            <th class="text-left px-3 py-3 font-semibold text-gray-600 min-w-[180px]">NCC lựa chọn</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="it in matrix.items" :key="it.comparison_item_id" class="hover:bg-gray-50/60">
            <td class="px-3 py-2 font-mono text-primary-700 sticky left-0 bg-white z-10">{{ it.product_code }}</td>
            <td class="px-3 py-2 text-gray-900">{{ it.product_name }}</td>
            <td class="px-3 py-2 text-right text-gray-700">{{ fmt(it.requested_qty) }}</td>
            <td class="px-3 py-2 text-gray-600">{{ it.unit || '—' }}</td>
            <td
              v-for="s in matrix.suppliers"
              :key="s.supplier_id"
              class="px-3 py-2 text-right tabular-nums cursor-pointer"
              :class="cellClass(it, s.supplier_id)"
              @click="openCell(it, s.supplier_id)"
            >
              <template v-if="it.cells[s.supplier_id]">
                {{ fmt(it.cells[s.supplier_id].compare_price) }}
                <span v-if="it.selection && it.selection.supplier_id === s.supplier_id" class="text-green-600 font-bold">✓</span>
              </template>
              <span v-else class="text-gray-300">—</span>
            </td>
            <td class="px-3 py-2 text-right font-medium text-gray-900">
              {{ it.lowest_price !== null ? fmt(it.lowest_price) : '—' }}
            </td>
            <td class="px-3 py-2 text-gray-600">{{ supplierName(it.lowest_supplier_id) || '—' }}</td>
            <td class="px-3 py-2">
              <select
                class="erp-input py-1 text-xs"
                :disabled="!canSelect || !Object.keys(it.cells).length"
                :value="it.selection?.supplier_id ?? ''"
                @change="e => onSelect(it, e.target.value)"
              >
                <option value="">-- Chưa chọn --</option>
                <option v-for="sid in Object.keys(it.cells)" :key="sid" :value="Number(sid)">
                  {{ supplierName(Number(sid)) }}
                </option>
              </select>
              <p v-if="it.selection && !it.selection.is_lowest_price" class="text-[11px] text-yellow-600 mt-0.5">
                {{ it.selection.selection_reason_label }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <CellDetailModal v-if="cellDetail" :cell="cellDetail" :item="cellItem" @close="cellDetail = null" />

    <SelectSupplierModal
      v-if="pendingSelect"
      :reasons="reasons"
      :item="pendingSelect.item"
      :supplier-name="supplierName(pendingSelect.supplierId)"
      @cancel="pendingSelect = null"
      @confirm="confirmReason"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/usePermission';
import CellDetailModal from '../Modals/CellDetailModal.vue';
import SelectSupplierModal from '../Modals/SelectSupplierModal.vue';

const props = defineProps({
  comparison: Object,
  matrix: Object,
  reasons: Array,
  priceBasis: String,
});
defineEmits(['change-basis']);

const { hasPermission } = usePermission();
const canSelect = computed(() => hasPermission('purchases.quote_comparisons.select_supplier'));

const cellDetail = ref(null);
const cellItem = ref(null);
const pendingSelect = ref(null);

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));
}

function supplierName(id) {
  return props.matrix.suppliers.find(s => s.supplier_id === id)?.name ?? '';
}

function cellClass(it, sid) {
  const cell = it.cells[sid];
  if (!cell) return '';
  const isLowest = it.lowest_price !== null && cell.compare_price <= it.lowest_price + 0.001;
  return isLowest ? 'bg-green-50 font-semibold text-green-800' : 'text-gray-800';
}

function openCell(it, sid) {
  if (!it.cells[sid]) return;
  cellItem.value = it;
  cellDetail.value = it.cells[sid];
}

function onSelect(it, supplierIdRaw) {
  const supplierId = supplierIdRaw === '' ? null : Number(supplierIdRaw);

  if (supplierId === null) {
    submit(it, null, null, null);
    return;
  }

  const cell = it.cells[supplierId];
  const isLowest = it.lowest_price !== null && cell.compare_price <= it.lowest_price + 0.001;

  if (isLowest) {
    submit(it, cell.quote_line_id, null, null);
  } else {
    pendingSelect.value = { item: it, supplierId, quoteLineId: cell.quote_line_id };
  }
}

function confirmReason({ reason, note }) {
  const p = pendingSelect.value;
  submit(p.item, p.quoteLineId, reason, note);
  pendingSelect.value = null;
}

function submit(it, quoteLineId, reason, note) {
  router.post(
    route('purchasing.quote-comparisons.items.selection', { quoteComparison: props.comparison.id, item: it.comparison_item_id }),
    { quote_line_id: quoteLineId, selection_reason: reason, selection_note: note },
    { preserveScroll: true },
  );
}
</script>
