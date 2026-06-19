<template>
  <AppLayout>
    <div class="space-y-5">

      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <p class="text-xs font-medium text-gray-400 mb-0.5">Kho / Tồn kho dự án</p>
          <h1 class="text-2xl font-bold text-gray-900">Tồn kho theo dự án</h1>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <select v-model="filters.project_id" @change="applyFilters"
          class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 w-56">
          <option :value="null">— Tất cả dự án —</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
        </select>

        <select v-model="filters.warehouse_id" @change="applyFilters"
          class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 w-48">
          <option :value="null">— Tất cả kho —</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>

        <select v-model="filters.status" @change="applyFilters"
          class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">— Tất cả trạng thái —</option>
          <option value="active">Còn hàng</option>
          <option value="depleted">Đã xuất hết</option>
          <option value="cancelled">Đã hủy</option>
        </select>

        <button v-if="hasFilters" @click="clearFilters"
          class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2">Xóa lọc</button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50/60 border-b border-gray-100">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Dự án</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Kho</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Sản phẩm</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn hàng / Phiếu nhập</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Ngày nhập</th>
              <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">SL nhập</th>
              <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">SL xuất</th>
              <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Còn lại</th>
              <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn giá</th>
              <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Giá trị còn</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">TK kho</th>
              <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-if="!lots.data.length">
              <td colspan="12" class="px-4 py-12 text-center text-sm text-gray-400">
                Không có dữ liệu tồn kho theo dự án.
              </td>
            </tr>
            <tr v-for="lot in lots.data" :key="lot.id" class="hover:bg-blue-50/20 transition-colors">
              <td class="px-4 py-2.5">
                <span class="font-mono text-xs text-primary-700">{{ lot.project_code }}</span>
                <span class="ml-1 text-xs text-gray-500">{{ lot.project_name }}</span>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-600">{{ lot.warehouse_name }}</td>
              <td class="px-4 py-2.5">
                <span class="font-mono text-xs text-gray-700">{{ lot.product_code }}</span>
                <span class="ml-1 text-xs text-gray-500">{{ lot.product_name }}</span>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-500">
                <div v-if="lot.purchase_order_code" class="font-mono">{{ lot.purchase_order_code }}</div>
                <div class="font-mono">{{ lot.stock_entry_code }}</div>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-500">{{ lot.received_at }}</td>
              <td class="px-4 py-2.5 text-right text-sm font-medium text-gray-700">{{ lot.received_qty }}</td>
              <td class="px-4 py-2.5 text-right text-sm text-gray-500">{{ lot.issued_qty }}</td>
              <td class="px-4 py-2.5 text-right text-sm font-semibold"
                :class="lot.available_qty > 0 ? 'text-emerald-700' : 'text-gray-400'">
                {{ lot.available_qty }}
              </td>
              <td class="px-4 py-2.5 text-right text-xs text-gray-600">{{ formatVnd(lot.unit_cost) }}</td>
              <td class="px-4 py-2.5 text-right text-xs font-semibold text-gray-700">{{ formatVnd(lot.total_cost) }}</td>
              <td class="px-4 py-2.5 text-xs font-mono text-gray-500">{{ lot.inventory_account ?? '—' }}</td>
              <td class="px-4 py-2.5">
                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="{
                    'bg-emerald-100 text-emerald-700': lot.status === 'active',
                    'bg-gray-100 text-gray-500':      lot.status === 'depleted',
                    'bg-red-100 text-red-600':         lot.status === 'cancelled',
                  }">
                  {{ statusLabel(lot.status) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="lots.links" />
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  lots:       Object,
  projects:   Array,
  warehouses: Array,
  filters:    Object,
});

const { formatVnd } = useCurrency();

const filters = reactive({
  project_id:   props.filters.project_id   ?? null,
  warehouse_id: props.filters.warehouse_id ?? null,
  status:       props.filters.status       ?? '',
});

const hasFilters = computed(() => filters.project_id || filters.warehouse_id || filters.status);

function applyFilters() {
  router.get(route('warehouse.project-inventory.index'), {
    project_id:   filters.project_id   || undefined,
    warehouse_id: filters.warehouse_id || undefined,
    status:       filters.status       || undefined,
  }, { preserveState: true });
}

function clearFilters() {
  filters.project_id   = null;
  filters.warehouse_id = null;
  filters.status       = '';
  applyFilters();
}

function statusLabel(status) {
  return { active: 'Còn hàng', depleted: 'Đã hết', cancelled: 'Đã hủy' }[status] ?? status;
}
</script>
