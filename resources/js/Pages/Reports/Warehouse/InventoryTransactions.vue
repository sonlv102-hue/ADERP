<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="erp-page-header">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sổ chi tiết Nhập-Xuất-Tồn</h1>
          <p class="text-sm text-gray-500 mt-0.5">Mỗi dòng là 1 giao dịch nhập/xuất — kèm tồn đầu kỳ và tồn cuối kỳ theo sản phẩm. Chỉ hiện sản phẩm có tồn đầu kỳ hoặc có phát sinh trong khoảng ngày đang lọc.</p>
        </div>
        <a :href="exportUrl" class="erp-btn-secondary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel
        </a>
      </div>

      <!-- Filters -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <input v-model="filters.date_from" @change="applyFilters" type="date" class="erp-input" />
        <input v-model="filters.date_to" @change="applyFilters" type="date" class="erp-input" />
        <select v-model="filters.warehouse_id" @change="applyFilters" class="erp-input">
          <option value="">Tất cả kho</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
        <FormField wrap-class="lg:col-span-1">
          <RemoteSearchSelect
            v-model="filters.product_id"
            :display-text="productLabel"
            :search-url="route('search.products')"
            placeholder="Tất cả sản phẩm"
            @change="opt => { productLabel = opt?.label ?? '' }"
          />
        </FormField>
        <select v-model="filters.category_id" @change="applyFilters" class="erp-input">
          <option value="">Tất cả danh mục</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="flex gap-3 items-center">
        <input v-model="filters.search" @keyup.enter="applyFilters" type="text" placeholder="Mã SP, tên SP..." class="erp-input w-full sm:w-64" />
        <button @click="applyFilters" class="erp-btn-primary">Lọc</button>
        <button v-if="filters.product_id" @click="clearProduct" class="text-gray-500 hover:text-gray-700 text-sm px-2">Bỏ chọn sản phẩm</button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr class="bg-gray-50">
              <th rowspan="2" class="erp-sticky-col-header truncate text-left px-4 py-2 font-semibold text-gray-600 border-r border-gray-100 align-middle whitespace-nowrap" :style="getStickyStyle('code')">Mã hàng hóa</th>
              <th rowspan="2" class="erp-sticky-col-header erp-sticky-col-boundary truncate text-left px-4 py-2 font-semibold text-gray-600 align-middle" :style="getStickyStyle('description')">Diễn giải</th>
              <th colspan="2" class="text-center px-3 py-2 font-semibold text-gray-600 border-r border-gray-100">Tồn đầu kỳ</th>
              <th colspan="4" class="text-center px-3 py-2 font-semibold text-blue-700 border-r border-gray-100 bg-blue-50">Nhập trong kỳ</th>
              <th colspan="4" class="text-center px-3 py-2 font-semibold text-orange-700 border-r border-gray-100 bg-orange-50">Xuất trong kỳ</th>
              <th colspan="2" class="text-center px-3 py-2 font-semibold text-green-700 bg-green-50" title="Tồn ngay sau dòng này — chỉ dòng &quot;Cộng phát sinh + Tồn cuối kỳ&quot; mới là số tồn cuối kỳ thực tế">Tồn sau CT</th>
            </tr>
            <tr class="border-b border-gray-200">
              <th class="text-right px-3 py-2 font-medium text-gray-500 text-xs whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-gray-500 text-xs border-r border-gray-100 whitespace-nowrap">Giá trị</th>
              <th class="text-left px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 whitespace-nowrap">Số CT</th>
              <th class="text-center px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 whitespace-nowrap">Ngày</th>
              <th class="text-right px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-blue-600 text-xs bg-blue-50 border-r border-gray-100 whitespace-nowrap">Giá trị</th>
              <th class="text-left px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 whitespace-nowrap">Số CT</th>
              <th class="text-center px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 whitespace-nowrap">Ngày</th>
              <th class="text-right px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-orange-600 text-xs bg-orange-50 border-r border-gray-100 whitespace-nowrap">Giá trị</th>
              <th class="text-right px-3 py-2 font-medium text-green-600 text-xs bg-green-50 whitespace-nowrap">SL</th>
              <th class="text-right px-3 py-2 font-medium text-green-600 text-xs bg-green-50 whitespace-nowrap">Giá trị</th>
            </tr>
          </thead>
          <template v-for="group in rows.data" :key="group.product.id">
            <tbody class="divide-y divide-gray-50">
              <tr
                v-for="(r, idx) in group.rows"
                :key="idx"
                :class="[
                  r.is_negative ? 'bg-red-50' : (r.row_type === 'movement' ? 'bg-white' : 'bg-slate-50'),
                  r.row_type === 'movement' ? 'hover:bg-gray-50' : 'font-semibold',
                ]"
              >
                <td class="erp-sticky-col truncate px-4 py-2 font-mono text-xs text-primary-700 border-r border-gray-100" :style="getStickyStyle('code')">
                  <div class="flex items-center gap-2 overflow-hidden">
                    <span class="truncate">{{ idx === 0 ? group.product.code : '' }}</span>
                    <span v-if="idx === 0 && group.status.code !== 'normal'" :title="statusTooltip(group.status.code)" class="shrink-0">
                      <StatusBadge :color="group.status.color">{{ group.status.label }}</StatusBadge>
                    </span>
                  </div>
                </td>
                <td class="erp-sticky-col erp-sticky-col-boundary truncate px-4 py-2 text-gray-800" :style="getStickyStyle('description')" :title="r.description">{{ r.description }}</td>
                <td class="px-3 py-2 text-right text-gray-700 whitespace-nowrap">{{ fmtQty(r.qty_begin) }}</td>
                <td class="px-3 py-2 text-right text-gray-600 text-xs border-r border-gray-100 whitespace-nowrap">{{ fmtVal(r.value_begin) }}</td>
                <td class="px-3 py-2 text-left text-blue-700 font-mono text-xs whitespace-nowrap" :title="r.backdated_note ?? ''">{{ r.in_doc_code ?? '—' }}</td>
                <td class="px-3 py-2 text-center text-gray-500 text-xs whitespace-nowrap">{{ fmtDate(r.in_doc_date) }}</td>
                <td class="px-3 py-2 text-right text-blue-700 whitespace-nowrap">{{ fmtQty(r.qty_in) }}</td>
                <td class="px-3 py-2 text-right text-blue-700 border-r border-gray-100 whitespace-nowrap">{{ fmtVal(r.value_in) }}</td>
                <td class="px-3 py-2 text-left text-orange-700 font-mono text-xs whitespace-nowrap" :title="r.backdated_note ?? ''">{{ r.out_doc_code ?? '—' }}</td>
                <td class="px-3 py-2 text-center text-gray-500 text-xs whitespace-nowrap">{{ fmtDate(r.out_doc_date) }}</td>
                <td class="px-3 py-2 text-right text-orange-700 whitespace-nowrap">{{ fmtQty(r.qty_out) }}</td>
                <td class="px-3 py-2 text-right text-orange-700 border-r border-gray-100 whitespace-nowrap">{{ fmtVal(r.value_out) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap" :class="r.is_negative ? 'text-red-600 font-semibold' : 'text-gray-800'">{{ fmtQty(r.qty_end) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap" :class="r.is_negative ? 'text-red-600 font-semibold' : 'text-green-700'">{{ fmt(r.value_end) }}</td>
              </tr>
            </tbody>
          </template>
          <tbody v-if="!rows.data?.length">
            <tr>
              <td colspan="14" class="px-4 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="rows.links" :meta="rows.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import FormField from '@/Components/Shared/FormField.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';
import { useStickyColumns } from '@/composables/useStickyColumns';

// Sticky columns: Mã hàng hóa + Diễn giải là 2 cột định danh thật duy nhất (rowspan=2).
// Không có cột Ngày/Số chứng từ độc lập — chúng nằm trong từng nhóm Nhập/Xuất riêng nên scroll cùng.
const { getStickyStyle } = useStickyColumns([
  { key: 'code', width: 180 },
  { key: 'description', width: 200 },
]);

const props = defineProps({
  rows:       Object,
  warehouses: Array,
  categories: Array,
  filters:    Object,
});

const { formatVnd: fmt } = useCurrency();

const filters = reactive({
  search:       props.filters?.search       ?? '',
  date_from:    props.filters?.date_from    ?? '',
  date_to:      props.filters?.date_to      ?? '',
  warehouse_id: props.filters?.warehouse_id ?? '',
  product_id:   props.filters?.product_id   ?? '',
  category_id:  props.filters?.category_id  ?? '',
});

const productLabel = ref(props.filters?.product_name ?? '');

function applyFilters() {
  router.get(route('reports.inventory_transactions'), { ...filters }, { preserveState: true, replace: true });
}

function clearProduct() {
  filters.product_id = '';
  productLabel.value = '';
  applyFilters();
}

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });
  const qs = params.toString();
  return route('reports.inventory_transactions.export') + (qs ? '?' + qs : '');
});

function fmtQty(n) {
  return n === null || n === undefined ? '' : Number(n).toLocaleString('vi-VN');
}

// fmt() (formatVnd) coerces null → "0 đ" — dùng bản null-safe riêng cho cột
// tiền có thể trống (không áp dụng bên nhập/xuất còn lại của dòng)
function fmtVal(n) {
  return n === null || n === undefined ? '' : fmt(n);
}

function fmtDate(d) {
  if (!d) return '';
  const [y, m, day] = d.split('-');
  return `${day}/${m}/${y}`;
}

// "Âm theo ngày chứng từ" tính theo thứ tự ngày chứng từ, có thể là cảnh báo giả
// nếu chứng từ bị nhập/xác nhận lùi ngày — xem plans/260729-avco-negative-stock-cost-investigation.
function statusTooltip(statusCode) {
  if (statusCode === 'was_negative') {
    return 'Số tồn được tính theo ngày chứng từ. Cảnh báo có thể xuất hiện khi chứng từ được nhập hoặc xác nhận lùi ngày, dù tồn kho tại thời điểm xử lý thực tế không bị âm.';
  }
  return '';
}
</script>
