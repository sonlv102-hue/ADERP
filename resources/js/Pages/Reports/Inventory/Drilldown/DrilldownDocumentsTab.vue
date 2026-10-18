<template>
  <div class="space-y-2">
    <p v-if="loading" class="text-sm text-gray-400">Đang tải...</p>
    <template v-else>
      <div class="overflow-x-auto border border-gray-200 rounded-lg max-h-[50vh] overflow-y-auto">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 sticky top-0">
            <tr>
              <th class="text-left px-2 py-1.5 cursor-pointer" @click="sortBy('doc_date')">Ngày</th>
              <th class="text-left px-2 py-1.5">Loại</th>
              <th class="text-left px-2 py-1.5">Số CT</th>
              <th class="text-left px-2 py-1.5 cursor-pointer" @click="sortBy('product_code')">SP</th>
              <th class="text-left px-2 py-1.5">Kho</th>
              <th class="text-right px-2 py-1.5 cursor-pointer" @click="sortBy('quantity')">SL</th>
              <th class="text-right px-2 py-1.5 cursor-pointer" @click="sortBy('amount')">Giá trị</th>
              <th class="text-left px-2 py-1.5">Diễn giải</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
              <td class="px-2 py-1.5 whitespace-nowrap">{{ row.doc_date }}</td>
              <td class="px-2 py-1.5 whitespace-nowrap">{{ row.type_label }}</td>
              <td class="px-2 py-1.5 font-mono">
                <a v-if="row.linkable" :href="docHref(row)" target="_blank" class="text-primary-700 underline hover:text-primary-800">{{ row.doc_code ?? '—' }}</a>
                <span v-else :title="row.doc_code ? 'Chưa hỗ trợ mở trang chi tiết cho loại chứng từ này' : ''">{{ row.doc_code ?? '—' }}</span>
              </td>
              <td class="px-2 py-1.5">{{ row.product_code }} <span class="text-gray-400">{{ row.product_name }}</span></td>
              <td class="px-2 py-1.5">{{ row.warehouse_name ?? '—' }}</td>
              <td class="px-2 py-1.5 text-right">{{ fmtQty(row.quantity) }}</td>
              <td class="px-2 py-1.5 text-right">
                <span v-if="row.has_source_value">{{ formatVnd(row.amount) }}</span>
                <span v-else class="text-amber-600" title="Chưa có giá trị nguồn">— ⚠</span>
              </td>
              <td class="px-2 py-1.5 text-gray-500 max-w-[220px] truncate" :title="row.notes">{{ row.notes }}</td>
            </tr>
            <tr v-if="!rows.length"><td colspan="8" class="text-center py-6 text-gray-400">Không có chứng từ</td></tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between text-xs text-gray-500">
        <span>Trang {{ meta.current_page }}/{{ meta.last_page }} · {{ meta.total }} dòng</span>
        <div class="flex gap-1">
          <button class="erp-btn-secondary erp-btn-sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">←</button>
          <button class="erp-btn-secondary erp-btn-sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">→</button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { formatVnd } from '@/composables/useCurrency';

const props = defineProps({ filters: Object, kpi: String });

const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const sort = ref('doc_date');
const direction = ref('desc');

async function load(page = 1) {
  loading.value = true;
  try {
    const { data } = await axios.get(route('reports.inventory.drilldown'), {
      params: { ...props.filters, kpi: props.kpi, tab: 'documents', page, sort: sort.value, direction: direction.value },
    });
    rows.value = data.data;
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total };
  } finally {
    loading.value = false;
  }
}

function sortBy(key) {
  direction.value = sort.value === key && direction.value === 'desc' ? 'asc' : 'desc';
  sort.value = key;
  load(1);
}

function fmtQty(n) { return Number(n ?? 0).toLocaleString('vi-VN'); }

// Chỉ 2 loại chứng từ có trang chi tiết trong hệ thống hiện tại; quyền xem đã được
// kiểm tra lại ở server (row.linkable) trước khi trả về — FE chỉ render theo cờ đó.
function docHref(row) {
  if (row.source_type === 'StockEntry') return route('warehouse.stock-entries.show', row.document_id);
  if (row.source_type === 'StockExit') return route('warehouse.stock-exits.show', row.document_id);
  return '#';
}

onMounted(() => load(1));
</script>
