<template>
  <AppLayout>
    <div class="max-w-6xl">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Tồn kho đầu kỳ</h1>
          <p class="text-sm text-gray-500 mt-1">Nhập số dư tồn kho đầu kỳ làm cơ sở cho báo cáo</p>
        </div>
        <Link :href="route('warehouse.opening-balance.create')"
          class="btn-primary">+ Nhập đầu kỳ mới</Link>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4 flex gap-3">
        <div>
          <label class="form-label">Kỳ (YYYY-MM)</label>
          <input v-model="period" type="month" @change="applyFilters" class="form-input" />
        </div>
        <div>
          <label class="form-label">Kho</label>
          <select v-model="warehouseId" @change="applyFilters" class="form-input">
            <option value="">Tất cả kho</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Kho</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Mã SP</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">SL ĐK</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">Đơn giá vốn</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">Thành tiền</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">BT kế toán</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!balances.length">
              <td colspan="8" class="px-4 py-8 text-center text-gray-400">Chưa có dữ liệu đầu kỳ cho kỳ này</td>
            </tr>
            <tr v-for="b in balances" :key="b.id" class="hover:bg-gray-50">
              <td class="px-4 py-2.5 text-gray-600">{{ b.warehouse_name }}</td>
              <td class="px-4 py-2.5 font-mono text-xs">{{ b.product_code }}</td>
              <td class="px-4 py-2.5">{{ b.product_name }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ b.quantity }} {{ b.unit }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(b.unit_cost) }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fv(b.total_cost) }}</td>
              <td class="px-4 py-2.5 text-center">
                <span v-if="b.has_je" class="text-green-600 text-xs font-semibold">✓ Đã HT</span>
                <span v-else class="text-gray-400 text-xs">—</span>
              </td>
              <td class="px-4 py-2.5 text-center">
                <button v-if="!b.has_je" @click="deleteRow(b.id)"
                  class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="balances.length" class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="5" class="px-4 py-2 text-right font-semibold text-gray-600">Tổng cộng:</td>
              <td class="px-4 py-2 text-right font-mono font-bold text-primary-700">
                {{ fv(balances.reduce((s, b) => s + b.total_cost, 0)) }}
              </td>
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ balances: Array, warehouses: Array, filters: Object });
const { formatVnd: fv } = useCurrency();

const period      = ref(props.filters.period ?? '');
const warehouseId = ref(props.filters.warehouse_id ?? '');

function applyFilters() {
  router.get(route('warehouse.opening-balance.index'), {
    period: period.value,
    warehouse_id: warehouseId.value || undefined,
  }, { preserveState: true });
}

function deleteRow(id) {
  if (!confirm('Xóa dòng tồn kho đầu kỳ này?')) return;
  router.delete(route('warehouse.opening-balance.destroy', id));
}
</script>
