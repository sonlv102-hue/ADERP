<template>
  <AppLayout>
    <div class="max-w-7xl">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thẻ kho</h1>
          <p class="text-sm text-gray-500 mt-1">Chi tiết nhập / xuất / tồn theo từng sản phẩm</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div>
            <label class="form-label">Sản phẩm <span class="text-red-500">*</span></label>
            <select v-model="f.product_id" @change="applyFilters" class="form-input">
              <option value="">-- Chọn sản phẩm --</option>
              <option v-for="p in products" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
            </select>
          </div>
          <div>
            <label class="form-label">Kho</label>
            <select v-model="f.warehouse_id" @change="applyFilters" class="form-input">
              <option value="">Tất cả kho</option>
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </div>
          <div>
            <label class="form-label">Từ ngày</label>
            <input v-model="f.date_from" type="date" @change="applyFilters" class="form-input" />
          </div>
          <div>
            <label class="form-label">Đến ngày</label>
            <input v-model="f.date_to" type="date" @change="applyFilters" class="form-input" />
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!f.product_id" class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center text-gray-400">
        Chọn sản phẩm để xem thẻ kho
      </div>

      <!-- Card header info -->
      <div v-else-if="product" class="bg-white rounded-xl shadow-sm border border-gray-100 mb-4 p-4">
        <div class="grid grid-cols-4 gap-4 text-sm">
          <div><span class="text-gray-500">Mã SP:</span> <strong>{{ product.code }}</strong></div>
          <div><span class="text-gray-500">Tên:</span> <strong>{{ product.name }}</strong></div>
          <div><span class="text-gray-500">ĐVT:</span> <strong>{{ product.unit ?? '—' }}</strong></div>
          <div><span class="text-gray-500">Đơn giá vốn:</span> <strong>{{ fv(product.cost_price) }}</strong></div>
        </div>
      </div>

      <!-- Table -->
      <div v-if="f.product_id" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-600">
            <tr>
              <th class="px-4 py-3 text-left w-24">Ngày</th>
              <th class="px-4 py-3 text-left">Diễn giải</th>
              <th class="px-4 py-3 text-left w-28">Kho</th>
              <th class="px-4 py-3 text-right w-20">Nhập</th>
              <th class="px-4 py-3 text-right w-20">Xuất</th>
              <th class="px-4 py-3 text-right w-24">Tồn</th>
              <th class="px-4 py-3 text-right w-28">Đơn giá BQ</th>
              <th class="px-4 py-3 text-right w-32">Giá trị tồn</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <!-- Tồn đầu kỳ -->
            <tr class="bg-blue-50 font-semibold text-blue-800">
              <td class="px-4 py-2.5" colspan="2">Tồn đầu kỳ</td>
              <td class="px-4 py-2.5 text-gray-500 text-xs">{{ filters.date_from }}</td>
              <td class="px-4 py-2.5 text-right" />
              <td class="px-4 py-2.5 text-right" />
              <td class="px-4 py-2.5 text-right font-mono">{{ fn(openingBalance) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(product?.cost_price ?? 0) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(openingValue) }}</td>
            </tr>

            <!-- Movements -->
            <tr v-if="!rows.length">
              <td colspan="8" class="px-4 py-6 text-center text-gray-400">Không có phát sinh trong kỳ</td>
            </tr>
            <tr v-for="(r, i) in rows" :key="i" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-xs text-gray-600">{{ r.date }}</td>
              <td class="px-4 py-2">{{ r.description }}</td>
              <td class="px-4 py-2 text-xs text-gray-500">{{ r.warehouse }}</td>
              <td class="px-4 py-2 text-right font-mono" :class="r.qty_in > 0 ? 'text-green-700 font-semibold' : 'text-gray-300'">
                {{ r.qty_in > 0 ? fn(r.qty_in) : '—' }}
              </td>
              <td class="px-4 py-2 text-right font-mono" :class="r.qty_out > 0 ? 'text-red-700 font-semibold' : 'text-gray-300'">
                {{ r.qty_out > 0 ? fn(r.qty_out) : '—' }}
              </td>
              <td class="px-4 py-2 text-right font-mono font-semibold">{{ fn(r.balance) }}</td>
              <td class="px-4 py-2 text-right font-mono text-gray-600">{{ fv(r.unit_cost) }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ fv(r.value_balance) }}</td>
            </tr>
          </tbody>

          <!-- Tồn cuối kỳ -->
          <tfoot v-if="rows.length" class="bg-green-50 border-t-2 border-green-200 font-bold text-green-800">
            <tr>
              <td class="px-4 py-2.5" colspan="2">Tồn cuối kỳ</td>
              <td class="px-4 py-2.5 text-gray-500 text-xs">{{ filters.date_to }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fn(totalIn) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fn(totalOut) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fn(endBalance) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(product?.cost_price ?? 0) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(endValue) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  rows:           Array,
  product:        Object,
  openingBalance: Number,
  openingValue:   Number,
  products:       Array,
  warehouses:     Array,
  filters:        Object,
});

const { formatVnd: fv } = useCurrency();
function fn(val) {
  return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 }).format(val || 0);
}

const f = ref({
  product_id:   props.filters.product_id   ?? '',
  warehouse_id: props.filters.warehouse_id ?? '',
  date_from:    props.filters.date_from    ?? '',
  date_to:      props.filters.date_to      ?? '',
});

function applyFilters() {
  router.get(route('reports.stock_card'), {
    product_id:   f.value.product_id   || undefined,
    warehouse_id: f.value.warehouse_id || undefined,
    date_from:    f.value.date_from    || undefined,
    date_to:      f.value.date_to      || undefined,
  }, { preserveState: true });
}

const totalIn    = computed(() => props.rows.reduce((s, r) => s + r.qty_in,  0));
const totalOut   = computed(() => props.rows.reduce((s, r) => s + r.qty_out, 0));
const endBalance = computed(() => (props.openingBalance ?? 0) + totalIn.value - totalOut.value);
const endValue   = computed(() => endBalance.value * (props.product?.cost_price ?? 0));
</script>
